/* Site-wide visual feedback batches, live-page annotations, and Hermes dispatch projection.
   Run after 015_image_annotation_tools.sql with the controlled migration identity. */
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
GO
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.auth_users', N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
BEGIN
    THROW 51016, 'Run migrations 001 through 015 before 016_site_feedback.sql.', 1;
END;
GO

IF OBJECT_ID(N'dbo.site_feedback_batches', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.site_feedback_batches
    (
        batch_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_site_feedback_batches PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_site_feedback_batches_public_id DEFAULT (NEWID()),
        title NVARCHAR(200) NOT NULL,
        status_code VARCHAR(32) NOT NULL CONSTRAINT DF_site_feedback_batches_status DEFAULT ('draft'),
        revision_number INT NOT NULL CONSTRAINT DF_site_feedback_batches_revision DEFAULT (1),
        analysis_summary NVARCHAR(4000) NULL,
        execution_summary NVARCHAR(4000) NULL,
        created_by_user_id BIGINT NOT NULL,
        approved_by_user_id BIGINT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_batches_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_batches_updated_at DEFAULT (SYSUTCDATETIME()),
        submitted_at DATETIME2(3) NULL,
        analyzed_at DATETIME2(3) NULL,
        approved_at DATETIME2(3) NULL,
        completed_at DATETIME2(3) NULL,
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_site_feedback_batches_public_id UNIQUE (public_id),
        CONSTRAINT FK_site_feedback_batches_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT FK_site_feedback_batches_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_site_feedback_batches_title CHECK (LEN(title) BETWEEN 1 AND 200),
        CONSTRAINT CK_site_feedback_batches_revision CHECK (revision_number BETWEEN 1 AND 1000000),
        CONSTRAINT CK_site_feedback_batches_status CHECK (status_code IN
            ('draft','queued_analysis','analyzing','awaiting_approval','approved','queued_execution','executing','completed','analysis_failed','rejected','execution_failed'))
    );
END;
GO

IF OBJECT_ID(N'dbo.site_feedback_items', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.site_feedback_items
    (
        item_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_site_feedback_items PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_site_feedback_items_public_id DEFAULT (NEWID()),
        batch_id BIGINT NOT NULL,
        parent_item_id BIGINT NULL,
        sequence_number INT NOT NULL,
        target_url NVARCHAR(1000) NOT NULL,
        target_action VARCHAR(64) NOT NULL,
        page_fingerprint CHAR(64) NULL,
        viewport_width INT NOT NULL,
        viewport_height INT NOT NULL,
        scroll_x INT NOT NULL CONSTRAINT DF_site_feedback_items_scroll_x DEFAULT (0),
        scroll_y INT NOT NULL CONSTRAINT DF_site_feedback_items_scroll_y DEFAULT (0),
        device_pixel_ratio DECIMAL(5,2) NOT NULL CONSTRAINT DF_site_feedback_items_dpr DEFAULT (1),
        annotation_type_code VARCHAR(20) NOT NULL,
        geometry_json NVARCHAR(4000) NOT NULL,
        element_selector NVARCHAR(1000) NULL,
        element_tag VARCHAR(32) NULL,
        element_text NVARCHAR(1000) NULL,
        element_fingerprint CHAR(64) NULL,
        instruction_text NVARCHAR(2000) NOT NULL,
        is_deleted BIT NOT NULL CONSTRAINT DF_site_feedback_items_deleted DEFAULT (0),
        deleted_at DATETIME2(3) NULL,
        structured_title NVARCHAR(300) NULL,
        structured_requirement NVARCHAR(4000) NULL,
        acceptance_criteria_json NVARCHAR(4000) NULL,
        risk_level VARCHAR(16) NULL,
        created_by_user_id BIGINT NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_items_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_items_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_site_feedback_items_public_id UNIQUE (public_id),
        CONSTRAINT FK_site_feedback_items_batch FOREIGN KEY (batch_id) REFERENCES dbo.site_feedback_batches(batch_id),
        CONSTRAINT FK_site_feedback_items_parent FOREIGN KEY (parent_item_id) REFERENCES dbo.site_feedback_items(item_id),
        CONSTRAINT FK_site_feedback_items_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_site_feedback_items_sequence CHECK (sequence_number BETWEEN 1 AND 1000),
        CONSTRAINT CK_site_feedback_items_viewport CHECK (viewport_width BETWEEN 240 AND 8192 AND viewport_height BETWEEN 240 AND 8192 AND scroll_x >= 0 AND scroll_y >= 0 AND device_pixel_ratio BETWEEN 0.25 AND 8),
        CONSTRAINT CK_site_feedback_items_type CHECK (annotation_type_code IN ('rectangle','arrow','highlight','text','number')),
        CONSTRAINT CK_site_feedback_items_geometry CHECK (ISJSON(geometry_json) = 1 AND LEN(geometry_json) BETWEEN 2 AND 4000),
        CONSTRAINT CK_site_feedback_items_instruction CHECK (LEN(instruction_text) BETWEEN 1 AND 2000),
        CONSTRAINT CK_site_feedback_items_acceptance CHECK (acceptance_criteria_json IS NULL OR (ISJSON(acceptance_criteria_json) = 1 AND LEN(acceptance_criteria_json) <= 4000)),
        CONSTRAINT CK_site_feedback_items_risk CHECK (risk_level IS NULL OR risk_level IN ('low','medium','high','needs_clarification')),
        CONSTRAINT CK_site_feedback_items_hashes CHECK
            ((page_fingerprint IS NULL OR (LEN(page_fingerprint) = 64 AND page_fingerprint NOT LIKE '%[^0-9A-Fa-f]%'))
             AND (element_fingerprint IS NULL OR (LEN(element_fingerprint) = 64 AND element_fingerprint NOT LIKE '%[^0-9A-Fa-f]%')))
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.site_feedback_items') AND name=N'UX_site_feedback_items_batch_sequence_active')
BEGIN
    CREATE UNIQUE INDEX UX_site_feedback_items_batch_sequence_active
        ON dbo.site_feedback_items(batch_id,sequence_number)
        WHERE is_deleted=0;
END;
GO

IF OBJECT_ID(N'dbo.site_feedback_dispatches', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.site_feedback_dispatches
    (
        dispatch_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_site_feedback_dispatches PRIMARY KEY,
        batch_id BIGINT NOT NULL,
        dispatch_kind VARCHAR(16) NOT NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_site_feedback_dispatches_status DEFAULT ('queued'),
        revision_number INT NOT NULL,
        request_sha256 CHAR(64) NOT NULL,
        result_sha256 CHAR(64) NULL,
        error_code VARCHAR(64) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_dispatches_created_at DEFAULT (SYSUTCDATETIME()),
        started_at DATETIME2(3) NULL,
        completed_at DATETIME2(3) NULL,
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_site_feedback_dispatches_batch_kind_revision UNIQUE (batch_id, dispatch_kind, revision_number),
        CONSTRAINT FK_site_feedback_dispatches_batch FOREIGN KEY (batch_id) REFERENCES dbo.site_feedback_batches(batch_id),
        CONSTRAINT CK_site_feedback_dispatches_kind CHECK (dispatch_kind IN ('analysis','execution')),
        CONSTRAINT CK_site_feedback_dispatches_status CHECK (status_code IN ('queued','processing','completed','failed')),
        CONSTRAINT CK_site_feedback_dispatches_revision CHECK (revision_number BETWEEN 1 AND 1000000),
        CONSTRAINT CK_site_feedback_dispatches_hashes CHECK
            (LEN(request_sha256) = 64 AND request_sha256 NOT LIKE '%[^0-9A-Fa-f]%'
             AND (result_sha256 IS NULL OR (LEN(result_sha256) = 64 AND result_sha256 NOT LIKE '%[^0-9A-Fa-f]%')))
    );
END;
GO

IF OBJECT_ID(N'dbo.site_feedback_events', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.site_feedback_events
    (
        event_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_site_feedback_events PRIMARY KEY,
        batch_id BIGINT NOT NULL,
        item_id BIGINT NULL,
        event_type VARCHAR(64) NOT NULL,
        outcome VARCHAR(32) NOT NULL,
        actor_user_id BIGINT NULL,
        detail NVARCHAR(1000) NULL,
        occurred_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_events_occurred_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT FK_site_feedback_events_batch FOREIGN KEY (batch_id) REFERENCES dbo.site_feedback_batches(batch_id),
        CONSTRAINT FK_site_feedback_events_item FOREIGN KEY (item_id) REFERENCES dbo.site_feedback_items(item_id),
        CONSTRAINT FK_site_feedback_events_actor FOREIGN KEY (actor_user_id) REFERENCES dbo.auth_users(user_id)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_site_feedback_batches_owner_updated' AND object_id = OBJECT_ID(N'dbo.site_feedback_batches'))
    CREATE INDEX IX_site_feedback_batches_owner_updated ON dbo.site_feedback_batches(created_by_user_id, updated_at DESC) INCLUDE (public_id, title, status_code, revision_number);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_site_feedback_items_batch_sequence' AND object_id = OBJECT_ID(N'dbo.site_feedback_items'))
    CREATE INDEX IX_site_feedback_items_batch_sequence ON dbo.site_feedback_items(batch_id, sequence_number) INCLUDE (public_id, target_action, annotation_type_code, instruction_text);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_site_feedback_dispatches_status' AND object_id = OBJECT_ID(N'dbo.site_feedback_dispatches'))
    CREATE INDEX IX_site_feedback_dispatches_status ON dbo.site_feedback_dispatches(status_code, dispatch_kind, created_at) INCLUDE (batch_id, dispatch_id, revision_number, request_sha256);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_site_feedback_events_batch_occurred' AND object_id = OBJECT_ID(N'dbo.site_feedback_events'))
    CREATE INDEX IX_site_feedback_events_batch_occurred ON dbo.site_feedback_events(batch_id, occurred_at DESC);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'016_site_feedback.sql')
    INSERT INTO dbo.schema_migrations(script_name, checksum_sha256) VALUES (N'016_site_feedback.sql', NULL);
GO
COMMIT TRANSACTION;
GO
