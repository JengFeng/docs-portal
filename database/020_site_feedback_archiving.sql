/* Reversible soft archiving for site feedback batches.
   Archived batches retain requirements, immutable snapshots, and audit events. */
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

IF OBJECT_ID(N'dbo.site_feedback_batches',N'U') IS NULL OR OBJECT_ID(N'dbo.auth_users',N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
    THROW 51022,'Run migrations 001 through 019 before 020_site_feedback_archiving.sql.',1;
GO

IF COL_LENGTH(N'dbo.site_feedback_batches',N'archived_at') IS NULL
    ALTER TABLE dbo.site_feedback_batches ADD archived_at DATETIME2(3) NULL;
GO
IF COL_LENGTH(N'dbo.site_feedback_batches',N'archived_by_user_id') IS NULL
    ALTER TABLE dbo.site_feedback_batches ADD archived_by_user_id BIGINT NULL;
GO

IF OBJECT_ID(N'dbo.FK_site_feedback_batches_archived_by',N'F') IS NULL
    ALTER TABLE dbo.site_feedback_batches WITH CHECK ADD CONSTRAINT FK_site_feedback_batches_archived_by FOREIGN KEY(archived_by_user_id) REFERENCES dbo.auth_users(user_id);
GO
IF OBJECT_ID(N'dbo.CK_site_feedback_batches_archive_pair',N'C') IS NULL
    ALTER TABLE dbo.site_feedback_batches WITH CHECK ADD CONSTRAINT CK_site_feedback_batches_archive_pair CHECK ((archived_at IS NULL AND archived_by_user_id IS NULL) OR (archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL));
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.site_feedback_batches') AND name=N'IX_site_feedback_batches_archive_updated')
    CREATE INDEX IX_site_feedback_batches_archive_updated ON dbo.site_feedback_batches(archived_at,updated_at DESC,batch_id DESC) INCLUDE(public_id,title,status_code,revision_number,created_by_user_id,completed_at);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'020_site_feedback_archiving.sql')
    INSERT INTO dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'020_site_feedback_archiving.sql',NULL);
GO
COMMIT TRANSACTION;
GO
