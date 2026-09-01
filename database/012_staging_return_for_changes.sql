/* Add return-for-changes review state to deployed staging workspaces. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.staged_document_revisions', N'U') IS NULL
    THROW 51012, 'Run migration 010 first.', 1;

IF COL_LENGTH(N'dbo.staged_document_revisions', N'review_note') IS NULL
    ALTER TABLE dbo.staged_document_revisions ADD review_note NVARCHAR(1000) NULL;

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_staged_document_revisions_status')
    ALTER TABLE dbo.staged_document_revisions DROP CONSTRAINT CK_staged_document_revisions_status;

ALTER TABLE dbo.staged_document_revisions ADD CONSTRAINT CK_staged_document_revisions_status
    CHECK (status_code IN ('uploading','uploaded','previewing','preview_ready','changes_requested','confirming','confirmed','conflict','failed','cancelled','expired'));

COMMIT TRANSACTION;
GO
