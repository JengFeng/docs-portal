/* Enforce snapshot append-only behavior even when the application database
   principal has broader legacy privileges. Run after 017 and 018. */
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.site_feedback_snapshots',N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
    THROW 51021,'Run 017 and 018 before 019.',1;
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

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'019_site_feedback_snapshot_immutability.sql')
    INSERT INTO dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'019_site_feedback_snapshot_immutability.sql',NULL);
GO
COMMIT TRANSACTION;
GO
