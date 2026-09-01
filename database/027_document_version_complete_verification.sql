/* Make version-content verification prove complete metadata/content set parity. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO
IF OBJECT_ID(N'dbo.document_version_contents',N'U') IS NULL OR OBJECT_ID(N'dbo.document_versions',N'U') IS NULL OR NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'026_document_version_historical_content.sql')
    THROW 51070,'Migrations 025 and 026 must be active before 027.',1;
GO
CREATE OR ALTER PROCEDURE dbo.portal_verify_document_version_contents AS
BEGIN
 SET NOCOUNT ON;
 SELECT COUNT(*) version_rows,
        SUM(CASE WHEN c.version_id IS NULL THEN 0 ELSE 1 END) content_rows,
        ISNULL(SUM(c.file_size_bytes),0) total_content_bytes,
        SUM(CASE WHEN c.version_id IS NULL THEN 1 ELSE 0 END) missing_rows,
        SUM(CASE WHEN c.version_id IS NOT NULL AND (LOWER(v.source_content_hash)<>LOWER(c.content_hash) OR v.source_file_size_bytes<>c.file_size_bytes OR CONVERT(BIGINT,DATALENGTH(c.content_bytes))<>c.file_size_bytes OR LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',c.content_bytes),2))<>LOWER(c.content_hash)) THEN 1 ELSE 0 END) invalid_rows
 FROM dbo.document_versions v LEFT JOIN dbo.document_version_contents c ON c.version_id=v.version_id;
 SELECT v.version_id FROM dbo.document_versions v LEFT JOIN dbo.document_version_contents c ON c.version_id=v.version_id WHERE c.version_id IS NULL ORDER BY v.version_id;
END;
GO
IF OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_verify_document_version_contents')) NOT LIKE '%FROM dbo.document_versions v LEFT JOIN dbo.document_version_contents%' OR OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_verify_document_version_contents')) NOT LIKE '%missing_rows%'
    THROW 51071,'Complete-history verifier definition is malformed.',1;
GRANT EXECUTE ON dbo.portal_verify_document_version_contents TO [TWWATER_PORTAL_APP];
IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'027_document_version_complete_verification.sql') INSERT dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'027_document_version_complete_verification.sql',NULL);
GO
COMMIT TRANSACTION;
GO
