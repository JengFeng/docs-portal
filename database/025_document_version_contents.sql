/* Immutable SQL-backed document version contents with durable capture state. */
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
IF CONVERT(INT,SERVERPROPERTY('ProductMajorVersion'))<13
    THROW 51025,'SQL Server 2016 or newer is required for large SHA2_256 inputs.',1;
IF OBJECT_ID(N'dbo.documents',N'U') IS NULL OR OBJECT_ID(N'dbo.document_versions',N'U') IS NULL OR OBJECT_ID(N'dbo.schema_migrations',N'U') IS NULL
    THROW 51026,'Run migrations 001 through 024 before 025_document_version_contents.sql.',1;
GO
DECLARE @document_columns INT=
    CASE WHEN COL_LENGTH(N'dbo.documents',N'current_version_id') IS NULL THEN 0 ELSE 1 END+
    CASE WHEN COL_LENGTH(N'dbo.documents',N'version_capture_status') IS NULL THEN 0 ELSE 1 END+
    CASE WHEN COL_LENGTH(N'dbo.documents',N'version_capture_error_code') IS NULL THEN 0 ELSE 1 END+
    CASE WHEN COL_LENGTH(N'dbo.documents',N'version_capture_updated_at') IS NULL THEN 0 ELSE 1 END;
DECLARE @has_contents INT=CASE WHEN OBJECT_ID(N'dbo.document_version_contents',N'U') IS NULL THEN 0 ELSE 1 END;
IF NOT ((@document_columns=0 AND @has_contents=0) OR (@document_columns=4 AND @has_contents=1))
    THROW 51027,'Partial document-version content schema detected.',1;
IF @has_contents=0
BEGIN
    CREATE TABLE dbo.document_version_contents
    (
        version_id BIGINT NOT NULL CONSTRAINT PK_document_version_contents PRIMARY KEY,
        content_hash CHAR(64) NOT NULL,
        file_size_bytes BIGINT NOT NULL,
        content_bytes VARBINARY(MAX) NOT NULL,
        stored_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_version_contents_stored DEFAULT(SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT FK_document_version_contents_version FOREIGN KEY(version_id) REFERENCES dbo.document_versions(version_id),
        CONSTRAINT CK_document_version_contents_hash CHECK(LEN(content_hash)=64 AND content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_document_version_contents_size CHECK(file_size_bytes BETWEEN 0 AND 157286400 AND CONVERT(BIGINT,DATALENGTH(content_bytes))=file_size_bytes)
    );
    ALTER TABLE dbo.documents ADD
        current_version_id BIGINT NULL,
        version_capture_status VARCHAR(8) NULL,
        version_capture_error_code VARCHAR(64) NULL,
        version_capture_updated_at DATETIME2(3) NULL;
END;
GO
UPDATE dbo.documents SET version_capture_status='pending',version_capture_updated_at=SYSUTCDATETIME() WHERE version_capture_status IS NULL OR version_capture_updated_at IS NULL;
ALTER TABLE dbo.documents ALTER COLUMN version_capture_status VARCHAR(8) NOT NULL;
ALTER TABLE dbo.documents ALTER COLUMN version_capture_updated_at DATETIME2(3) NOT NULL;
GO
IF EXISTS(SELECT 1 FROM (VALUES
 (N'version_id',N'bigint',8,0,0),(N'content_hash',N'char',64,0,0),(N'file_size_bytes',N'bigint',8,0,0),
 (N'content_bytes',N'varbinary',-1,0,0),(N'stored_at',N'datetime2',7,3,0),(N'rowver',N'timestamp',8,0,0)
) e(name,type_name,max_length,scale,is_nullable)
LEFT JOIN sys.columns c ON c.object_id=OBJECT_ID(N'dbo.document_version_contents') AND c.name=e.name
WHERE c.column_id IS NULL OR TYPE_NAME(c.user_type_id)<>e.type_name OR c.max_length<>e.max_length OR c.scale<>e.scale OR c.is_nullable<>e.is_nullable)
    THROW 51028,'Document-version content column metadata mismatch.',1;
IF EXISTS(SELECT 1 FROM (VALUES
 (N'current_version_id',N'bigint',8,0,1),(N'version_capture_status',N'varchar',8,0,0),
 (N'version_capture_error_code',N'varchar',64,0,1),(N'version_capture_updated_at',N'datetime2',7,3,0)
) e(name,type_name,max_length,scale,is_nullable)
LEFT JOIN sys.columns c ON c.object_id=OBJECT_ID(N'dbo.documents') AND c.name=e.name
WHERE c.column_id IS NULL OR TYPE_NAME(c.user_type_id)<>e.type_name OR c.max_length<>e.max_length OR c.scale<>e.scale OR c.is_nullable<>e.is_nullable)
    THROW 51029,'Document capture-state column metadata mismatch.',1;
GO
IF NOT EXISTS(SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'DF_documents_version_capture_status')
    ALTER TABLE dbo.documents ADD CONSTRAINT DF_documents_version_capture_status DEFAULT('pending') FOR version_capture_status;
IF NOT EXISTS(SELECT 1 FROM sys.default_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'DF_documents_version_capture_updated')
    ALTER TABLE dbo.documents ADD CONSTRAINT DF_documents_version_capture_updated DEFAULT(SYSUTCDATETIME()) FOR version_capture_updated_at;
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_version_capture_status')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_version_capture_status CHECK(version_capture_status IN('pending','ready','failed'));
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_version_capture_error')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_version_capture_error CHECK(version_capture_error_code IS NULL OR (LEN(version_capture_error_code) BETWEEN 3 AND 64 AND version_capture_error_code NOT LIKE '%[^A-Z0-9_]%'));
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'CK_documents_version_capture_consistency')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT CK_documents_version_capture_consistency CHECK(
      (version_capture_status='ready' AND current_version_id IS NOT NULL AND version_capture_error_code IS NULL) OR
      (version_capture_status='pending' AND current_version_id IS NULL AND version_capture_error_code IS NULL) OR
      (version_capture_status='failed' AND current_version_id IS NULL AND version_capture_error_code IS NOT NULL));
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes ix WHERE ix.object_id=OBJECT_ID(N'dbo.document_versions') AND ix.name=N'UQ_document_versions_version_document' AND ix.is_unique=1 AND ix.is_disabled=0 AND (SELECT COL_NAME(ic.object_id,ic.column_id) FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal=1)=N'version_id' AND (SELECT COL_NAME(ic.object_id,ic.column_id) FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal=2)=N'document_id' AND NOT EXISTS(SELECT 1 FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal>2))
    THROW 51030,'Required document-version composite identity is missing or malformed.',1;
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(N'dbo.documents') AND name=N'FK_documents_current_version')
    ALTER TABLE dbo.documents WITH CHECK ADD CONSTRAINT FK_documents_current_version FOREIGN KEY(current_version_id,document_id) REFERENCES dbo.document_versions(version_id,document_id);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.documents') AND name=N'IX_documents_current_version')
    CREATE INDEX IX_documents_current_version ON dbo.documents(current_version_id,document_id) WHERE current_version_id IS NOT NULL;
