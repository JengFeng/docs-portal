/* Cache-first website working copy and delayed Drive write-back projection. */
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

IF OBJECT_ID(N'dbo.documents', N'U') IS NULL
   OR OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
    THROW 51024, 'Run migrations 001 through 023 before 024_cache_first_documents.sql.', 1;
GO

DECLARE @cache_first_columns INT =
      CASE WHEN COL_LENGTH(N'dbo.documents', N'writeback_status') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'source_content_hash') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'writeback_generation') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'writeback_due_at') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'writeback_error_code') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'writeback_observed_source_hash') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'working_modified_at') IS NULL THEN 0 ELSE 1 END
    + CASE WHEN COL_LENGTH(N'dbo.documents', N'writeback_updated_at') IS NULL THEN 0 ELSE 1 END;
IF @cache_first_columns NOT IN (0, 8)
    THROW 51025, 'Incomplete cache-first document schema; restore or repair it before migration 024.', 1;

IF @cache_first_columns = 0
BEGIN
    ALTER TABLE dbo.documents ADD
        writeback_status VARCHAR(8) NULL,
        source_content_hash CHAR(64) NULL,
        writeback_generation UNIQUEIDENTIFIER NULL,
        writeback_due_at DATETIME2(3) NULL,
        writeback_error_code VARCHAR(64) NULL,
        writeback_observed_source_hash CHAR(64) NULL,
        working_modified_at DATETIME2(3) NULL,
        writeback_updated_at DATETIME2(3) NULL;

    UPDATE dbo.documents
       SET writeback_status = 'synced',
           source_content_hash = content_hash,
           working_modified_at = source_modified_at,
           writeback_updated_at = SYSUTCDATETIME();

    ALTER TABLE dbo.documents ALTER COLUMN writeback_status VARCHAR(8) NOT NULL;
    ALTER TABLE dbo.documents ALTER COLUMN working_modified_at DATETIME2(3) NOT NULL;
    ALTER TABLE dbo.documents ALTER COLUMN writeback_updated_at DATETIME2(3) NOT NULL;
END;
GO

IF EXISTS
(
    SELECT 1
    FROM (VALUES
        (N'writeback_status',N'varchar',8,0,0),
        (N'source_content_hash',N'char',64,0,1),
        (N'writeback_generation',N'uniqueidentifier',16,0,1),
        (N'writeback_due_at',N'datetime2',8,3,1),
        (N'writeback_error_code',N'varchar',64,0,1),
        (N'writeback_observed_source_hash',N'char',64,0,1),
        (N'working_modified_at',N'datetime2',8,3,0),
        (N'writeback_updated_at',N'datetime2',8,3,0)
    ) AS expected(name,type_name,max_length,scale,is_nullable)
    LEFT JOIN sys.columns c
      ON c.object_id=OBJECT_ID(N'dbo.documents') AND c.name=expected.name
    WHERE c.column_id IS NULL
       OR TYPE_NAME(c.user_type_id)<>expected.type_name
       OR c.max_length<>expected.max_length
       OR c.scale<>expected.scale
       OR c.is_nullable<>expected.is_nullable
)
    THROW 51026, 'Cache-first document column metadata mismatch; restore or repair it before migration 024.', 1;
GO

IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'DF_documents_writeback_status')
    ALTER TABLE dbo.documents ADD CONSTRAINT DF_documents_writeback_status DEFAULT('synced') FOR writeback_status;
IF NOT EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'DF_documents_writeback_updated')
    ALTER TABLE dbo.documents ADD CONSTRAINT DF_documents_writeback_updated DEFAULT(SYSUTCDATETIME()) FOR writeback_updated_at;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_writeback_status')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_writeback_status
        CHECK (writeback_status IN ('synced','pending','conflict','failed'));
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_source_content_hash')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_source_content_hash
        CHECK (source_content_hash IS NULL OR (LEN(source_content_hash)=64 AND source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'));
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_writeback_observed_hash')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_writeback_observed_hash
        CHECK (writeback_observed_source_hash IS NULL OR (LEN(writeback_observed_source_hash)=64 AND writeback_observed_source_hash NOT LIKE '%[^0-9A-Fa-f]%'));
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_writeback_error_code')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_writeback_error_code
        CHECK (writeback_error_code IS NULL OR (LEN(writeback_error_code) BETWEEN 3 AND 64 AND writeback_error_code NOT LIKE '%[^A-Z0-9_]%'));
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_writeback_consistency')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_writeback_consistency CHECK
    (
        (writeback_status='synced'
          AND ((content_hash IS NULL AND source_content_hash IS NULL) OR content_hash=source_content_hash)
          AND writeback_generation IS NULL AND writeback_due_at IS NULL
          AND writeback_error_code IS NULL AND writeback_observed_source_hash IS NULL)
        OR
        (writeback_status='pending'
          AND content_hash IS NOT NULL AND source_content_hash IS NOT NULL AND content_hash<>source_content_hash
          AND writeback_generation IS NOT NULL AND writeback_due_at IS NOT NULL
          AND writeback_error_code IS NULL AND writeback_observed_source_hash IS NULL)
        OR
        (writeback_status='failed'
          AND content_hash IS NOT NULL AND source_content_hash IS NOT NULL AND content_hash<>source_content_hash
          AND writeback_generation IS NOT NULL AND writeback_due_at IS NULL
          AND writeback_error_code IS NOT NULL AND writeback_observed_source_hash IS NULL)
        OR
        (writeback_status='conflict'
          AND content_hash IS NOT NULL AND source_content_hash IS NOT NULL AND content_hash<>source_content_hash
          AND writeback_generation IS NOT NULL AND writeback_due_at IS NULL
          AND writeback_error_code='SOURCE_CHANGED' AND writeback_observed_source_hash IS NOT NULL
          AND writeback_observed_source_hash<>source_content_hash)
    );
GO

ALTER TABLE dbo.documents CHECK CONSTRAINT
    CK_documents_writeback_status,
    CK_documents_source_content_hash,
    CK_documents_writeback_observed_hash,
    CK_documents_writeback_error_code,
    CK_documents_writeback_consistency;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.documents') AND name=N'IX_documents_writeback_due')
    CREATE INDEX IX_documents_writeback_due
        ON dbo.documents(writeback_status, writeback_due_at, document_id)
        INCLUDE(relative_path, content_hash, source_content_hash, writeback_generation, writeback_updated_at)
        WHERE writeback_status IN ('pending','failed','conflict');
GO

IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT, UPDATE ON dbo.documents TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.documents TO [TWWATER_PORTAL_APP];
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'024_cache_first_documents.sql')
    INSERT INTO dbo.schema_migrations(script_name, checksum_sha256)
    VALUES(N'024_cache_first_documents.sql', NULL);
GO

COMMIT TRANSACTION;
GO
