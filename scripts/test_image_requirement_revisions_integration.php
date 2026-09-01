<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
function m023_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function m023_batches(PDO $db,string $sql):void{
    $sql=preg_replace('/^\s*SET\s+XACT_ABORT\s+ON\s*;?\s*$/mi','',$sql)??$sql;
    $sql=preg_replace('/^\s*(BEGIN|COMMIT)\s+TRANSACTION\s*;?\s*$/mi','',$sql)??$sql;
    foreach(preg_split('/^\s*GO\s*$/mi',$sql)?:[] as $batch)if(trim($batch)!=='')$db->exec($batch);
}
function m023_parents(PDO $db,string $schema,string $role):void{
    $db->exec("CREATE SCHEMA [$schema]");$db->exec("CREATE ROLE [$role]");
    $db->exec("CREATE TABLE [$schema].documents(document_id BIGINT NOT NULL PRIMARY KEY)");
    $db->exec("CREATE TABLE [$schema].auth_users(user_id BIGINT NOT NULL PRIMARY KEY)");
    $db->exec("CREATE TABLE [$schema].schema_migrations(script_name NVARCHAR(255) NOT NULL PRIMARY KEY,checksum_sha256 CHAR(64) NULL)");
}
function m023_partial(PDO $db,string $schema):void{$db->exec("CREATE TABLE [$schema].image_requirement_revisions(image_requirement_revision_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,public_id UNIQUEIDENTIFIER NOT NULL)");}
function m023_verify(PDO $db,string $schema,string $role,string $rejection):void{
    $object=$schema.'.image_requirement_revisions';
    $expected=['image_requirement_revision_id','public_id','document_id','source_content_hash','revision_number','requirement_json','prompt_text','item_count','status_code','created_by_user_id','created_at','completed_by_user_id','completed_at','archived_by_user_id','archived_at','rowver'];
    $q=$db->prepare('SELECT name FROM sys.columns WHERE object_id=OBJECT_ID(?) ORDER BY column_id');$q->execute([$object]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);m023_assert($actual===$expected,"$schema exact columns mismatch");
    $q=$db->prepare('SELECT COUNT(*) FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID(?)');$q->execute([$object]);m023_assert((int)$q->fetchColumn()===4,"$schema exact FK count mismatch");
    $q=$db->prepare('SELECT COUNT(*) FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(?)');$q->execute([$object]);m023_assert((int)$q->fetchColumn()===6,"$schema exact check count mismatch");
    $q=$db->prepare("SELECT name,is_unique,has_filter FROM sys.indexes WHERE object_id=OBJECT_ID(?) AND name IN('UQ_image_requirement_revisions_public_id','UQ_image_requirement_revisions_document_source_revision','IX_image_requirement_revisions_document_history','IX_image_requirement_revisions_current_source') ORDER BY name");$q->execute([$object]);$indexes=$q->fetchAll();m023_assert(count($indexes)===4,"$schema exact index set mismatch");
    $q=$db->prepare("SELECT COUNT(*) FROM sys.triggers WHERE parent_id=OBJECT_ID(?) AND name='TR_image_requirement_revisions_immutable'");$q->execute([$object]);m023_assert((int)$q->fetchColumn()===1,"$schema immutable-content trigger missing");
    $db->exec("INSERT INTO [$schema].documents VALUES(1);INSERT INTO [$schema].auth_users VALUES(1),(2)");
    $json='{"schemaVersion":"twwater-image-change-request-v1","annotations":[{"annotationId":"a"}]}';$prompt='identity hash annotation type instruction position geometry style safety';
    $insert=$db->prepare("INSERT INTO [$schema].image_requirement_revisions(document_id,source_content_hash,revision_number,requirement_json,prompt_text,item_count,created_by_user_id) VALUES(1,?,1,?,?,1,1)");$insert->execute([str_repeat('a',64),$json,$prompt]);
    $db->exec("UPDATE [$schema].image_requirement_revisions SET status_code='completed',completed_by_user_id=2,completed_at=SYSUTCDATETIME() WHERE revision_number=1");
    $db->exec("UPDATE [$schema].image_requirement_revisions SET archived_by_user_id=2,archived_at=SYSUTCDATETIME() WHERE revision_number=1");
    $db->exec("UPDATE [$schema].image_requirement_revisions SET archived_by_user_id=NULL,archived_at=NULL WHERE revision_number=1");
    $db->exec("EXECUTE AS USER='u_$schema'");try{$q=$db->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', 'DELETE')");$q->execute([$object]);m023_assert((int)$q->fetchColumn()===0,"$schema physical DELETE permission must be denied");}finally{$db->exec('REVERT');}
    $q=$db->prepare("SELECT COUNT(*) FROM [$schema].schema_migrations WHERE script_name='023_image_requirement_revisions.sql'");$q->execute();m023_assert((int)$q->fetchColumn()===1,"$schema migration ledger mismatch");
    $rejected=false;try{if($rejection==='content')$db->exec("UPDATE [$schema].image_requirement_revisions SET prompt_text='changed' WHERE revision_number=1");else $db->exec("UPDATE [$schema].image_requirement_revisions SET status_code='ready',completed_by_user_id=NULL,completed_at=NULL WHERE revision_number=1");}catch(Throwable){$rejected=true;}m023_assert($rejected,"$schema immutable content/completion reversal was accepted");
}
$db=portal_open_database(portal_config(false));$path=dirname(__DIR__).'/database/023_image_requirement_revisions.sql';$migration=is_file($path)?(string)file_get_contents($path):'';m023_assert($migration!=='','023 migration file is missing');$suffix=substr(bin2hex(random_bytes(8)),0,12);$cases=['m023c_'.$suffix=>'content','m023r_'.$suffix=>'reverse','m023w_'.$suffix=>'wrong'];
foreach($cases as $schema=>$case){$role='r_'.$schema;$db->beginTransaction();try{m023_parents($db,$schema,$role);$db->exec("CREATE USER [u_$schema] WITHOUT LOGIN;ALTER ROLE [$role] ADD MEMBER [u_$schema]");if($case==='wrong')m023_partial($db,$schema);$fixture=str_replace(['dbo.','[TWWATER_PORTAL_APP]','TWWATER_PORTAL_APP'],['['.$schema.'].','['.$role.']',$role],$migration);m023_assert(!str_contains($fixture,'dbo.')&&!str_contains($fixture,'TWWATER_PORTAL_APP'),'fixture transformation leaked production schema/role token');if($case==='wrong'){$failedClosed=false;try{m023_batches($db,$fixture);}catch(Throwable){$failedClosed=true;}m023_assert($failedClosed,'wrong same-name schema did not fail closed');}else{m023_batches($db,$fixture);m023_batches($db,$fixture);m023_verify($db,$schema,$role,$case);}}finally{if($db->inTransaction())$db->rollBack();}$q=$db->prepare("SELECT (SELECT COUNT(*) FROM sys.schemas WHERE name=?)+(SELECT COUNT(*) FROM sys.database_principals WHERE name IN(?,?))");$q->execute([$schema,$role,'u_'.$schema]);m023_assert((int)$q->fetchColumn()===0,"$schema schema/role/user residue remains");}
echo "[OK] migration 023 fresh/repeat exact validation, wrong-schema fail-closed, immutability/lifecycle, least privilege, rollback and zero residue passed.\n";
