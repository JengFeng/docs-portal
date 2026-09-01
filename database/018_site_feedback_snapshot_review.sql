/* Require an explicit sensitive-content review and fixed client redaction profile
   for every immutable site-feedback snapshot. Run after 017. */
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.site_feedback_snapshots',N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
BEGIN
    THROW 51018,'Run 017_site_feedback_snapshots.sql before 018.',1;
END;
GO

IF COL_LENGTH(N'dbo.site_feedback_snapshots',N'sensitive_content_reviewed') IS NULL
   OR COL_LENGTH(N'dbo.site_feedback_snapshots',N'redaction_profile') IS NULL
BEGIN
    IF EXISTS (SELECT 1 FROM dbo.site_feedback_snapshots)
        THROW 51019,'Existing snapshot rows require manual review; migration will not retroactively attest them.',1;
    ALTER TABLE dbo.site_feedback_snapshots ADD
        sensitive_content_reviewed BIT NOT NULL,
        redaction_profile VARCHAR(32) NOT NULL;
END;
GO

IF OBJECT_ID(N'dbo.CK_site_feedback_snapshots_review',N'C') IS NULL
    ALTER TABLE dbo.site_feedback_snapshots WITH CHECK ADD CONSTRAINT CK_site_feedback_snapshots_review CHECK (sensitive_content_reviewed=1 AND redaction_profile='password-explicit-v1');
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'018_site_feedback_snapshot_review.sql')
    INSERT INTO dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'018_site_feedback_snapshot_review.sql',NULL);
GO
COMMIT TRANSACTION;
GO
