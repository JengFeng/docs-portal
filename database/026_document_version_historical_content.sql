/* Controlled immutable content backfill for existing historical version metadata. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO
IF OBJECT_ID(N'dbo.document_version_contents',N'U') IS NULL OR OBJECT_ID(N'dbo.document_versions',N'U') IS NULL OR NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'025_document_version_contents.sql')
    THROW 51060,'Migration 025 must be active before 026.',1;
IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NULL
    THROW 51061,'Required TWWATER_PORTAL_APP principal is missing.',1;
GO
CREATE OR ALTER PROCEDURE dbo.portal_backfill_document_version_content
 @version_id BIGINT,@content_bytes VARBINARY(MAX)
AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @initial INT=@@TRANCOUNT;
 IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION backfill_version_content;
 BEGIN TRY
  DECLARE @hash CHAR(64),@size BIGINT;
  SELECT @hash=source_content_hash,@size=source_file_size_bytes FROM dbo.document_versions WITH(UPDLOCK,HOLDLOCK) WHERE version_id=@version_id;
  IF @hash IS NULL THROW 51062,'Historical version metadata does not exist.',1;
  IF @content_bytes IS NULL OR @size>157286400 OR CONVERT(BIGINT,DATALENGTH(@content_bytes))<>@size THROW 51063,'Historical version byte length mismatch.',1;
  DECLARE @actual CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',@content_bytes),2));
  IF @actual<>LOWER(@hash) THROW 51064,'Historical version hash mismatch.',1;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_version_contents WITH(UPDLOCK,HOLDLOCK) WHERE version_id=@version_id)
    INSERT dbo.document_version_contents(version_id,content_hash,file_size_bytes,content_bytes) VALUES(@version_id,@hash,@size,@content_bytes);
  ELSE IF EXISTS(SELECT 1 FROM dbo.document_version_contents WHERE version_id=@version_id AND (LOWER(content_hash)<>LOWER(@hash) OR file_size_bytes<>@size OR CONVERT(BIGINT,DATALENGTH(content_bytes))<>@size OR LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',content_bytes),2))<>LOWER(@hash)))
    THROW 51065,'Existing historical version content conflicts.',1;
  IF @initial=0 COMMIT TRANSACTION;
  SELECT @version_id version_id,@actual content_hash,@size file_size_bytes;
 END TRY
 BEGIN CATCH
  IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION;ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION backfill_version_content;THROW;
 END CATCH
END;
GO
IF OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_backfill_document_version_content')) NOT LIKE '%UPDLOCK,HOLDLOCK%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_backfill_document_version_content')) NOT LIKE '%HASHBYTES%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_backfill_document_version_content')) NOT LIKE '%document_version_contents%'
    THROW 51066,'Historical backfill procedure is malformed.',1;
GRANT EXECUTE ON dbo.portal_backfill_document_version_content TO [TWWATER_PORTAL_APP];
GO
DECLARE @portal INT=DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP');
IF NOT EXISTS(SELECT 1 FROM sys.database_permissions WHERE grantee_principal_id=@portal AND class_desc='OBJECT_OR_COLUMN' AND major_id=OBJECT_ID(N'dbo.portal_backfill_document_version_content') AND permission_name='EXECUTE' AND state_desc='GRANT')
    THROW 51067,'Historical backfill permission is missing.',1;
IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'026_document_version_historical_content.sql') INSERT dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'026_document_version_historical_content.sql',NULL);
GO
COMMIT TRANSACTION;
GO
