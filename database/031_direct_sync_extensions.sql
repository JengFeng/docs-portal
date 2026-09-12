/* Direct Sync document catalog extension contract.
   Run against the current deployed portal schema baseline using the controlled migration identity. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO
IF OBJECT_ID(N'dbo.documents', N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
    THROW 51231, 'Migration 031 requires the existing portal catalog.', 1;
GO
IF EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'031_direct_sync_extensions.sql')
BEGIN
    IF COL_LENGTH(N'dbo.documents', N'extension') <> 64
       OR NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name=N'CK_documents_extension' AND parent_object_id=OBJECT_ID(N'dbo.documents'))
        THROW 51232, 'DIRECT_SYNC_EXTENSION_SCHEMA_DRIFT', 1;
    COMMIT TRANSACTION;
    RETURN;
END;
GO
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name=N'CK_documents_extension' AND parent_object_id=OBJECT_ID(N'dbo.documents'))
    ALTER TABLE dbo.documents DROP CONSTRAINT CK_documents_extension;
GO
ALTER TABLE dbo.documents ALTER COLUMN extension VARCHAR(64) NOT NULL;
GO
ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_extension CHECK
    (LEN(extension) BETWEEN 1 AND 64 AND extension NOT LIKE '%[\\/:]%');
GO
INSERT dbo.schema_migrations(script_name, checksum_sha256) VALUES (N'031_direct_sync_extensions.sql', NULL);
GO
COMMIT TRANSACTION;
GO
