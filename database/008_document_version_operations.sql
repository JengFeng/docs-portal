/*
  Durable document-version restore operations and immutable event history.
  The web application records intent/state; the credentialless bridge reports
  filesystem stages through protected JSON result files.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
   OR OBJECT_ID(N'dbo.documents', N'U') IS NULL
   OR OBJECT_ID(N'dbo.document_versions', N'U') IS NULL
   OR OBJECT_ID(N'dbo.auth_users', N'U') IS NULL
BEGIN
    ;THROW 51008, 'Run database migrations 001 through 007 before 008_document_version_operations.sql.', 1;
END;
GO

IF OBJECT_ID(N'dbo.document_version_operations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_version_operations
    (
        operation_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_document_version_operations PRIMARY KEY,
        document_id BIGINT NOT NULL,
        operation_type VARCHAR(16) NOT NULL CONSTRAINT DF_document_version_operations_type DEFAULT ('restore'),
        status_code VARCHAR(24) NOT NULL CONSTRAINT DF_document_version_operations_status DEFAULT ('queued'),
        source_status_code VARCHAR(16) NOT NULL,
        expected_current_hash CHAR(64) NOT NULL,
        target_content_hash CHAR(64) NOT NULL,
        previous_content_hash CHAR(64) NULL,
        actual_content_hash CHAR(64) NULL,
        drive_sync_status VARCHAR(32) NOT NULL CONSTRAINT DF_document_version_operations_drive DEFAULT ('pending-confirmation'),
        requested_by_user_id BIGINT NOT NULL,
        request_client_ip VARCHAR(45) NULL,
        request_user_agent NVARCHAR(512) NULL,
        requested_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_version_operations_requested DEFAULT (SYSUTCDATETIME()),
        started_at DATETIME2(3) NULL,
        local_restored_at DATETIME2(3) NULL,
        indexed_at DATETIME2(3) NULL,
        preview_ready_at DATETIME2(3) NULL,
        completed_at DATETIME2(3) NULL,
        error_code VARCHAR(64) NULL,
        error_message NVARCHAR(1000) NULL,
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_version_operations_updated DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT FK_document_version_operations_document FOREIGN KEY (document_id) REFERENCES dbo.documents (document_id),
        CONSTRAINT FK_document_version_operations_user FOREIGN KEY (requested_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_document_version_operations_type CHECK (operation_type IN ('restore')),
        CONSTRAINT CK_document_version_operations_status CHECK
        (
            status_code IN ('queued','running','local-restored','indexed','preview-ready','completed','conflict','failed','cancelled')
        ),
        CONSTRAINT CK_document_version_operations_source_status CHECK (source_status_code IN ('draft','published','archived')),
        CONSTRAINT CK_document_version_operations_drive CHECK
        (
            drive_sync_status IN ('pending-confirmation','confirmed','attention-required')
        ),
        CONSTRAINT CK_document_version_operations_expected_hash CHECK
        (
            expected_current_hash NOT LIKE '%[^0-9A-Fa-f]%'
        ),
        CONSTRAINT CK_document_version_operations_target_hash CHECK
        (
            target_content_hash NOT LIKE '%[^0-9A-Fa-f]%'
        ),
        CONSTRAINT CK_document_version_operations_previous_hash CHECK
        (
            previous_content_hash IS NULL OR previous_content_hash NOT LIKE '%[^0-9A-Fa-f]%'
        ),
        CONSTRAINT CK_document_version_operations_actual_hash CHECK
        (
            actual_content_hash IS NULL OR actual_content_hash NOT LIKE '%[^0-9A-Fa-f]%'
        ),
        CONSTRAINT CK_document_version_operations_different_hash CHECK (expected_current_hash <> target_content_hash)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.document_version_operations')
      AND name = N'UX_document_version_operations_active'
)
BEGIN
    CREATE UNIQUE INDEX UX_document_version_operations_active
        ON dbo.document_version_operations(document_id)
        WHERE status_code IN ('queued','running','local-restored','indexed');
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.document_version_operations')
      AND name = N'IX_document_version_operations_document_requested'
)
BEGIN
    CREATE INDEX IX_document_version_operations_document_requested
        ON dbo.document_version_operations(document_id, requested_at DESC)
        INCLUDE(status_code, target_content_hash, requested_by_user_id, error_code);
END;
GO

IF OBJECT_ID(N'dbo.document_version_operation_events', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_version_operation_events
    (
        event_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_document_version_operation_events PRIMARY KEY,
        operation_id UNIQUEIDENTIFIER NOT NULL,
        status_code VARCHAR(24) NOT NULL,
        event_code VARCHAR(64) NOT NULL,
        event_source VARCHAR(16) NOT NULL,
        detail_message NVARCHAR(1000) NULL,
        actor_user_id BIGINT NULL,
        occurred_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_version_operation_events_occurred DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT FK_document_version_operation_events_operation FOREIGN KEY (operation_id) REFERENCES dbo.document_version_operations(operation_id),
        CONSTRAINT FK_document_version_operation_events_actor FOREIGN KEY (actor_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_document_version_operation_events_status CHECK
        (
            status_code IN ('queued','running','local-restored','indexed','preview-ready','completed','conflict','failed','cancelled')
        ),
        CONSTRAINT CK_document_version_operation_events_source CHECK (event_source IN ('portal','bridge','sync','preview'))
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.document_version_operation_events')
      AND name = N'IX_document_version_operation_events_operation'
)
BEGIN
    CREATE INDEX IX_document_version_operation_events_operation
        ON dbo.document_version_operation_events(operation_id, occurred_at, event_id);
END;
GO

IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.document_version_operations TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.document_version_operations TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT ON dbo.document_version_operation_events TO [TWWATER_PORTAL_APP];
    DENY UPDATE, DELETE ON dbo.document_version_operation_events TO [TWWATER_PORTAL_APP];
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM dbo.schema_migrations
    WHERE script_name = N'008_document_version_operations.sql'
)
    INSERT INTO dbo.schema_migrations(script_name, checksum_sha256)
    VALUES(N'008_document_version_operations.sql', NULL);
GO

COMMIT TRANSACTION;
GO
