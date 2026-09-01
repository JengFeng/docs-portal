/* Secure same-path document replacement operations and reversible soft archive. Dormant until applied. */
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
DECLARE @migration_initial_trancount INT=@@TRANCOUNT;
IF @migration_initial_trancount=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION migration029;
BEGIN TRY
 IF NOT EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'028_document_version_500mib_limit.sql') THROW 51090,'Migration 028 is required.',1;
 DECLARE @existing_checksum CHAR(64)=(SELECT checksum_sha256 FROM dbo.schema_migrations WHERE script_name=N'029_secure_document_mutations.sql');
 IF @existing_checksum IS NOT NULL
 BEGIN
  DECLARE @existing_payload NVARCHAR(MAX)=CONCAT(CAST(N'mutation-schema-v1|' AS NVARCHAR(MAX)),
   COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_prepare_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_claim_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_mark_document_replaced')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_complete_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_mark_document_replacement_backup_cleaned')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_fail_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_list_active_document_mutations')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_soft_archive_document')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_restore_archived_document')),N'<missing>'),N'|',
   COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_operation_type')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_status')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_hashes')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_size')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_error')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_execution_lease')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_backup_binding')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_backup_hash')),N'<missing>'),N'|',COALESCE((SELECT filter_definition FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'UX_document_mutation_operations_active'),N'<missing>'));
  DECLARE @existing_actual CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@existing_payload)),2));
  IF @existing_actual<>LOWER(@existing_checksum) THROW 51102,'MUTATION_SCHEMA_FINGERPRINT_MISMATCH',1;
  IF @migration_initial_trancount=0 COMMIT TRANSACTION; RETURN;
 END;
 IF EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'029_secure_document_mutations.sql') OR OBJECT_ID(N'dbo.document_mutation_operations',N'U') IS NOT NULL OR OBJECT_ID(N'dbo.document_mutation_events',N'U') IS NOT NULL OR OBJECT_ID(N'dbo.portal_prepare_document_replacement',N'P') IS NOT NULL OR COL_LENGTH(N'dbo.documents',N'archive_previous_status_code') IS NOT NULL THROW 51103,'PARTIAL_MUTATION_SCHEMA',1;
 IF OBJECT_ID(N'dbo.document_mutation_operations',N'U') IS NULL
 BEGIN
  CREATE TABLE dbo.document_mutation_operations(
   operation_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_document_mutation_operations PRIMARY KEY,
   document_id BIGINT NOT NULL,
   operation_type VARCHAR(16) NOT NULL CONSTRAINT CK_document_mutation_operation_type CHECK(operation_type IN('replace')),
   status_code VARCHAR(16) NOT NULL CONSTRAINT CK_document_mutation_status CHECK(status_code IN('prepared','replaced','completed','conflict','failed')),
   expected_hash CHAR(64) NOT NULL, candidate_hash CHAR(64) NOT NULL, candidate_size BIGINT NOT NULL,
   actual_hash CHAR(64) NULL, error_code VARCHAR(64) NULL, requested_by_user_id BIGINT NOT NULL,
   execution_token UNIQUEIDENTIFIER NULL, execution_lease_expires_at DATETIME2(3) NULL,
   backup_name NVARCHAR(320) NULL, backup_hash CHAR(64) NULL, backup_cleaned_at DATETIME2(3) NULL,
   requested_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_mutation_requested DEFAULT(SYSUTCDATETIME()),
   replaced_at DATETIME2(3) NULL, completed_at DATETIME2(3) NULL, updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_mutation_updated DEFAULT(SYSUTCDATETIME()),
   rowver ROWVERSION NOT NULL,
   CONSTRAINT FK_document_mutation_document FOREIGN KEY(document_id) REFERENCES dbo.documents(document_id),
   CONSTRAINT FK_document_mutation_user FOREIGN KEY(requested_by_user_id) REFERENCES dbo.auth_users(user_id),
   CONSTRAINT CK_document_mutation_hashes CHECK(expected_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(expected_hash)=64 AND candidate_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(candidate_hash)=64 AND (actual_hash IS NULL OR (actual_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(actual_hash)=64)) AND (backup_hash IS NULL OR (backup_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(backup_hash)=64))),
   CONSTRAINT CK_document_mutation_size CHECK(candidate_size BETWEEN 1 AND 524288000),
   CONSTRAINT CK_document_mutation_error CHECK(error_code IS NULL OR (LEN(error_code) BETWEEN 3 AND 64 AND error_code NOT LIKE '%[^A-Z0-9_]%')),
   CONSTRAINT CK_document_mutation_lease CHECK((execution_token IS NULL AND execution_lease_expires_at IS NULL) OR (execution_token IS NOT NULL AND execution_lease_expires_at IS NOT NULL)),
   CONSTRAINT CK_document_mutation_backup CHECK((backup_name IS NULL AND backup_hash IS NULL AND backup_cleaned_at IS NULL) OR (backup_name IS NOT NULL AND backup_hash IS NOT NULL))
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
 IF COL_LENGTH(N'dbo.document_mutation_operations',N'execution_token') IS NULL ALTER TABLE dbo.document_mutation_operations ADD execution_token UNIQUEIDENTIFIER NULL, execution_lease_expires_at DATETIME2(3) NULL;
 IF COL_LENGTH(N'dbo.document_mutation_operations',N'backup_name') IS NULL ALTER TABLE dbo.document_mutation_operations ADD backup_name NVARCHAR(320) NULL, backup_hash CHAR(64) NULL, backup_cleaned_at DATETIME2(3) NULL;
 IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'CK_document_mutation_execution_lease') ALTER TABLE dbo.document_mutation_operations ADD CONSTRAINT CK_document_mutation_execution_lease CHECK((execution_token IS NULL AND execution_lease_expires_at IS NULL) OR (execution_token IS NOT NULL AND execution_lease_expires_at IS NOT NULL));
 IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'CK_document_mutation_backup_binding') ALTER TABLE dbo.document_mutation_operations ADD CONSTRAINT CK_document_mutation_backup_binding CHECK((backup_name IS NULL AND backup_hash IS NULL AND backup_cleaned_at IS NULL) OR (backup_name IS NOT NULL AND backup_hash IS NOT NULL));
 IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'CK_document_mutation_backup_hash') ALTER TABLE dbo.document_mutation_operations ADD CONSTRAINT CK_document_mutation_backup_hash CHECK(backup_hash IS NULL OR (backup_hash NOT LIKE '%[^0-9A-Fa-f]%' AND LEN(backup_hash)=64));
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

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_claim_document_replacement @operation_id UNIQUEIDENTIFIER,@execution_token UNIQUEIDENTIFIER,@lease_seconds INT=300 AS
 BEGIN SET NOCOUNT ON; IF @lease_seconds NOT BETWEEN 60 AND 900 THROW 51102,''LEASE_INVALID'',1; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_claim; BEGIN TRY
  UPDATE dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) SET execution_token=@execution_token,execution_lease_expires_at=DATEADD(SECOND,@lease_seconds,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id AND status_code=''prepared'' AND (execution_token IS NULL OR execution_lease_expires_at<=SYSUTCDATETIME() OR execution_token=@execution_token);
  IF @@ROWCOUNT<>1 THROW 51103,''EXECUTION_ALREADY_CLAIMED'',1;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_mutation_events WHERE operation_id=@operation_id AND event_code=''EXECUTION_CLAIMED'') INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''prepared'',''EXECUTION_CLAIMED'');
  IF @initial=0 COMMIT TRANSACTION; SELECT CONVERT(varchar(36),@execution_token) execution_token;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_claim; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_mark_document_replaced @operation_id UNIQUEIDENTIFIER,@execution_token UNIQUEIDENTIFIER,@actual_hash CHAR(64),@actual_size BIGINT,@source_modified_utc DATETIME2(3),@backup_name NVARCHAR(320),@backup_hash CHAR(64) AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_mark; BEGIN TRY
  DECLARE @document_id BIGINT,@expected CHAR(64),@candidate CHAR(64),@size BIGINT,@status VARCHAR(16),@saved_actual CHAR(64),@saved_backup NVARCHAR(320),@saved_backup_hash CHAR(64);
  SELECT @document_id=document_id,@expected=expected_hash,@candidate=candidate_hash,@size=candidate_size,@status=status_code,@saved_actual=actual_hash,@saved_backup=backup_name,@saved_backup_hash=backup_hash FROM dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) WHERE operation_id=@operation_id AND status_code IN(''prepared'',''replaced'') AND execution_token=@execution_token;
  IF @document_id IS NULL OR LOWER(@candidate)<>LOWER(@actual_hash) OR @size<>@actual_size OR @backup_name IS NULL OR LEN(@backup_name)>320 OR @backup_name LIKE ''%[\\/]%'' OR LOWER(@backup_hash)<>LOWER(@expected) THROW 51093,''REPLACEMENT_RESULT_INVALID'',1;
  IF @status=''replaced'' BEGIN IF LOWER(@saved_actual)<>LOWER(@actual_hash) OR @saved_backup<>@backup_name OR LOWER(@saved_backup_hash)<>LOWER(@backup_hash) THROW 51093,''REPLACEMENT_RESULT_INVALID'',1; IF @initial=0 COMMIT TRANSACTION; RETURN; END;
  UPDATE dbo.documents SET content_hash=LOWER(@actual_hash),file_size_bytes=@actual_size,source_modified_at=@source_modified_utc,current_version_id=NULL,version_capture_status=''pending'',version_capture_error_code=NULL,version_capture_updated_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE document_id=@document_id AND LOWER(content_hash)=LOWER(@expected);
  IF @@ROWCOUNT<>1 THROW 51094,''DOCUMENT_CONFLICT'',1;
  UPDATE dbo.document_mutation_operations SET status_code=''replaced'',actual_hash=LOWER(@actual_hash),backup_name=@backup_name,backup_hash=LOWER(@backup_hash),replaced_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_mutation_events WHERE operation_id=@operation_id AND event_code=''FILE_REPLACED'') INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''replaced'',''FILE_REPLACED''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_mark; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_complete_document_replacement @operation_id UNIQUEIDENTIFIER,@actual_hash CHAR(64) AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_complete; BEGIN TRY
  DECLARE @document BIGINT,@candidate CHAR(64); SELECT @document=document_id,@candidate=candidate_hash FROM dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) WHERE operation_id=@operation_id AND status_code=''replaced'';
  IF @document IS NULL OR LOWER(@candidate)<>LOWER(@actual_hash) OR NOT EXISTS(SELECT 1 FROM dbo.documents d JOIN dbo.document_versions v ON v.version_id=d.current_version_id AND v.document_id=d.document_id JOIN dbo.document_version_contents c ON c.version_id=v.version_id WHERE d.document_id=@document AND d.version_capture_status=''ready'' AND LOWER(d.content_hash)=LOWER(@actual_hash) AND LOWER(v.source_content_hash)=LOWER(@actual_hash) AND LOWER(c.content_hash)=LOWER(@actual_hash) AND v.source_file_size_bytes=d.file_size_bytes AND c.file_size_bytes=d.file_size_bytes AND DATALENGTH(c.content_bytes)=d.file_size_bytes) THROW 51095,''IMMUTABLE_VERSION_NOT_READY'',1;
  UPDATE dbo.document_mutation_operations SET status_code=''completed'',completed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_mutation_events WHERE operation_id=@operation_id AND event_code=''IMMUTABLE_VERSION_READY'') INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''completed'',''IMMUTABLE_VERSION_READY''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_complete; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_mark_document_replacement_backup_cleaned @operation_id UNIQUEIDENTIFIER,@backup_name NVARCHAR(320),@backup_hash CHAR(64) AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_cleanup; BEGIN TRY
  UPDATE dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) SET backup_cleaned_at=COALESCE(backup_cleaned_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id AND status_code=''completed'' AND backup_name=@backup_name AND LOWER(backup_hash)=LOWER(@backup_hash);
  IF @@ROWCOUNT<>1 THROW 51104,''BACKUP_BINDING_INVALID'',1;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_mutation_events WHERE operation_id=@operation_id AND event_code=''BACKUP_CLEANED'') INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''completed'',''BACKUP_CLEANED''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_cleanup; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_fail_document_replacement @operation_id UNIQUEIDENTIFIER,@execution_token UNIQUEIDENTIFIER,@error_code VARCHAR(64) AS
 BEGIN SET NOCOUNT ON; IF @error_code LIKE ''%[^A-Z0-9_]%'' OR LEN(@error_code) NOT BETWEEN 3 AND 64 THROW 51096,''ERROR_CODE_INVALID'',1; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_fail; BEGIN TRY
  UPDATE dbo.document_mutation_operations WITH(UPDLOCK,HOLDLOCK) SET status_code=''failed'',error_code=@error_code,updated_at=SYSUTCDATETIME() WHERE operation_id=@operation_id AND status_code=''prepared'' AND execution_token=@execution_token;
  IF @@ROWCOUNT<>1 THROW 51105,''FAIL_FENCE_INVALID'',1;
  IF NOT EXISTS(SELECT 1 FROM dbo.document_mutation_events WHERE operation_id=@operation_id AND event_code=''FAILED'') INSERT dbo.document_mutation_events(operation_id,status_code,event_code) VALUES(@operation_id,''failed'',''FAILED''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_fail; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_list_active_document_mutations @limit INT=10 AS BEGIN SET NOCOUNT ON; IF @limit NOT BETWEEN 1 AND 10 SET @limit=10; SELECT TOP(@limit) CONVERT(varchar(36),o.operation_id) operation_id,o.status_code,LOWER(o.expected_hash) expected_hash,LOWER(o.candidate_hash) candidate_hash,o.candidate_size,o.requested_at,CONVERT(varchar(36),o.execution_token) execution_token,o.execution_lease_expires_at,o.backup_name,LOWER(o.backup_hash) backup_hash,o.backup_cleaned_at,d.relative_path,d.file_name,d.extension FROM dbo.document_mutation_operations o JOIN dbo.documents d ON d.document_id=o.document_id WHERE o.status_code IN(''prepared'',''replaced'') OR (o.status_code=''completed'' AND o.backup_name IS NOT NULL AND o.backup_cleaned_at IS NULL) ORDER BY o.updated_at,o.operation_id; END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_soft_archive_document @document_id BIGINT,@expected_hash CHAR(64),@actor_user_id BIGINT AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_archive; BEGIN TRY DECLARE @status VARCHAR(16);
  SELECT @status=status_code FROM dbo.documents WITH(UPDLOCK,HOLDLOCK) WHERE document_id=@document_id AND LOWER(content_hash)=LOWER(@expected_hash);
  IF @status IS NULL OR @status=''archived'' OR EXISTS(SELECT 1 FROM dbo.document_mutation_operations WHERE document_id=@document_id AND status_code IN(''prepared'',''replaced'')) THROW 51097,''DOCUMENT_CONFLICT'',1;
  UPDATE dbo.documents SET archive_previous_status_code=@status,status_code=''archived'',archived_by_user_id=@actor_user_id,archived_at=SYSUTCDATETIME(),archive_reason=''user-soft-archive'',published_at=NULL,updated_by_user_id=@actor_user_id,updated_at=SYSUTCDATETIME() WHERE document_id=@document_id;
  INSERT dbo.document_audit_events(event_type,outcome,actor_user_id,document_id,detail) VALUES(''document_soft_archive'',''accepted'',@actor_user_id,@document_id,''bytes-and-versions-preserved''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_archive; THROW; END CATCH END');

 EXEC(N'CREATE OR ALTER PROCEDURE dbo.portal_restore_archived_document @document_id BIGINT,@actor_user_id BIGINT,@expected_hash CHAR(64),@expected_size BIGINT AS
 BEGIN SET NOCOUNT ON; DECLARE @initial INT=@@TRANCOUNT; IF @initial=0 BEGIN TRANSACTION; ELSE SAVE TRANSACTION mutation_restore; BEGIN TRY DECLARE @previous VARCHAR(16),@reason VARCHAR(32);
  SELECT @previous=d.archive_previous_status_code,@reason=d.archive_reason FROM dbo.documents d WITH(UPDLOCK,HOLDLOCK) WHERE d.document_id=@document_id AND d.status_code=''archived'' AND LOWER(d.content_hash)=LOWER(@expected_hash) AND d.file_size_bytes=@expected_size AND d.version_capture_status=''ready'' AND EXISTS(SELECT 1 FROM dbo.document_versions v JOIN dbo.document_version_contents c ON c.version_id=v.version_id WHERE v.version_id=d.current_version_id AND v.document_id=d.document_id AND LOWER(v.source_content_hash)=LOWER(@expected_hash) AND LOWER(c.content_hash)=LOWER(@expected_hash) AND v.source_file_size_bytes=@expected_size AND c.file_size_bytes=@expected_size AND DATALENGTH(c.content_bytes)=@expected_size);
  IF @previous IS NULL OR @reason<>''user-soft-archive'' THROW 51098,''NOT_USER_ARCHIVED'',1;
  UPDATE dbo.documents SET status_code=CASE WHEN @previous IN(''draft'',''published'') THEN @previous ELSE ''draft'' END,published_at=CASE WHEN @previous=''published'' THEN SYSUTCDATETIME() ELSE NULL END,archive_previous_status_code=NULL,archived_by_user_id=NULL,archived_at=NULL,archive_reason=NULL,updated_by_user_id=@actor_user_id,updated_at=SYSUTCDATETIME() WHERE document_id=@document_id;
  INSERT dbo.document_audit_events(event_type,outcome,actor_user_id,document_id,detail) VALUES(''document_soft_restore'',''accepted'',@actor_user_id,@document_id,''physical-bytes-and-immutable-current-binding-verified''); IF @initial=0 COMMIT TRANSACTION;
 END TRY BEGIN CATCH IF @initial=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION; ELSE IF @initial>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION mutation_restore; THROW; END CATCH END');

 DENY INSERT,UPDATE,DELETE ON dbo.document_mutation_operations TO [TWWATER_PORTAL_APP];
 DENY INSERT,UPDATE,DELETE ON dbo.document_mutation_events TO [TWWATER_PORTAL_APP];
 GRANT SELECT ON dbo.document_mutation_operations TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_prepare_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_claim_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_mark_document_replaced TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_complete_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_mark_document_replacement_backup_cleaned TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_fail_document_replacement TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_list_active_document_mutations TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_soft_archive_document TO [TWWATER_PORTAL_APP];
 GRANT EXECUTE ON dbo.portal_restore_archived_document TO [TWWATER_PORTAL_APP];
 IF OBJECT_ID(N'dbo.document_mutation_operations',N'U') IS NULL OR OBJECT_ID(N'dbo.document_mutation_events',N'U') IS NULL OR (SELECT COUNT(*) FROM sys.objects WHERE object_id IN(OBJECT_ID(N'dbo.portal_prepare_document_replacement'),OBJECT_ID(N'dbo.portal_claim_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replaced'),OBJECT_ID(N'dbo.portal_complete_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replacement_backup_cleaned'),OBJECT_ID(N'dbo.portal_fail_document_replacement'),OBJECT_ID(N'dbo.portal_list_active_document_mutations'),OBJECT_ID(N'dbo.portal_soft_archive_document'),OBJECT_ID(N'dbo.portal_restore_archived_document')) AND type=N'P')<>9 THROW 51099,'MUTATION_SCHEMA_INCOMPLETE',1;
 IF COL_LENGTH(N'dbo.document_mutation_operations',N'execution_token') IS NULL OR COL_LENGTH(N'dbo.document_mutation_operations',N'execution_lease_expires_at') IS NULL OR COL_LENGTH(N'dbo.document_mutation_operations',N'backup_name') IS NULL OR COL_LENGTH(N'dbo.document_mutation_operations',N'backup_hash') IS NULL OR COL_LENGTH(N'dbo.document_mutation_operations',N'backup_cleaned_at') IS NULL THROW 51106,'MUTATION_COLUMNS_INCOMPLETE',1;
 IF (SELECT COUNT(*) FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND ((name=N'execution_token' AND system_type_id=36 AND max_length=16 AND is_nullable=1) OR (name IN(N'execution_lease_expires_at',N'backup_cleaned_at') AND system_type_id=42 AND scale=3 AND is_nullable=1) OR (name=N'backup_name' AND system_type_id=231 AND max_length=640 AND is_nullable=1) OR (name=N'backup_hash' AND system_type_id=175 AND max_length=64 AND is_nullable=1)))<>5 THROW 51107,'MUTATION_COLUMN_TYPES_INVALID',1;
 IF (SELECT COUNT(*) FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name IN(N'CK_document_mutation_execution_lease',N'CK_document_mutation_backup_binding',N'CK_document_mutation_backup_hash'))<>3 THROW 51108,'MUTATION_CONSTRAINTS_INVALID',1;
 IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'UX_document_mutation_operations_active' AND is_unique=1 AND has_filter=1 AND is_disabled=0 AND filter_definition LIKE N'%prepared%' AND filter_definition LIKE N'%replaced%' AND filter_definition NOT LIKE N'%completed%') THROW 51100,'MUTATION_ACTIVE_INDEX_INVALID',1;
 IF (SELECT COUNT(*) FROM sys.database_permissions WHERE grantee_principal_id=DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') AND class_desc='OBJECT_OR_COLUMN' AND permission_name='EXECUTE' AND state_desc='GRANT' AND major_id IN(OBJECT_ID(N'dbo.portal_prepare_document_replacement'),OBJECT_ID(N'dbo.portal_claim_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replaced'),OBJECT_ID(N'dbo.portal_complete_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replacement_backup_cleaned'),OBJECT_ID(N'dbo.portal_fail_document_replacement'),OBJECT_ID(N'dbo.portal_list_active_document_mutations'),OBJECT_ID(N'dbo.portal_soft_archive_document'),OBJECT_ID(N'dbo.portal_restore_archived_document')))<>9 THROW 51101,'MUTATION_PERMISSIONS_INVALID',1;
 IF (SELECT COUNT(*) FROM sys.database_permissions WHERE grantee_principal_id=DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') AND class_desc='OBJECT_OR_COLUMN' AND permission_name IN('INSERT','UPDATE','DELETE') AND state_desc='DENY' AND major_id IN(OBJECT_ID(N'dbo.document_mutation_operations'),OBJECT_ID(N'dbo.document_mutation_events')))<>6 THROW 51109,'MUTATION_DENIES_INVALID',1;
 DECLARE @schema_payload NVARCHAR(MAX)=CONCAT(CAST(N'mutation-schema-v1|' AS NVARCHAR(MAX)),
  COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_prepare_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_claim_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_mark_document_replaced')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_complete_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_mark_document_replacement_backup_cleaned')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_fail_document_replacement')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_list_active_document_mutations')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_soft_archive_document')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.portal_restore_archived_document')),N'<missing>'),N'|',
  COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_operation_type')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_status')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_hashes')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_size')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_error')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_execution_lease')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_backup_binding')),N'<missing>'),N'|',COALESCE(OBJECT_DEFINITION(OBJECT_ID(N'dbo.CK_document_mutation_backup_hash')),N'<missing>'),N'|',COALESCE((SELECT filter_definition FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'UX_document_mutation_operations_active'),N'<missing>'));
 DECLARE @schema_hash CHAR(64)=LOWER(CONVERT(CHAR(64),HASHBYTES('SHA2_256',CONVERT(VARBINARY(MAX),@schema_payload)),2));
 IF @schema_hash IS NULL OR @schema_payload LIKE N'%<missing>%' THROW 51104,'MUTATION_SCHEMA_FINGERPRINT_INCOMPLETE',1;
 INSERT dbo.schema_migrations(script_name,checksum_sha256) VALUES(N'029_secure_document_mutations.sql',@schema_hash);
 IF @migration_initial_trancount=0 COMMIT TRANSACTION;
END TRY
BEGIN CATCH
 IF @migration_initial_trancount=0 AND XACT_STATE()<>0 ROLLBACK TRANSACTION;
 ELSE IF @migration_initial_trancount>0 AND XACT_STATE()=1 ROLLBACK TRANSACTION migration029;
 THROW;
END CATCH;
GO
