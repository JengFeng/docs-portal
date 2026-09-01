/* Immutable before/after viewport snapshots for site feedback revisions.
   Approved by Gary on 2026-08-28. Run after 016_site_feedback.sql. */
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

IF OBJECT_ID(N'dbo.site_feedback_batches', N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
BEGIN
    THROW 51017, 'Run migrations 001 through 016 before 017_site_feedback_snapshots.sql.', 1;
END;
GO

IF OBJECT_ID(N'dbo.site_feedback_snapshots', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.site_feedback_snapshots
    (
        snapshot_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_site_feedback_snapshots PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_site_feedback_snapshots_public_id DEFAULT (NEWID()),
        batch_id BIGINT NOT NULL,
        revision_number INT NOT NULL,
        snapshot_kind VARCHAR(8) NOT NULL,
        target_url NVARCHAR(1000) NOT NULL,
        target_url_sha256 CHAR(64) NOT NULL,
        viewport_width INT NOT NULL,
        viewport_height INT NOT NULL,
        scroll_x INT NOT NULL CONSTRAINT DF_site_feedback_snapshots_scroll_x DEFAULT (0),
        scroll_y INT NOT NULL CONSTRAINT DF_site_feedback_snapshots_scroll_y DEFAULT (0),
        mime_type VARCHAR(32) NOT NULL CONSTRAINT DF_site_feedback_snapshots_mime DEFAULT ('image/png'),
        image_width INT NOT NULL,
        image_height INT NOT NULL,
        image_byte_count INT NOT NULL,
        image_sha256 CHAR(64) NOT NULL,
        sensitive_content_reviewed BIT NOT NULL,
        redaction_profile VARCHAR(32) NOT NULL,
        image_bytes VARBINARY(MAX) NOT NULL,
        created_by_user_id BIGINT NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_site_feedback_snapshots_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT UQ_site_feedback_snapshots_public_id UNIQUE (public_id),
        CONSTRAINT UQ_site_feedback_snapshots_immutable UNIQUE (batch_id, revision_number, snapshot_kind, target_url_sha256),
        CONSTRAINT FK_site_feedback_snapshots_batch FOREIGN KEY (batch_id) REFERENCES dbo.site_feedback_batches(batch_id),
        CONSTRAINT FK_site_feedback_snapshots_creator FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users(user_id),
        CONSTRAINT CK_site_feedback_snapshots_revision CHECK (revision_number BETWEEN 1 AND 1000000),
        CONSTRAINT CK_site_feedback_snapshots_kind CHECK (snapshot_kind IN ('before','after')),
        CONSTRAINT CK_site_feedback_snapshots_target_hash CHECK (LEN(target_url_sha256)=64 AND target_url_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_site_feedback_snapshots_viewport CHECK (viewport_width BETWEEN 240 AND 8192 AND viewport_height BETWEEN 240 AND 8192 AND scroll_x>=0 AND scroll_y>=0),
        CONSTRAINT CK_site_feedback_snapshots_image CHECK (mime_type='image/png' AND image_width=viewport_width AND image_height=viewport_height AND image_byte_count=DATALENGTH(image_bytes) AND image_byte_count BETWEEN 67 AND 6291456 AND CAST(image_width AS BIGINT)*CAST(image_height AS BIGINT)<=20000000),
        CONSTRAINT CK_site_feedback_snapshots_image_hash CHECK (LEN(image_sha256)=64 AND image_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_site_feedback_snapshots_review CHECK (sensitive_content_reviewed=1 AND redaction_profile='password-explicit-v1')
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.site_feedback_snapshots') AND name=N'IX_site_feedback_snapshots_batch_revision')
    CREATE INDEX IX_site_feedback_snapshots_batch_revision ON dbo.site_feedback_snapshots(batch_id, revision_number, snapshot_kind, created_at DESC) INCLUDE (public_id, target_url, image_width, image_height, image_byte_count, image_sha256);
GO

IF OBJECT_ID(N'dbo.TR_site_feedback_snapshots_immutable',N'TR') IS NULL
    EXEC(N'CREATE TRIGGER dbo.TR_site_feedback_snapshots_immutable
ON dbo.site_feedback_snapshots
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 51020, ''Site feedback snapshots are immutable and cannot be updated or deleted.'', 1;
END;');
GO

/* Snapshot rows are immutable even if a broad database role exists. */
DENY UPDATE, DELETE ON dbo.site_feedback_snapshots TO public;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'017_site_feedback_snapshots.sql')
    INSERT INTO dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'017_site_feedback_snapshots.sql',NULL);
GO
COMMIT TRANSACTION;
GO
