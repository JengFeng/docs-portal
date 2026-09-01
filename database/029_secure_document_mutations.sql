/* Secure same-path document replacement operations and reversible soft archive. Dormant until applied. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
BEGIN TRY
 IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'028_document_version_500mib_limit.sql') THROW 51090,'Migration 028 is required.',1;
 IF OBJECT_ID(N'dbo.document_mutation_operations',N'U') IS NULL
 BEGIN
  CREATE TABLE dbo.document_mutation_operations(
   operation_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_document_mutation_operations PRIMARY KEY,
   document_id BIGINT NOT NULL,
   operation_type VARCHAR(16) NOT NULL CONSTRAINT CK_document_mutation_operation_type CHECK(operation_type IN('replace')),
   status_code VARCHAR(16) NOT NULL CONSTRAINT CK_document_mutation_status CHECK(status_code IN('prepared','replaced','completed','conflict','failed')),
   expected_hash CHAR(64) NOT NULL, candidate_hash CHAR(64) NOT NULL, candidate_size BIGINT NOT NULL,
   actual_hash CHAR(64) NULL, error_code VARCHAR(64) NULL, requested_by_user_id BIGINT NOT NULL,
   requested_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_mutation_requested DEFAULT(SYSUTCDATETIME()),
   replaced_at DATETIME2(3) NULL, completed_at DATETIME2(3) NULL, updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_mutation_updated DEFAULT(SYSUTCDATETIME()),
   rowver ROWVERSION NOT NULL,
   CONSTRAINT FK_document_mutation_document FOREIGN KEY(document_id) REFERENCES dbo.documents(document_id),
   CONSTRAINT FK_document_mutation_user FOREIGN KEY(requested_by_user_id) REFERENCES dbo.auth_users(user_id),
   CONSTRAINT CK_document_mutation_hashes CHECK(expected_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(expected_hash)=64 AND candidate_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(candidate_hash)=64 AND (actual_hash IS NULL OR (actual_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(actual_hash)=64))),
   CONSTRAINT CK_document_mutation_size CHECK(candidate_size BETWEEN 1 AND 524288000),
   CONSTRAINT CK_document_mutation_error CHECK(error_code IS NULL OR (LEN(error_code) BETWEEN 3 AND 64 AND error_code NOT LIKE '%[^A-Z0-9_]%' ))
  );
  CREATE UNIQUE INDEX UX_document_mutation_operations_active ON dbo.document_mutation_operations(document_id) WHERE status_code IN('prepared','replaced');
  CREATE TABLE dbo.document_mutation_events(
   event_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_document_mutation_events PRIMARY KEY,
   operation_id UNIQUEIDENTIFIER NOT NULL, status_code VARCHAR(16) NOT NULL, event_code VARCHAR(64) NOT NULL,
   actor_user_id BIGINT NULL, occurred_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_mutation_event_time DEFAULT(SYSUTCDATETIME()),
   CONSTRAINT FK_document_mutation_event_operation FOREIGN KEY(operation_id) REFERENCES dbo.document_mutation_operations(operation_id),
   CONSTRAINT UQ_document_mutation_event UNIQUE(operation_id,event_code)
  );
 END;
 IF COL_LENGTH(N'dbo.documents',N'archive_previous_status_code') IS NULL
 BEGIN
  ALTER TABLE dbo.documents ADD archive_previous_status_code VARCHAR(16) NULL, archived_by_user_id BIGINT NULL, archived_at DATETIME2(3) NULL, archive_reason VARCHAR(32) NULL;
  ALTER TABLE dbo.documents ADD CONSTRAINT FK_documents_archived_by FOREIGN KEY(archived_by_user_id) REFERENCES dbo.auth_users(user_id);
 END;

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_prepare_document_replacement
 @operation_id UNIQUEIDENTIFIER,@document_id BIGINT,@expected_hash CHAR(64),@candidate_hash CHAR(64),@candidate_size BIGINT,@requested_by_user_id BIGINT AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_prepare; BEGIN TRY
  DECLARE @current CHAR(64),@status VARCHAR(16),@capture VARCHAR(8),@version_hash CHAR(64); SELECT @current=d.content_hash,@status=d.status_code,@capture=d.version_capture_status,@version_hash=v.source_content_hash FROM dbo.documents d WITH(UPDLOCK,HOLDLOCK) LEFT JOIN dbo.document_versions v ON v.version_id=d.current_version_id AND v.document_id=d.document_id WHERE d.document_id=@document_id;
  IF @current IS NULL OR LOWER(@current)<>LOWER(@expected_hash) OR @status=''archived'' OR @capture<>''ready'' OR LOWER(@version_hash)<>LOWER(@expected_hash) THROW 51091,''DOCUMENT_CONFLICT'',1;
  IF @candidate_size NOT BETWEEN 1 AND 524288000 OR @candidate_hash LIKE ''%[^0-9A-Fa-f]%'' OR LEN(@candidate_hash)<>64 OR LOWER(@candidate_hash)=LOWER(@expected_hash) THROW 51092,''CANDIDATE_INVALID'',1;
  INSERT dbo.document_mutation_operations(operation_id,document_id,operation_type,status_code,expected_hash,candidate_hash,candidate_size,requested_by_user_id) VALUES(@operation_id,@document_id,''replace'',''prepared'',LOWER(@expected_hash),LOWER(@candidate_hash),@candidate_size,@requested_by_user_id);
  INSERT dbo.document_mutation_events(operation_id,status_code,event_code,actor_user_id) VALUES(@operation_id,''prepared'',''INTENT_PREPARED'',@requested_by_user_id);
  IF @initial=0 COMMIT TRANSACTION; SELECT CONVERT(varchar(36),@operation_id) operation_id;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_prepare; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_mark_document_replaced @operation_id UNIQUEIDENTIFIER,@actual_hash CHAR(64),@actual_size BIGINT,@source_modified_utc DATETIME2(3) AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_mark; BEGIN TRY
  DECLARE @document_id BIGINT,@expected CHAR(64),@candidate CHAR(64),@size BIGINT;
  SELECT @document_id=document_id,@expected=expected_hash,@candidate=candidate_hash,@size=candidate_size FROM dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) WHERE operation_id=@operation_id AND status_code=''prepared'';
  IF @document_id IS NULL OR LOWER(@candidate)<>LOWER(@actual_hash) OR @size<>@actual_size THROW 51093,''REPLACEMENT_RESULT_INVALID'',1;
  UPDATE dbo.documents SET content_hash=LOWER(@actual_hash),file_size_bytes=@actual_size,source_modified_at=@source_modified_utc,current_version_id=NULL,version_capture_status=''pending'',version_capture_error_code=NULL,version_capture_updated_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE document_id=@document_id AND LOWER(content_hash)=LOWER(@expected);
  IF @@ROWCOUNT<>1 THROW 51094,''DOCUMENT_CONFLICT'',1;
  UPDATE dbo.document_mutation_operations SET status_code=''replaced'',actual_hash=LOWER(@actual_hash),replaced_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id;
  INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''replaced'',''FILE_REPLACED''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_mark; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_complete_document_replacement @operation_id UNIQUEIDENTIFIER,@actual_hash CHAR(64) AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_complete; BEGIN TRY
  DECLARE @document BIGINT,@candidate CHAR(64); SELECT @document=document_id,@candidate=candidate_hash FROM dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) WHERE operation_id=@operation_id AND status_code=''replaced'';
  IF @document IS NULL OR LOWER(@candidate)<>LOWER(@actual_hash) OR NOT EXISTS(SELECT 1 FROM dbo.documents d JOIN dbo.document_versions v ON v.version_id=d.current_version_id AND v.document_id=d.document_id JOIN dbo.document_version_contents c ON c.version_id=v.version_id WHERE d.document_id=@document AND d.version_capture_status=''ready'' AND LOWER(d.content_hash)=LOWER(@actual_hash) AND LOWER(v.source_content_hash)=LOWER(@actual_hash) AND LOWER(c.content_hash)=LOWER(@actual_hash) AND c.file_size_bytes=d.file_size_bytes AND DATALENGTH(c.content_bytes)=d.file_size_bytes) THROW 51095,''IMMUTABLE_VERSION_NOT_READY'',1;
  UPDATE dbo.document_mutation_operations SET status_code=''completed'',completed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id;
  INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''completed'',''IMMUTABLE_VERSION_READY''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_complete; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_fail_document_replacement @operation_id UNIQUEIDENTIFIER,@error_code VARCHAR(64) AS
 BEGIN SET NOCOUNT ON; IF @error_code LIKE ''%[^A-Z0-9_]%'' OR LEN(@error_code) NOT BETWEEN 3 AND 64 THROW 51096,''ERROR_CODE_INVALID'',1;
  UPDATE dbo.document_mutation_operations SET status_code=''failed'',error_code=@error_code,updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id AND status_code=''prepared'';
  IF @@ROWCOUNT=1 AND NOT EXISTS(SELECT 1 FROM dbo.document_mutation_events WHERE operation_id=@operation_id AND event_code=''FAILED'') INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''failed'',''FAILED''); END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_list_active_document_mutations @limit INT=10 AS BEGIN SET NOCOUNT ON; IF @limit NOT BETWEEN 1 AND 10 SET @limit=10; SELECT TOP(@limit) CONVERT(varchar(36),o.operation_id) operation_id,o.status_code,LOWER(o.expected_hash) expected_hash,LOWER(o.candidate_hash) candidate_hash,o.candidate_size,o.requested_at,d.relative_path,d.file_name,d.extension FROM dbo.document_mutation_operations o JOIN dbo.documents d ON d.document_id=o.document_id WHERE o.status_code IN(''prepared'',''replaced'') ORDER BY o.updated_at,o.operation_id; END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_soft_archive_document @document_id BIGINT,@expected_hash CHAR(64),@actor_user_id BIGINT AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_archive; BEGIN TRY DECLARE @status VARCHAR(16);
  SELECT @status=status_code FROM dbo.documents WITH(UPDLOCK,HOLDLOCK) WHERE document_id=@document_id AND LOWER(content_hash)=LOWER(@expected_hash);
  IF @status IS NULL OR @status=''archived'' OR EXISTS(SELECT 1 FROM dbo.document_mutation_operations WHERE document_id=@document_id AND status_code IN(''prepared'',''replaced'')) THROW 51097,''DOCUMENT_CONFLICT'',1;
  UPDATE dbo.documents SET archive_previous_status_code=@status,status_code=''archived'',archived_by_user_id=@actor_user_id,archived_at=SYSUTCDATETIME(),archive_reason=''user-soft-archive'',published_at=NULL,updated_by_user_id=@actor_user_id,updated_at=SYSUTCDATETIME() WHERE document_id=@document_id;
  INSERT dbo.document_audit_events(event_type,outcome,actor_user_id,document_id,detail) VALUES(''document_soft_archive'',''accepted'',@actor_user_id,@document_id,''bytes-and-versions-preserved''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_archive; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_restore_archived_document @document_id BIGINT,@actor_user_id BIGINT AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_restore; BEGIN TRY DECLARE @previous VARCHAR(16),@reason VARCHAR(32);
  SELECT @previous=archive_previous_status_code,@reason=archive_reason FROM dbo.documents WITH(UPDLOCK,HOLDLOCK) WHERE document_id=@document_id AND status_code=''archived'';
  IF @previous IS NULL OR @reason<>''user-soft-archive'' THROW 51098,''NOT_USER_ARCHIVED'',1;
  UPDATE dbo.documents SET status_code=CASE WHEN @previous IN(''draft'',''published'') THEN @previous ELSE ''draft'' END,published_at=CASE WHEN @previous=''published'' THEN SYSUTCDATETIME() ELSE NULL END,archive_previous_status_code=NULL,archived_by_user_id=NULL,archived_at=NULL,archive_reason=NULL,updated_by_user_id=@actor_user_id,updated_at=SYSUTCDATETIME() WHERE document_id=@document_id;
  INSERT dbo.document_audit_events(event_type,outcome,actor_user_id,document_id,detail) VALUES(''document_soft_restore'',''accepted'',@actor_user_id,@document_id,''physical-bytes-unchanged''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_restore; THROW; END CATCH END');

 DENY INSERT,UPDATE,DELETE ON dbo.document_mutation_operations TO [TWWATER_PORTAL_APP];
 DENY INSERT,UPDATE,DELETE ON dbo.document_mutation_events TO [TWWATER_PORTAL_APP];
 GRANT SELECT ON dbo.document_mutation_operations TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_prepare_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_mark_document_replaced TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_complete_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_fail_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_list_active_document_mutations TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_soft_archive_document TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_restore_archived_document TO [TWWATER_PORTAL_APP];
 IF OBJECT_ID(N'dbo.document_mutation_operations',N'U') IS NULL OR OBJECT_ID(N'dbo.document_mutation_events',N'U') IS NULL OR (SELECT COUNT(*) FROM sys.objects WHERE object_id IN(OBJECT_ID(N'dbo.portal_prepare_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replaced'),OBJECT_ID(N'dbo.portal_complete_document_replacement'),OBJECT_ID(N'dbo.portal_fail_document_replacement'),OBJECT_ID(N'dbo.portal_list_active_document_mutations'),OBJECT_ID(N'dbo.portal_soft_archive_document'),OBJECT_ID(N'dbo.portal_restore_archived_document')) AND type=N'P')<>7 THROW 51099,'MUTATION_SCHEMA_INCOMPLETE',1;
 IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'UX_document_mutation_operations_active' AND is_unique=1 AND has_filter=1 AND is_disabled=0) THROW 51100,'MUTATION_ACTIVE_INDEX_INVALID',1;
 IF (SELECT COUNT(*) FROM sys.database_permissions WHERE grantee_principal_id=DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') AND class_desc='OBJECT_OR_COLUMN' AND permission_name='EXECUTE' AND state_desc='GRANT' AND major_id IN(OBJECT_ID(N'dbo.portal_prepare_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replaced'),OBJECT_ID(N'dbo.portal_complete_document_replacement'),OBJECT_ID(N'dbo.portal_fail_document_replacement'),OBJECT_ID(N'dbo.portal_list_active_document_mutations'),OBJECT_ID(N'dbo.portal_soft_archive_document'),OBJECT_ID(N'dbo.portal_restore_archived_document')))<>7 THROW 51101,'MUTATION_PERMISSIONS_INVALID',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'029_secure_document_mutations.sql') INSERT dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'029_secure_document_mutations.sql',NULL);
 COMMIT TRANSACTION;
END TRY
BEGIN CATCH IF XACT_STATE()<>0 ROLLBACK; THROW; END CATCH;
GO
