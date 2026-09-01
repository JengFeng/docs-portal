<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
function m021_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function m021_batches(PDO $db,string $sql):void{
    $sql=preg_replace('/^\s*SET\s+XACT_ABORT\s+ON\s*;?\s*$/mi','',$sql)??$sql;
    $sql=preg_replace('/^\s*(BEGIN|COMMIT)\s+TRANSACTION\s*;?\s*$/mi','',$sql)??$sql;
    foreach(preg_split('/^\s*GO\s*$/mi',$sql)?:[] as $batch){if(trim($batch)!=='')$db->exec($batch);}
}
function m021_parent_fixture(PDO $db,string $schema,string $role):void{
    $db->exec("CREATE SCHEMA [$schema]");
    $db->exec("CREATE ROLE [$role]");
    $db->exec("CREATE TABLE [$schema].documents(document_id BIGINT NOT NULL PRIMARY KEY)");
    $db->exec("CREATE TABLE [$schema].auth_users(user_id BIGINT NOT NULL PRIMARY KEY)");
    $db->exec("CREATE TABLE [$schema].staged_document_revisions(staged_revision_id BIGINT NOT NULL PRIMARY KEY)");
    $db->exec("CREATE TABLE [$schema].document_version_operations(operation_id UNIQUEIDENTIFIER NOT NULL PRIMARY KEY,operation_type VARCHAR(24) NOT NULL,CONSTRAINT CK_document_version_operations_type CHECK(operation_type IN('restore')))");
    $db->exec("CREATE TABLE [$schema].schema_migrations(script_name NVARCHAR(255) NOT NULL PRIMARY KEY,checksum_sha256 CHAR(64) NULL)");
}
function m021_partial_fixture(PDO $db,string $schema):void{
    $db->exec("CREATE TABLE [$schema].image_revision_jobs(
      image_revision_job_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_image_revision_jobs PRIMARY KEY,
      public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_image_revision_jobs_public_id DEFAULT(NEWID()),document_id BIGINT NOT NULL,
      source_content_hash CHAR(64) NOT NULL,request_revision INT NOT NULL CONSTRAINT DF_image_revision_jobs_revision DEFAULT(1),annotations_json NVARCHAR(MAX) NOT NULL,request_sha256 CHAR(64) NOT NULL,
      candidate_sha256 CHAR(64) NULL,candidate_extension VARCHAR(10) NULL,candidate_size_bytes BIGINT NULL,candidate_width INT NULL,candidate_height INT NULL,staged_revision_id BIGINT NULL,
      status_code VARCHAR(24) NOT NULL CONSTRAINT DF_image_revision_jobs_status DEFAULT('queued'),error_code VARCHAR(64) NULL,created_by_user_id BIGINT NOT NULL,confirmed_by_user_id BIGINT NULL,
      queued_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_revision_jobs_queued DEFAULT(SYSUTCDATETIME()),processing_at DATETIME2(3) NULL,candidate_ready_at DATETIME2(3) NULL,confirmed_at DATETIME2(3) NULL,
      created_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_revision_jobs_created DEFAULT(SYSUTCDATETIME()),updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_image_revision_jobs_updated DEFAULT(SYSUTCDATETIME()),rowver ROWVERSION NOT NULL,
      CONSTRAINT UQ_image_revision_jobs_public_id UNIQUE(public_id),CONSTRAINT FK_image_revision_jobs_document FOREIGN KEY(document_id) REFERENCES [$schema].documents(document_id),
      CONSTRAINT FK_image_revision_jobs_staged FOREIGN KEY(staged_revision_id) REFERENCES [$schema].staged_document_revisions(staged_revision_id),CONSTRAINT FK_image_revision_jobs_creator FOREIGN KEY(created_by_user_id) REFERENCES [$schema].auth_users(user_id),
      CONSTRAINT FK_image_revision_jobs_confirmer FOREIGN KEY(confirmed_by_user_id) REFERENCES [$schema].auth_users(user_id),CONSTRAINT CK_image_revision_jobs_source_hash CHECK(source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
      CONSTRAINT CK_image_revision_jobs_request_hash CHECK(request_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),CONSTRAINT CK_image_revision_jobs_candidate_hash CHECK(candidate_sha256 IS NULL OR candidate_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
      CONSTRAINT CK_image_revision_jobs_revision CHECK(request_revision BETWEEN 1 AND 1000000),CONSTRAINT CK_image_revision_jobs_annotations CHECK(ISJSON(annotations_json)=1 AND DATALENGTH(annotations_json) BETWEEN 2 AND 524288),
      CONSTRAINT CK_image_revision_jobs_candidate_extension CHECK(candidate_extension IS NULL OR candidate_extension IN('png','jpg','jpeg','webp')),CONSTRAINT CK_image_revision_jobs_candidate_size CHECK(candidate_size_bytes IS NULL OR candidate_size_bytes BETWEEN 1 AND 52428800),
      CONSTRAINT CK_image_revision_jobs_candidate_dimensions CHECK((candidate_width IS NULL AND candidate_height IS NULL) OR(candidate_width BETWEEN 1 AND 10000 AND candidate_height BETWEEN 1 AND 10000)),
      CONSTRAINT CK_image_revision_jobs_status CHECK(status_code IN('queued','processing','candidate_ready','confirming','confirmed','conflict','failed','cancelled')))" );
}
function m021_verify(PDO $db,string $schema,string $role):void{
    $object=$schema.'.image_revision_jobs';
    $expected=['image_revision_job_id','public_id','document_id','source_content_hash','request_revision','annotations_json','request_sha256','candidate_sha256','candidate_extension','candidate_size_bytes','candidate_width','candidate_height','staged_revision_id','version_operation_id','status_code','error_code','created_by_user_id','confirmed_by_user_id','queued_at','processing_at','candidate_ready_at','confirmed_at','created_at','updated_at','rowver'];
    $q=$db->prepare("SELECT name FROM sys.columns WHERE object_id=OBJECT_ID(?) ORDER BY column_id");$q->execute([$object]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);sort($expected);sort($actual);m021_assert($actual===$expected,"$schema columns mismatch");
    $q=$db->prepare("SELECT COUNT(*) FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(?)");$q->execute([$object]);m021_assert((int)$q->fetchColumn()===5,"$schema foreign keys mismatch");
    $q=$db->prepare("SELECT COUNT(*) FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(?)");$q->execute([$object]);m021_assert((int)$q->fetchColumn()===9,"$schema checks mismatch");
    $q=$db->prepare("SELECT name,has_filter,is_unique FROM sys.indexes WHERE object_id=OBJECT_ID(?) AND name IN('IX_image_revision_jobs_document_created','IX_image_revision_jobs_status_updated','UX_image_revision_jobs_active_document','UX_image_revision_jobs_version_operation')");$q->execute([$object]);$indexes=$q->fetchAll();m021_assert(count($indexes)===4,"$schema indexes mismatch");$map=[];foreach($indexes as $row)$map[$row['name']]=$row;m021_assert((int)$map['UX_image_revision_jobs_active_document']['has_filter']===1&&(int)$map['UX_image_revision_jobs_active_document']['is_unique']===1,"$schema active filtered unique missing");m021_assert((int)$map['UX_image_revision_jobs_version_operation']['has_filter']===1&&(int)$map['UX_image_revision_jobs_version_operation']['is_unique']===1,"$schema operation filtered unique missing");
    $q=$db->prepare("SELECT COUNT(*) FROM [$schema].schema_migrations WHERE script_name='021_image_revision_workflow.sql'");$q->execute();m021_assert((int)$q->fetchColumn()===1,"$schema migration row mismatch");
    if((int)$db->query("SELECT CASE WHEN DATABASE_PRINCIPAL_ID(N'$role') IS NULL THEN 0 ELSE 1 END")->fetchColumn()===1){$testUser='u_'.$schema;$db->exec("CREATE USER [$testUser] WITHOUT LOGIN");$db->exec("ALTER ROLE [$role] ADD MEMBER [$testUser]");$db->exec("EXECUTE AS USER='$testUser'");try{foreach(['SELECT','INSERT','UPDATE'] as $permission){$q=$db->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', ?)");$q->execute([$object,$permission]);m021_assert((int)$q->fetchColumn()===1,"$schema $permission effective grant missing");}$q=$db->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', 'DELETE')");$q->execute([$object]);m021_assert((int)$q->fetchColumn()===0,"$schema DELETE must be explicitly denied/effectively unavailable");}finally{$db->exec('REVERT');}}
    $q=$db->prepare("SELECT definition FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(?) AND name='CK_document_version_operations_type'");$q->execute([$schema.'.document_version_operations']);m021_assert(str_contains((string)$q->fetchColumn(),"'publish'"),"$schema publish operation check missing");
}
$db=portal_open_database(portal_config(false));$migration=(string)file_get_contents(dirname(__DIR__).'/database/021_image_revision_workflow.sql');$suffix=substr(bin2hex(random_bytes(8)),0,12);$schemas=['m021f_'.$suffix,'m021p_'.$suffix];
$db->beginTransaction();
try{
 foreach($schemas as $index=>$schema){$role='r_'.$schema;m021_parent_fixture($db,$schema,$role);if($index===1)m021_partial_fixture($db,$schema);$fixture=str_replace(['dbo.','TWWATER_PORTAL_APP'],['['.$schema.'].',$role],$migration);m021_batches($db,$fixture);m021_batches($db,$fixture);m021_verify($db,$schema,$role);}
}finally{if($db->inTransaction())$db->rollBack();}
foreach($schemas as $schema){$q=$db->prepare("SELECT (SELECT COUNT(*) FROM sys.schemas WHERE name=?)+(SELECT COUNT(*) FROM sys.database_principals WHERE name IN(?,?))");$q->execute([$schema,'r_'.$schema,'u_'.$schema]);m021_assert((int)$q->fetchColumn()===0,"$schema fixture residue remains after rollback");}
echo "[OK] migration 021 fresh and partial fixtures apply twice with exact schema, constraints, filtered indexes, grants and deny; transaction rolled back with zero fixture residue.\n";