GO
CREATE OR ALTER TRIGGER dbo.TR_document_version_contents_validate ON dbo.document_version_contents AFTER INSERT AS
BEGIN
 SET NOCOUNT ON;
 IF EXISTS(SELECT 1 FROM inserted i LEFT JOIN dbo.document_versions v ON v.version_id=i.version_id
           WHERE v.version_id IS NULL OR LOWER(i.content_hash)<>LOWER(v.source_content_hash) OR i.file_size_bytes<>v.source_file_size_bytes OR LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',i.content_bytes),2))<>LOWER(i.content_hash))
    THROW 51031,'Document-version bytes do not match immutable version metadata.',1;
END;
GO
CREATE OR ALTER TRIGGER dbo.TR_document_version_contents_immutable ON dbo.document_version_contents INSTEAD OF UPDATE,DELETE AS
    THROW 51032,'Document-version bytes are immutable.',1;
GO
CREATE OR ALTER PROCEDURE dbo.portal_prepare_document_version_capture AS
BEGIN
 SET NOCOUNT ON;
 UPDATE d SET
   current_version_id=CASE WHEN v.version_id IS NOT NULL AND c.version_id IS NOT NULL AND LOWER(v.source_content_hash)=LOWER(d.content_hash) AND v.source_file_size_bytes=d.file_size_bytes AND LOWER(c.content_hash)=LOWER(d.content_hash) AND c.file_size_bytes=d.file_size_bytes AND CONVERT(BIGINT,DATALENGTH(c.content_bytes))=d.file_size_bytes AND LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',c.content_bytes),2))=LOWER(d.content_hash) THEN v.version_id ELSE NULL END,
   version_capture_status=CASE WHEN v.version_id IS NOT NULL AND c.version_id IS NOT NULL AND LOWER(v.source_content_hash)=LOWER(d.content_hash) AND v.source_file_size_bytes=d.file_size_bytes AND LOWER(c.content_hash)=LOWER(d.content_hash) AND c.file_size_bytes=d.file_size_bytes AND CONVERT(BIGINT,DATALENGTH(c.content_bytes))=d.file_size_bytes AND LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',c.content_bytes),2))=LOWER(d.content_hash) THEN 'ready' ELSE 'pending' END,
   version_capture_error_code=NULL,version_capture_updated_at=SYSUTCDATETIME()
 FROM dbo.documents d
 LEFT JOIN dbo.document_versions v ON v.version_id=d.current_version_id AND v.document_id=d.document_id
 LEFT JOIN dbo.document_version_contents c ON c.version_id=v.version_id
 WHERE d.status_code<>'archived' AND d.content_hash IS NOT NULL;
END;
GO
CREATE OR ALTER PROCEDURE dbo.portal_capture_current_document_version
 @document_id BIGINT,@expected_hash CHAR(64),@content_bytes VARBINARY(MAX)
AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @initial INT=@@TRANCOUNT;
 IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION capture_document_version;
 BEGIN TRY
  DECLARE @catalog_hash CHAR(64),@catalog_size BIGINT,@modified DATETIME2(3),@path NVARCHAR(512);
  SELECT @catalog_hash=content_hash,@catalog_size=file_size_bytes,@modified=source_modified_at,@path=relative_path FROM dbo.documents WITH(UPDLOCK,HOLDLOCK) WHERE document_id=@document_id AND status_code<>'archived';
  IF @catalog_hash IS NULL OR LOWER(@catalog_hash)<>LOWER(@expected_hash) THROW 51033,'Current document hash changed before version capture.',1;
  IF @content_bytes IS NULL OR @catalog_size>157286400 OR CONVERT(BIGINT,DATALENGTH(@content_bytes))<>@catalog_size THROW 51034,'Current document byte length does not match catalog metadata.',1;
  DECLARE @actual CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',@content_bytes),2));
  IF @actual<>LOWER(@expected_hash) THROW 51035,'Current document bytes do not match expected hash.',1;
  DECLARE @version_id BIGINT,@version_number INT,@stored_size BIGINT,@stored_path NVARCHAR(512),@stored_modified DATETIME2(3);
  SELECT @version_id=version_id,@stored_size=source_file_size_bytes,@stored_path=source_relative_path,@stored_modified=source_modified_at FROM dbo.document_versions WITH(UPDLOCK,HOLDLOCK) WHERE document_id=@document_id AND source_content_hash=@catalog_hash;
  IF @version_id IS NOT NULL AND (@stored_size<>@catalog_size OR @stored_path<>@path OR @stored_modified<>@modified) THROW 51036,'Existing immutable version metadata conflicts with current catalog.',1;
  IF @version_id IS NULL
  BEGIN
   SELECT @version_number=ISNULL(MAX(version_number),0)+1 FROM dbo.document_versions WITH(UPDLOCK,HOLDLOCK) WHERE document_id=@document_id;
   INSERT dbo.document_versions(document_id,version_number,source_content_hash,source_file_size_bytes,source_modified_at,source_relative_path,created_by_user_id)
   VALUES(@document_id,@version_number,@catalog_hash,@catalog_size,@modified,@path,NULL);SET @version_id=SCOPE_IDENTITY();
  END;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_version_contents WITH(UPDLOCK,HOLDLOCK) WHERE version_id=@version_id)
    INSERT dbo.document_version_contents(version_id,content_hash,file_size_bytes,content_bytes) VALUES(@version_id,@catalog_hash,@catalog_size,@content_bytes);
  ELSE IF EXISTS(SELECT 1 FROM dbo.document_version_contents WHERE version_id=@version_id AND (LOWER(content_hash)<>LOWER(@catalog_hash) OR file_size_bytes<>@catalog_size OR CONVERT(BIGINT,DATALENGTH(content_bytes))<>@catalog_size OR LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',content_bytes),2))<>LOWER(@catalog_hash)))
    THROW 51037,'Existing version bytes conflict with immutable metadata.',1;
  UPDATE dbo.documents SET current_version_id=@version_id,version_capture_status='ready',version_capture_error_code=NULL,version_capture_updated_at=SYSUTCDATETIME() WHERE document_id=@document_id AND content_hash=@catalog_hash;
  IF @@ROWCOUNT<>1 THROW 51038,'Current document pointer changed during version capture.',1;
  IF @initial=0 COMMIT TRANSACTION;
  SELECT @version_id version_id,@actual content_hash,@catalog_size file_size_bytes;
 END TRY
 BEGIN CATCH
  IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION;ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION capture_document_version;THROW;
 END CATCH
END;
GO
CREATE OR ALTER PROCEDURE dbo.portal_mark_document_version_capture_failed @document_id BIGINT,@expected_hash CHAR(64),@error_code VARCHAR(64) AS
BEGIN
 SET NOCOUNT ON;
 IF @error_code IS NULL OR LEN(@error_code) NOT BETWEEN 3 AND 64 OR @error_code LIKE '%[^A-Z0-9_]%' THROW 51039,'Invalid capture error code.',1;
 UPDATE dbo.documents SET current_version_id=NULL,version_capture_status='failed',version_capture_error_code=@error_code,version_capture_updated_at=SYSUTCDATETIME() WHERE document_id=@document_id AND content_hash=@expected_hash AND status_code<>'archived';
 IF @@ROWCOUNT<>1 THROW 51040,'Capture failure no longer matches current document.',1;
END;
GO
CREATE OR ALTER PROCEDURE dbo.portal_list_document_versions @document_id BIGINT AS
BEGIN
 SET NOCOUNT ON;
 SELECT v.version_id,v.version_number,LOWER(v.source_content_hash) content_hash,v.source_file_size_bytes file_size_bytes,v.source_modified_at,v.created_at,c.stored_at,
  CASE WHEN c.version_id IS NULL THEN 0 ELSE 1 END has_content,
  CASE WHEN d.current_version_id=v.version_id AND d.version_capture_status='ready' AND d.content_hash=v.source_content_hash THEN 1 ELSE 0 END is_current
 FROM dbo.document_versions v JOIN dbo.documents d ON d.document_id=v.document_id LEFT JOIN dbo.document_version_contents c ON c.version_id=v.version_id
 WHERE v.document_id=@document_id ORDER BY CASE WHEN d.current_version_id=v.version_id AND d.version_capture_status='ready' AND d.content_hash=v.source_content_hash THEN 0 ELSE 1 END,v.version_number DESC;
END;
GO
CREATE OR ALTER PROCEDURE dbo.portal_read_document_version_content @document_id BIGINT,@content_hash CHAR(64) AS
BEGIN
 SET NOCOUNT ON;
 SELECT c.file_size_bytes,LOWER(c.content_hash) content_hash,LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',c.content_bytes),2)) actual_hash,c.content_bytes
 FROM dbo.document_versions v JOIN dbo.document_version_contents c ON c.version_id=v.version_id WHERE v.document_id=@document_id AND v.source_content_hash=@content_hash;
END;
GO
CREATE OR ALTER PROCEDURE dbo.portal_verify_document_version_contents AS
BEGIN
 SET NOCOUNT ON;
 SELECT COUNT(*) content_rows,ISNULL(SUM(c.file_size_bytes),0) total_content_bytes,
  SUM(CASE WHEN v.version_id IS NULL OR LOWER(v.source_content_hash)<>LOWER(c.content_hash) OR v.source_file_size_bytes<>c.file_size_bytes OR CONVERT(BIGINT,DATALENGTH(c.content_bytes))<>c.file_size_bytes OR LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',c.content_bytes),2))<>LOWER(c.content_hash) THEN 1 ELSE 0 END) invalid_rows
 FROM dbo.document_version_contents c LEFT JOIN dbo.document_versions v ON v.version_id=c.version_id;
END;
GO
/* Exact trust/state checks before granting use. */
IF NOT EXISTS(SELECT 1 FROM sys.indexes ix WHERE ix.object_id=OBJECT_ID(N'dbo.document_versions') AND ix.name=N'UQ_document_versions_document_hash' AND ix.is_unique=1 AND ix.is_disabled=0 AND (SELECT COL_NAME(ic.object_id,ic.column_id) FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal=1)=N'document_id' AND (SELECT COL_NAME(ic.object_id,ic.column_id) FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal=2)=N'source_content_hash' AND NOT EXISTS(SELECT 1 FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal>2)) THROW 51040,'Document hash identity is missing or malformed.',1;
IF NOT EXISTS(SELECT 1 FROM sys.key_constraints kc JOIN sys.indexes ix ON ix.object_id=kc.parent_object_id AND ix.index_id=kc.unique_index_id WHERE kc.parent_object_id=OBJECT_ID(N'dbo.document_version_contents') AND kc.name=N'PK_document_version_contents' AND kc.type='PK' AND ix.is_disabled=0 AND (SELECT COL_NAME(ic.object_id,ic.column_id) FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal=1)=N'version_id' AND NOT EXISTS(SELECT 1 FROM sys.index_columns ic WHERE ic.object_id=ix.object_id AND ic.index_id=ix.index_id AND ic.key_ordinal>1)) THROW 51041,'Version content primary key is missing or malformed.',1;
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fc ON fc.constraint_object_id=fk.object_id WHERE fk.parent_object_id=OBJECT_ID(N'dbo.document_version_contents') AND fk.referenced_object_id=OBJECT_ID(N'dbo.document_versions') AND fk.name=N'FK_document_version_contents_version' AND fk.is_disabled=0 AND fk.is_not_trusted=0 AND fc.constraint_column_id=1 AND COL_NAME(fc.parent_object_id,fc.parent_column_id)=N'version_id' AND COL_NAME(fc.referenced_object_id,fc.referenced_column_id)=N'version_id') THROW 51042,'Version content foreign key is missing, malformed, disabled, or untrusted.',1;
IF (SELECT COUNT(*) FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fc ON fc.constraint_object_id=fk.object_id WHERE fk.parent_object_id=OBJECT_ID(N'dbo.documents') AND fk.referenced_object_id=OBJECT_ID(N'dbo.document_versions') AND fk.name=N'FK_documents_current_version' AND fk.is_disabled=0 AND fk.is_not_trusted=0 AND ((fc.constraint_column_id=1 AND COL_NAME(fc.parent_object_id,fc.parent_column_id)=N'current_version_id' AND COL_NAME(fc.referenced_object_id,fc.referenced_column_id)=N'version_id') OR (fc.constraint_column_id=2 AND COL_NAME(fc.parent_object_id,fc.parent_column_id)=N'document_id' AND COL_NAME(fc.referenced_object_id,fc.referenced_column_id)=N'document_id')))<>2 THROW 51043,'Current-version foreign key is missing, malformed, disabled, or untrusted.',1;
IF (SELECT COUNT(*) FROM sys.check_constraints WHERE is_disabled=0 AND is_not_trusted=0 AND ((parent_object_id=OBJECT_ID(N'dbo.document_version_contents') AND name IN(N'CK_document_version_contents_hash',N'CK_document_version_contents_size')) OR (parent_object_id=OBJECT_ID(N'dbo.documents') AND name IN(N'CK_documents_version_capture_status',N'CK_documents_version_capture_error',N'CK_documents_version_capture_consistency'))))<>5 THROW 51044,'Version checks are missing, disabled, or untrusted.',1;
IF OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_version_contents_hash')) NOT LIKE '%content_hash%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_version_contents_size')) NOT LIKE '%157286400%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_version_contents_size')) NOT LIKE '%DATALENGTH%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_documents_version_capture_status')) NOT LIKE '%pending%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_documents_version_capture_status')) NOT LIKE '%ready%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_documents_version_capture_status')) NOT LIKE '%failed%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_documents_version_capture_error')) NOT LIKE '%version_capture_error_code%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_documents_version_capture_consistency')) NOT LIKE '%current_version_id%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_documents_version_capture_consistency')) NOT LIKE '%version_capture_status%' THROW 51045,'Version check definitions are malformed.',1;
IF (SELECT COUNT(*) FROM sys.triggers WHERE object_id IN(OBJECT_ID(N'dbo.TR_document_version_contents_validate'),OBJECT_ID(N'dbo.TR_document_version_contents_immutable')) AND parent_id=OBJECT_ID(N'dbo.document_version_contents') AND is_disabled=0)<>2 OR OBJECT_ID(N'dbo.TR_document_version_contents_validate',N'TR') IS NULL OR OBJECT_ID(N'dbo.TR_document_version_contents_immutable',N'TR') IS NULL OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.TR_document_version_contents_validate')) NOT LIKE '%LEFT JOIN%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.TR_document_version_contents_validate')) NOT LIKE '%document_versions%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.TR_document_version_contents_validate')) NOT LIKE '%HASHBYTES%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.TR_document_version_contents_immutable')) NOT LIKE '%INSTEAD OF UPDATE,DELETE%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.TR_document_version_contents_immutable')) NOT LIKE '%THROW%' THROW 51046,'Version triggers are missing, malformed, or disabled.',1;
IF (SELECT COUNT(*) FROM sys.objects WHERE object_id IN(OBJECT_ID(N'dbo.portal_prepare_document_version_capture'),OBJECT_ID(N'dbo.portal_capture_current_document_version'),OBJECT_ID(N'dbo.portal_mark_document_version_capture_failed'),OBJECT_ID(N'dbo.portal_list_document_versions'),OBJECT_ID(N'dbo.portal_read_document_version_content'),OBJECT_ID(N'dbo.portal_verify_document_version_contents')) AND type=N'P')<>6
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_prepare_document_version_capture')) NOT LIKE '%HASHBYTES%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_prepare_document_version_capture')) NOT LIKE '%version_capture_status%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version')) LIKE '%@actor_user_id%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version')) NOT LIKE '%UPDLOCK,HOLDLOCK%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version')) NOT LIKE '%HASHBYTES%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version')) NOT LIKE '%INSERT%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version')) NOT LIKE '%document_versions%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version')) NOT LIKE '%document_version_contents%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_mark_document_version_capture_failed')) NOT LIKE '%version_capture_status=''failed''%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_list_document_versions')) NOT LIKE '%d.content_hash=v.source_content_hash%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_read_document_version_content')) NOT LIKE '%HASHBYTES%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_read_document_version_content')) NOT LIKE '%content_bytes%'
 OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_verify_document_version_contents')) NOT LIKE '%LEFT JOIN%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_verify_document_version_contents')) NOT LIKE '%invalid_rows%'
 THROW 51047,'Version procedures are incomplete or malformed.',1;
GO
IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NULL
    THROW 51048,'Required TWWATER_PORTAL_APP principal is missing.',1;
DENY SELECT,INSERT,UPDATE,DELETE ON dbo.document_version_contents TO [TWWATER_PORTAL_APP];
DENY INSERT ON dbo.document_versions TO [TWWATER_PORTAL_APP];
GRANT EXECUTE ON dbo.portal_prepare_document_version_capture TO [TWWATER_PORTAL_APP];
GRANT EXECUTE ON dbo.portal_capture_current_document_version TO [TWWATER_PORTAL_APP];
GRANT EXECUTE ON dbo.portal_mark_document_version_capture_failed TO [TWWATER_PORTAL_APP];
GRANT EXECUTE ON dbo.portal_list_document_versions TO [TWWATER_PORTAL_APP];
GRANT EXECUTE ON dbo.portal_read_document_version_content TO [TWWATER_PORTAL_APP];
GRANT EXECUTE ON dbo.portal_verify_document_version_contents TO [TWWATER_PORTAL_APP];
GO
DECLARE @portal_principal INT=DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP');
IF (SELECT COUNT(*) FROM sys.database_permissions WHERE grantee_principal_id=@portal_principal AND class_desc='OBJECT_OR_COLUMN' AND major_id=OBJECT_ID(N'dbo.document_version_contents') AND state_desc='DENY' AND permission_name IN('SELECT','INSERT','UPDATE','DELETE'))<>4
 OR NOT EXISTS(SELECT 1 FROM sys.database_permissions WHERE grantee_principal_id=@portal_principal AND class_desc='OBJECT_OR_COLUMN' AND major_id=OBJECT_ID(N'dbo.document_versions') AND state_desc='DENY' AND permission_name='INSERT')
 OR (SELECT COUNT(*) FROM sys.database_permissions WHERE grantee_principal_id=@portal_principal AND class_desc='OBJECT_OR_COLUMN' AND state_desc='GRANT' AND permission_name='EXECUTE' AND major_id IN(OBJECT_ID(N'dbo.portal_prepare_document_version_capture'),OBJECT_ID(N'dbo.portal_capture_current_document_version'),OBJECT_ID(N'dbo.portal_mark_document_version_capture_failed'),OBJECT_ID(N'dbo.portal_list_document_versions'),OBJECT_ID(N'dbo.portal_read_document_version_content'),OBJECT_ID(N'dbo.portal_verify_document_version_contents')))<>6
    THROW 51049,'Version procedure-only permissions are incomplete or malformed.',1;
GO
IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'025_document_version_contents.sql') INSERT dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'025_document_version_contents.sql',NULL);
GO
COMMIT TRANSACTION;
GO
