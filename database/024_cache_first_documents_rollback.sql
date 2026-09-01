/* Fail-closed rollback for 024_cache_first_documents.sql. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.documents',N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
    THROW 51124, 'Required base schema is missing; refusing migration 024 rollback.', 1;
GO

DECLARE @cache_first_columns INT =
      CASE WHEN COL_LENGTH(N'dbo.documents',N'writeback_status') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'source_content_hash') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'writeback_generation') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'writeback_due_at') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'writeback_error_code') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'writeback_observed_source_hash') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'working_modified_at') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents',N'writeback_updated_at') IS NULL THEN 0 ELSE 1 END;

IF @cache_first_columns NOT IN (0,8)
    THROW 51125, 'Incomplete cache-first document schema; refusing migration 024 rollback.', 1;

IF @cache_first_columns=0
   AND EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'024_cache_first_documents.sql')
    THROW 51126, 'Migration ledger exists but cache-first columns are absent; refusing rollback.', 1;

IF @cache_first_columns=8
BEGIN
    IF EXISTS
    (
        SELECT 1 FROM dbo.documents
        WHERE writeback_status<>'synced'
           OR (content_hash<>source_content_hash)
           OR (content_hash IS NULL AND source_content_hash IS NOT NULL)
           OR (content_hash IS NOT NULL AND source_content_hash IS NULL)
    )
        THROW 51127, 'Refusing rollback: unsynced website working copies exist.', 1;

    IF (SELECT COUNT(*) FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents')
        AND name IN(N'CK_documents_writeback_status',N'CK_documents_source_content_hash',N'CK_documents_writeback_observed_hash',N'CK_documents_writeback_error_code',N'CK_documents_writeback_consistency'))<>5
        THROW 51128, 'Cache-first checks are incomplete; refusing rollback.', 1;
    IF (SELECT COUNT(*) FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents')
        AND name IN(N'DF_documents_writeback_status',N'DF_documents_writeback_updated'))<>2
        THROW 51129, 'Cache-first defaults are incomplete; refusing rollback.', 1;
    IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.documents') AND name=N'IX_documents_writeback_due')
        THROW 51130, 'Cache-first write-back index is missing; refusing rollback.', 1;

    DROP INDEX IX_documents_writeback_due ON dbo.documents;

    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_writeback_consistency;
    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_writeback_error_code;
    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_writeback_observed_hash;
    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_source_content_hash;
    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_writeback_status;

    ALTER TABLE dbo.documents DROP CONSTRAINT DF_documents_writeback_updated;
    ALTER TABLE dbo.documents DROP CONSTRAINT DF_documents_writeback_status;

    ALTER TABLE dbo.documents DROP COLUMN writeback_status;
    ALTER TABLE dbo.documents DROP COLUMN source_content_hash;
    ALTER TABLE dbo.documents DROP COLUMN writeback_generation;
    ALTER TABLE dbo.documents DROP COLUMN writeback_due_at;
    ALTER TABLE dbo.documents DROP COLUMN writeback_error_code;
    ALTER TABLE dbo.documents DROP COLUMN writeback_observed_source_hash;
    ALTER TABLE dbo.documents DROP COLUMN working_modified_at;
    ALTER TABLE dbo.documents DROP COLUMN writeback_updated_at;

    DELETE FROM dbo.schema_migrations WHERE script_name=N'024_cache_first_documents.sql';

    IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
        REVOKE DELETE ON dbo.documents FROM [TWWATER_PORTAL_APP];
END;
GO

COMMIT TRANSACTION;
GO
