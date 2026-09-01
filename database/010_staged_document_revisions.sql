/* Private pre-upload document workspaces and confirmation state. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.auth_users', N'U') IS NULL OR OBJECT_ID(N'dbo.documents', N'U') IS NULL
    THROW 51010, 'Run earlier TWWATER migrations first.', 1;

IF OBJECT_ID(N'dbo.staged_document_revisions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.staged_document_revisions
    (
        staged_revision_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_staged_document_revisions PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_staged_document_revisions_public_id DEFAULT (NEWID()),
        target_document_id BIGINT NULL,
        change_request_id BIGINT NULL,
        created_by_user_id BIGINT NOT NULL,
        confirmed_by_user_id BIGINT NULL,
        file_name NVARCHAR(260) NOT NULL,
        extension VARCHAR(10) NOT NULL,
        target_relative_path NVARCHAR(512) NOT NULL,
        expected_source_hash CHAR(64) NULL,
        declared_size_bytes BIGINT NOT NULL,
        chunk_size_bytes INT NOT NULL,
        total_chunks INT NOT NULL,
        staged_content_hash CHAR(64) NULL,
        status_code VARCHAR(24) NOT NULL CONSTRAINT DF_staged_document_revisions_status DEFAULT ('uploading'),
        preview_kind VARCHAR(16) NULL,
        preview_relative_path NVARCHAR(512) NULL,
        preview_page_count INT NULL,
        error_code VARCHAR(64) NULL,
        review_note NVARCHAR(1000) NULL,
        drive_sync_status VARCHAR(24) NOT NULL CONSTRAINT DF_staged_document_revisions_drive DEFAULT ('not_requested'),
        drive_file_id NVARCHAR(128) NULL,
        drive_verified_at DATETIME2(3) NULL,
        confirmed_at DATETIME2(3) NULL,
        expires_at DATETIME2(3) NOT NULL CONSTRAINT DF_staged_document_revisions_expires DEFAULT (DATEADD(day, 7, SYSUTCDATETIME())),
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_staged_document_revisions_created DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_staged_document_revisions_updated DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_staged_document_revisions_public_id UNIQUE (public_id),
        CONSTRAINT FK_staged_document_revisions_document FOREIGN KEY (target_document_id) REFERENCES dbo.documents (document_id),
        CONSTRAINT FK_staged_document_revisions_change_request FOREIGN KEY (change_request_id) REFERENCES dbo.presentation_change_requests (change_request_id),
        CONSTRAINT FK_staged_document_revisions_creator FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_staged_document_revisions_confirmer FOREIGN KEY (confirmed_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_staged_document_revisions_extension CHECK (extension IN ('pptx','pdf','docx','xlsx','md','txt')),
        CONSTRAINT CK_staged_document_revisions_size CHECK (declared_size_bytes BETWEEN 1 AND 157286400),
        CONSTRAINT CK_staged_document_revisions_chunks CHECK (chunk_size_bytes BETWEEN 65536 AND 2097152 AND total_chunks BETWEEN 1 AND 2400),
        CONSTRAINT CK_staged_document_revisions_status CHECK (status_code IN ('uploading','uploaded','previewing','preview_ready','changes_requested','confirming','confirmed','conflict','failed','cancelled','expired')),
        CONSTRAINT CK_staged_document_revisions_drive CHECK (drive_sync_status IN ('not_requested','pending','verified','mismatch','failed')),
        CONSTRAINT CK_staged_document_revisions_expected_hash CHECK (expected_source_hash IS NULL OR expected_source_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_staged_document_revisions_content_hash CHECK (staged_content_hash IS NULL OR staged_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_staged_document_revisions_target_path CHECK (target_relative_path NOT LIKE '/%' AND target_relative_path NOT LIKE '%..%' AND target_relative_path NOT LIKE '%\%')
    );
    CREATE INDEX IX_staged_document_revisions_creator_status ON dbo.staged_document_revisions (created_by_user_id, status_code, updated_at DESC);
    CREATE INDEX IX_staged_document_revisions_change_request ON dbo.staged_document_revisions (change_request_id, updated_at DESC) WHERE change_request_id IS NOT NULL;
END;

IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.staged_document_revisions TO TWWATER_PORTAL_APP;
END;

COMMIT TRANSACTION;
GO
