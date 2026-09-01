/* Link already-deployed staged revisions to presentation change requests. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.staged_document_revisions', N'U') IS NULL OR OBJECT_ID(N'dbo.presentation_change_requests', N'U') IS NULL
    THROW 51011, 'Run migrations 006 and 010 first.', 1;

IF COL_LENGTH(N'dbo.staged_document_revisions', N'change_request_id') IS NULL
    ALTER TABLE dbo.staged_document_revisions ADD change_request_id BIGINT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_staged_document_revisions_change_request')
    EXEC(N'ALTER TABLE dbo.staged_document_revisions ADD CONSTRAINT FK_staged_document_revisions_change_request
        FOREIGN KEY (change_request_id) REFERENCES dbo.presentation_change_requests (change_request_id)');

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.staged_document_revisions') AND name = N'IX_staged_document_revisions_change_request')
    EXEC(N'CREATE INDEX IX_staged_document_revisions_change_request
        ON dbo.staged_document_revisions (change_request_id, updated_at DESC)
        WHERE change_request_id IS NOT NULL');

COMMIT TRANSACTION;
GO
