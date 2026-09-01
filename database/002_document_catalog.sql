/*
  供水監測文件協作平台：文件目錄與 SSDLC 對應資料表

  先執行 001_schema.sql，再以具 DDL 權限的 migration 帳號執行本檔。
  實體文件保留於 PORTAL_DOCUMENT_ROOT；本資料庫只保存中繼資料與稽核紀錄。
*/

SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.schema_migrations
    (
        migration_id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_schema_migrations PRIMARY KEY,
        script_name NVARCHAR(255) NOT NULL CONSTRAINT UQ_schema_migrations_script_name UNIQUE,
        applied_at DATETIME2(3) NOT NULL CONSTRAINT DF_schema_migrations_applied_at DEFAULT (SYSUTCDATETIME()),
        applied_by NVARCHAR(256) NOT NULL CONSTRAINT DF_schema_migrations_applied_by DEFAULT (SUSER_SNAME()),
        checksum_sha256 CHAR(64) NULL
    );
END;
GO

IF OBJECT_ID(N'dbo.document_types', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_types
    (
        document_type_id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_document_types PRIMARY KEY,
        type_code VARCHAR(64) NOT NULL CONSTRAINT UQ_document_types_type_code UNIQUE,
        display_name NVARCHAR(100) NOT NULL,
        description NVARCHAR(500) NULL,
        sort_order SMALLINT NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_document_types_is_active DEFAULT (1),
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_types_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_types_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT CK_document_types_code CHECK (type_code NOT LIKE '%[^a-z0-9_-]%')
    );
END;
GO

IF OBJECT_ID(N'dbo.ssdlc_phases', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ssdlc_phases
    (
        phase_id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ssdlc_phases PRIMARY KEY,
        phase_code CHAR(2) NOT NULL CONSTRAINT UQ_ssdlc_phases_phase_code UNIQUE,
        display_name NVARCHAR(100) NOT NULL,
        description NVARCHAR(500) NULL,
        sort_order SMALLINT NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_ssdlc_phases_is_active DEFAULT (1),
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_ssdlc_phases_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_ssdlc_phases_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT CK_ssdlc_phases_code CHECK (phase_code LIKE '[0-9][0-9]')
    );
END;
GO

IF OBJECT_ID(N'dbo.documents', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.documents
    (
        document_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_documents PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_documents_public_id DEFAULT (NEWID()),
        relative_path NVARCHAR(512) NOT NULL,
        file_name NVARCHAR(255) NOT NULL,
        title NVARCHAR(255) NOT NULL,
        extension VARCHAR(16) NOT NULL,
        file_size_bytes BIGINT NOT NULL CONSTRAINT DF_documents_file_size_bytes DEFAULT (0),
        source_modified_at DATETIME2(3) NOT NULL,
        content_hash CHAR(64) NULL,
        document_type_id INT NULL,
        summary NVARCHAR(1000) NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_documents_status_code DEFAULT ('draft'),
        created_by_user_id BIGINT NULL,
        updated_by_user_id BIGINT NULL,
        published_at DATETIME2(3) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_documents_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_documents_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_documents_public_id UNIQUE (public_id),
        CONSTRAINT UQ_documents_relative_path UNIQUE (relative_path),
        CONSTRAINT FK_documents_document_type FOREIGN KEY (document_type_id) REFERENCES dbo.document_types (document_type_id),
        CONSTRAINT FK_documents_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_documents_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_documents_status_code CHECK (status_code IN ('draft', 'published', 'archived')),
        CONSTRAINT CK_documents_extension CHECK (extension IN ('md', 'txt', 'pdf', 'docx', 'xlsx', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'gif')),
        CONSTRAINT CK_documents_relative_path CHECK (relative_path NOT LIKE '/%' AND relative_path NOT LIKE '%..%' AND relative_path NOT LIKE '%\%')
    );
END;
GO

IF OBJECT_ID(N'dbo.document_phase_roles', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_phase_roles
    (
        document_id BIGINT NOT NULL,
        phase_id INT NOT NULL,
        role_code VARCHAR(10) NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_phase_roles_created_at DEFAULT (SYSUTCDATETIME()),
        created_by_user_id BIGINT NULL,
        CONSTRAINT PK_document_phase_roles PRIMARY KEY (document_id, phase_id, role_code),
        CONSTRAINT FK_document_phase_roles_document FOREIGN KEY (document_id) REFERENCES dbo.documents (document_id) ON DELETE CASCADE,
        CONSTRAINT FK_document_phase_roles_phase FOREIGN KEY (phase_id) REFERENCES dbo.ssdlc_phases (phase_id),
        CONSTRAINT FK_document_phase_roles_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_document_phase_roles_code CHECK (role_code IN ('input', 'output'))
    );
END;
GO

IF OBJECT_ID(N'dbo.document_sync_runs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_sync_runs
    (
        sync_run_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_document_sync_runs PRIMARY KEY,
        initiated_by_user_id BIGINT NULL,
        source_name NVARCHAR(128) NOT NULL CONSTRAINT DF_document_sync_runs_source_name DEFAULT ('portal_document_root'),
        started_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_sync_runs_started_at DEFAULT (SYSUTCDATETIME()),
        completed_at DATETIME2(3) NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_document_sync_runs_status_code DEFAULT ('running'),
        discovered_count INT NOT NULL CONSTRAINT DF_document_sync_runs_discovered_count DEFAULT (0),
        inserted_count INT NOT NULL CONSTRAINT DF_document_sync_runs_inserted_count DEFAULT (0),
        updated_count INT NOT NULL CONSTRAINT DF_document_sync_runs_updated_count DEFAULT (0),
        skipped_count INT NOT NULL CONSTRAINT DF_document_sync_runs_skipped_count DEFAULT (0),
        error_message NVARCHAR(1000) NULL,
        CONSTRAINT FK_document_sync_runs_initiated_by FOREIGN KEY (initiated_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_document_sync_runs_status_code CHECK (status_code IN ('running', 'completed', 'failed'))
    );
END;
GO

IF OBJECT_ID(N'dbo.document_audit_events', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_audit_events
    (
        event_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_document_audit_events PRIMARY KEY,
        occurred_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_audit_events_occurred_at DEFAULT (SYSUTCDATETIME()),
        event_type VARCHAR(64) NOT NULL,
        outcome VARCHAR(32) NOT NULL,
        actor_user_id BIGINT NULL,
        document_id BIGINT NULL,
        detail NVARCHAR(1000) NULL,
        client_ip VARCHAR(45) NULL,
        user_agent NVARCHAR(512) NULL,
        CONSTRAINT FK_document_audit_events_actor FOREIGN KEY (actor_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_document_audit_events_document FOREIGN KEY (document_id) REFERENCES dbo.documents (document_id)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_documents_status_modified' AND object_id = OBJECT_ID(N'dbo.documents'))
    CREATE INDEX IX_documents_status_modified ON dbo.documents (status_code, source_modified_at DESC) INCLUDE (public_id, title, file_name, extension, document_type_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_documents_type_status' AND object_id = OBJECT_ID(N'dbo.documents'))
    CREATE INDEX IX_documents_type_status ON dbo.documents (document_type_id, status_code, source_modified_at DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_document_phase_roles_phase_role' AND object_id = OBJECT_ID(N'dbo.document_phase_roles'))
    CREATE INDEX IX_document_phase_roles_phase_role ON dbo.document_phase_roles (phase_id, role_code, document_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_document_audit_events_document_occurred' AND object_id = OBJECT_ID(N'dbo.document_audit_events'))
    CREATE INDEX IX_document_audit_events_document_occurred ON dbo.document_audit_events (document_id, occurred_at DESC);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'002_document_catalog.sql')
    INSERT INTO dbo.schema_migrations (script_name, checksum_sha256) VALUES (N'002_document_catalog.sql', NULL);
GO

COMMIT TRANSACTION;
GO
