SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.documents',N'U') IS NULL OR OBJECT_ID(N'dbo.auth_users',N'U') IS NULL
    OR OBJECT_ID(N'dbo.staged_document_revisions',N'U') IS NULL
    OR OBJECT_ID(N'dbo.document_version_operations',N'U') IS NULL
    OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
BEGIN
    THROW 51021, 'Run migrations 001 through 020 before 021_image_revision_workflow.sql.', 1;
END;
GO

IF OBJECT_ID(N'dbo.image_revision_jobs',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.image_revision_jobs
    (
        image_revision_job_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_image_revision_jobs PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_image_revision_jobs_public_id DEFAULT (NEWID()),
        document_id BIGINT NOT NULL,
        source_content_hash CHAR(64) NOT NULL,
        request_revision INT NOT NULL CONSTRAINT DF_image_revision_jobs_revision DEFAULT (1),
        annotations_json NVARCHAR(MAX) NOT NULL,
        request_sha256 CHAR(64) NOT NULL,
        candidate_sha256 CHAR(64) NULL,
        candidate_extension VARCHAR(10) NULL,
        candidate_size_bytes BIGINT NULL,
        candidate_width INT NULL,
        candidate_height INT NULL,
        staged_revision_id BIGINT NULL,
        version_operation_id UNIQUEIDENTIFIER NULL,
        status_code VARCHAR(24) NOT NULL CONSTRAINT DF_image_revision_jobs_status DEFAULT ('queued'),
        error_code VARCHAR(64) NULL,
        created_by_user_id BIGINT NOT NULL,
        confirmed_by_user_id BIGINT NULL,
        queued_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_revision_jobs_queued DEFAULT (SYSUTCDATETIME()),
        processing_at DATETIME2(3) NULL,
        candidate_ready_at DATETIME2(3) NULL,
        confirmed_at DATETIME2(3) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_revision_jobs_created DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_revision_jobs_updated DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_image_revision_jobs_public_id UNIQUE (public_id),
        CONSTRAINT FK_image_revision_jobs_document FOREIGN KEY (document_id) REFERENCES dbo.documents(document_id),
        CONSTRAINT FK_image_revision_jobs_staged FOREIGN KEY (staged_revision_id) REFERENCES dbo.staged_document_revisions(staged_revision_id),
        CONSTRAINT FK_image_revision_jobs_version_operation FOREIGN KEY (version_operation_id) REFERENCES dbo.document_version_operations(operation_id),
        CONSTRAINT FK_image_revision_jobs_creator FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT FK_image_revision_jobs_confirmer FOREIGN KEY (confirmed_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_image_revision_jobs_source_hash CHECK (source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_image_revision_jobs_request_hash CHECK (request_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_image_revision_jobs_candidate_hash CHECK (candidate_sha256 IS NULL OR candidate_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_image_revision_jobs_revision CHECK (request_revision BETWEEN 1 AND 1000000),
        CONSTRAINT CK_image_revision_jobs_annotations CHECK (ISJSON(annotations_json)=1 AND DATALENGTH(annotations_json) BETWEEN 2 AND 524288),
        CONSTRAINT CK_image_revision_jobs_candidate_extension CHECK (candidate_extension IS NULL OR candidate_extension IN ('png','jpg','jpeg','webp')),
        CONSTRAINT CK_image_revision_jobs_candidate_size CHECK (candidate_size_bytes IS NULL OR candidate_size_bytes BETWEEN 1 AND 52428800),
        CONSTRAINT CK_image_revision_jobs_candidate_dimensions CHECK ((candidate_width IS NULL AND candidate_height IS NULL) OR (candidate_width BETWEEN 1 AND 10000 AND candidate_height BETWEEN 1 AND 10000)),
        CONSTRAINT CK_image_revision_jobs_status CHECK (status_code IN ('queued','processing','candidate_ready','confirming','confirmed','conflict','failed','cancelled'))
    );
    CREATE INDEX IX_image_revision_jobs_document_created ON dbo.image_revision_jobs(document_id,created_at DESC);
    CREATE INDEX IX_image_revision_jobs_status_updated ON dbo.image_revision_jobs(status_code,updated_at);
    CREATE UNIQUE INDEX UX_image_revision_jobs_active_document ON dbo.image_revision_jobs(document_id)
        WHERE status_code IN ('queued','processing','candidate_ready','confirming');
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_revision_jobs') AND name=N'IX_image_revision_jobs_document_created')
    CREATE INDEX IX_image_revision_jobs_document_created ON dbo.image_revision_jobs(document_id,created_at DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_revision_jobs') AND name=N'IX_image_revision_jobs_status_updated')
    CREATE INDEX IX_image_revision_jobs_status_updated ON dbo.image_revision_jobs(status_code,updated_at);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_revision_jobs') AND name=N'UX_image_revision_jobs_active_document')
    CREATE UNIQUE INDEX UX_image_revision_jobs_active_document ON dbo.image_revision_jobs(document_id)
        WHERE status_code IN ('queued','processing','candidate_ready','confirming');
GO

IF COL_LENGTH(N'dbo.image_revision_jobs',N'version_operation_id') IS NULL
    ALTER TABLE dbo.image_revision_jobs ADD version_operation_id UNIQUEIDENTIFIER NULL;
GO

IF OBJECT_ID(N'dbo.FK_image_revision_jobs_version_operation',N'F') IS NULL
    ALTER TABLE dbo.image_revision_jobs ADD CONSTRAINT FK_image_revision_jobs_version_operation
        FOREIGN KEY (version_operation_id) REFERENCES dbo.document_version_operations(operation_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.image_revision_jobs') AND name=N'UX_image_revision_jobs_version_operation')
    CREATE UNIQUE INDEX UX_image_revision_jobs_version_operation ON dbo.image_revision_jobs(version_operation_id) WHERE version_operation_id IS NOT NULL;
GO

IF OBJECT_ID(N'dbo.CK_document_version_operations_type',N'C') IS NOT NULL
    ALTER TABLE dbo.document_version_operations DROP CONSTRAINT CK_document_version_operations_type;
ALTER TABLE dbo.document_version_operations ADD CONSTRAINT CK_document_version_operations_type
    CHECK (operation_type IN ('restore','publish'));
GO

IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT,INSERT,UPDATE ON dbo.image_revision_jobs TO TWWATER_PORTAL_APP;
    DENY DELETE ON dbo.image_revision_jobs TO TWWATER_PORTAL_APP;
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'021_image_revision_workflow.sql')
    INSERT INTO dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'021_image_revision_workflow.sql',NULL);
GO

COMMIT TRANSACTION;
GO
