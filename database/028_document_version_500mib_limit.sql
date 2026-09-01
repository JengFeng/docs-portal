/* Raise immutable document-version limit from 150 MiB to 500 MiB. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
BEGIN TRY
    IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'027_document_version_complete_verification.sql') OR OBJECT_ID(N'dbo.document_version_contents',N'U') IS NULL
        THROW 51080,'Migration 027 must be active before 028.',1;
    IF EXISTS(SELECT 1 FROM dbo.document_version_contents WHERE file_size_bytes>524288000 OR CONVERT(BIGINT,DATALENGTH(content_bytes))>524288000)
        THROW 51081,'Existing version content exceeds the proposed 500 MiB limit.',1;

    DECLARE @ledger INT=(SELECT COUNT(*) FROM dbo.schema_migrations WHERE script_name=N'028_document_version_500mib_limit.sql');
    DECLARE @constraint NVARCHAR(MAX)=OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_version_contents_size'));
    DECLARE @capture NVARCHAR(MAX)=OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version'));
    DECLARE @historical NVARCHAR(MAX)=OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_backfill_document_version_content'));
    DECLARE @portal INT=DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP');
    DECLARE @constraint_hash CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@constraint)),2));
    DECLARE @capture_hash CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@capture)),2));
    DECLARE @historical_hash CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@historical)),2));
    DECLARE @is_dbo BIT=CASE WHEN OBJECT_SCHEMA_NAME(OBJECT_ID(N'dbo.portal_capture_current_document_version'))=N'dbo' AND OBJECT_SCHEMA_NAME(OBJECT_ID(N'dbo.portal_backfill_document_version_content'))=N'dbo' THEN 1 ELSE 0 END;
    IF @constraint IS NULL OR @capture IS NULL OR @historical IS NULL OR @portal IS NULL OR @ledger NOT IN(0,1)
        THROW 51082,'Document limit migration prerequisites are incomplete.',1;

    IF @ledger=1
    BEGIN
        DECLARE @post_invalid BIT=0;
        IF (LEN(@constraint)-LEN(REPLACE(@constraint,N'524288000',N'')))/LEN(N'524288000')<>1 SET @post_invalid=1;
        IF @constraint LIKE '%157286400%' OR @constraint NOT LIKE '%DATALENGTH%' SET @post_invalid=1;
        IF (LEN(@capture)-LEN(REPLACE(@capture,N'524288000',N'')))/LEN(N'524288000')<>1 SET @post_invalid=1;
        IF @capture LIKE '%157286400%' OR @capture NOT LIKE '%UPDLOCK,HOLDLOCK%' OR @capture NOT LIKE '%HASHBYTES%' OR @capture NOT LIKE '%document_version_contents%' OR @capture NOT LIKE '%version_capture_status%' SET @post_invalid=1;
        IF (LEN(@historical)-LEN(REPLACE(@historical,N'524288000',N'')))/LEN(N'524288000')<>1 SET @post_invalid=1;
        IF @historical LIKE '%157286400%' OR @historical NOT LIKE '%UPDLOCK,HOLDLOCK%' OR @historical NOT LIKE '%HASHBYTES%' OR @historical NOT LIKE '%document_version_contents%' SET @post_invalid=1;
        IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.document_version_contents') AND name=N'CK_document_version_contents_size' AND (is_disabled=1 OR is_not_trusted=1)) SET @post_invalid=1;
        IF NOT EXISTS(SELECT 1 FROM sys.database_permissions WHERE grantee_principal_id=@portal AND class_desc='OBJECT_OR_COLUMN' AND major_id=OBJECT_ID(N'dbo.portal_capture_current_document_version') AND permission_name='EXECUTE' AND state_desc='GRANT') SET @post_invalid=1;
        IF NOT EXISTS(SELECT 1 FROM sys.database_permissions WHERE grantee_principal_id=@portal AND class_desc='OBJECT_OR_COLUMN' AND major_id=OBJECT_ID(N'dbo.portal_backfill_document_version_content') AND permission_name='EXECUTE' AND state_desc='GRANT') SET @post_invalid=1;
        IF @is_dbo=1 AND (@constraint_hash<>'ec73c1150e3f1cd24600b84c007c2794b648d02073ba2f6c0ee829702ea15be0' OR @capture_hash<>'c1291b053635bb4f593909aa078e03e8980cbefe8c403754c5657cc5c700c081' OR @historical_hash<>'8a4982924eb04279b6f74afbacbe8f78ec1dec005a319ced1831ee6cf1f458fe') SET @post_invalid=1;
        IF @post_invalid=1 THROW 51083,'Existing 500 MiB migration state is incomplete or drifted.',1;
        COMMIT TRANSACTION;
        RETURN;
    END;

    DECLARE @pre_invalid BIT=0;
    IF (LEN(@constraint)-LEN(REPLACE(@constraint,N'157286400',N'')))/LEN(N'157286400')<>1 SET @pre_invalid=1;
    IF @constraint LIKE '%524288000%' OR @constraint NOT LIKE '%DATALENGTH%' SET @pre_invalid=1;
    IF (LEN(@capture)-LEN(REPLACE(@capture,N'157286400',N'')))/LEN(N'157286400')<>1 SET @pre_invalid=1;
    IF @capture LIKE '%524288000%' OR @capture NOT LIKE '%UPDLOCK,HOLDLOCK%' OR @capture NOT LIKE '%HASHBYTES%' OR @capture NOT LIKE '%document_versions%' OR @capture NOT LIKE '%document_version_contents%' OR @capture NOT LIKE '%version_capture_status%' SET @pre_invalid=1;
    IF (LEN(@historical)-LEN(REPLACE(@historical,N'157286400',N'')))/LEN(N'157286400')<>1 SET @pre_invalid=1;
    IF @historical LIKE '%524288000%' OR @historical NOT LIKE '%UPDLOCK,HOLDLOCK%' OR @historical NOT LIKE '%HASHBYTES%' OR @historical NOT LIKE '%document_versions%' OR @historical NOT LIKE '%document_version_contents%' SET @pre_invalid=1;
    IF @is_dbo=1 AND (@constraint_hash<>'0b37d66586838961e3d9c5e21f8810b409ecce498080483b28cb7b4fac7536f9' OR @capture_hash<>'2fa3c689cd658c51ac0041a4f5b047c062c9ec7016002df933080f58eb6cc7a7' OR @historical_hash<>'0d98df542c808150ed7e895e2dbc9314bd40731b6bffb3b813800d2dac606a06') SET @pre_invalid=1;
    IF @pre_invalid=1 THROW 51084,'Pre-028 procedure or constraint definition is incomplete or drifted.',1;

    ALTER TABLE dbo.document_version_contents DROP CONSTRAINT CK_document_version_contents_size;
    ALTER TABLE dbo.document_version_contents WITH CHECK ADD CONSTRAINT CK_document_version_contents_size CHECK(file_size_bytes BETWEEN 0 AND 524288000 AND CONVERT(BIGINT,DATALENGTH(content_bytes))=file_size_bytes);
    ALTER TABLE dbo.document_version_contents CHECK CONSTRAINT CK_document_version_contents_size;

    SET @capture=N'ALTER PROCEDURE'+SUBSTRING(@capture,CHARINDEX(N'PROCEDURE',@capture)+LEN(N'PROCEDURE'),LEN(@capture));
    SET @historical=N'ALTER PROCEDURE'+SUBSTRING(@historical,CHARINDEX(N'PROCEDURE',@historical)+LEN(N'PROCEDURE'),LEN(@historical));
    SET @capture=REPLACE(@capture,N'157286400',N'524288000');
    SET @historical=REPLACE(@historical,N'157286400',N'524288000');
    EXEC sys.sp_executesql @capture;
    EXEC sys.sp_executesql @historical;

    SET @constraint=OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_version_contents_size'));
    SET @capture=OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_capture_current_document_version'));
    SET @historical=OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_backfill_document_version_content'));
    SET @constraint_hash=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@constraint)),2));
    SET @capture_hash=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@capture)),2));
    SET @historical_hash=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@historical)),2));
    DECLARE @applied_invalid BIT=0;
    IF (LEN(@constraint)-LEN(REPLACE(@constraint,N'524288000',N'')))/LEN(N'524288000')<>1 OR @constraint LIKE '%157286400%' SET @applied_invalid=1;
    IF (LEN(@capture)-LEN(REPLACE(@capture,N'524288000',N'')))/LEN(N'524288000')<>1 OR @capture LIKE '%157286400%' SET @applied_invalid=1;
    IF (LEN(@historical)-LEN(REPLACE(@historical,N'524288000',N'')))/LEN(N'524288000')<>1 OR @historical LIKE '%157286400%' SET @applied_invalid=1;
    IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.document_version_contents') AND name=N'CK_document_version_contents_size' AND (is_disabled=1 OR is_not_trusted=1)) SET @applied_invalid=1;
    IF @is_dbo=1 AND (@constraint_hash<>'ec73c1150e3f1cd24600b84c007c2794b648d02073ba2f6c0ee829702ea15be0' OR @capture_hash<>'c1291b053635bb4f593909aa078e03e8980cbefe8c403754c5657cc5c700c081' OR @historical_hash<>'8a4982924eb04279b6f74afbacbe8f78ec1dec005a319ced1831ee6cf1f458fe') SET @applied_invalid=1;
    IF @applied_invalid=1 THROW 51085,'500 MiB content limit did not apply exactly.',1;

    INSERT dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'028_document_version_500mib_limit.sql',NULL);
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
