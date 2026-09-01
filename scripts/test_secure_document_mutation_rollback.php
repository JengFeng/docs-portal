<?php
declare(strict_types=1);
function rollback_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/database/029_secure_document_mutations.sql');
rollback_assert(str_contains($migration, 'SET QUOTED_IDENTIFIER ON;'), 'migration must explicitly enable QUOTED_IDENTIFIER for filtered index creation under sqlcmd');
$source=(string)file_get_contents($root.'/app/document_mutations.php');
$index=(string)file_get_contents($root.'/index.php');
rollback_assert(str_contains($migration,'@migration_initial_trancount')&&str_contains($migration,'SAVE TRANSACTION migration029')&&str_contains($migration,'ROLLBACK TRANSACTION migration029')&&str_contains($migration,'BEGIN CATCH'),'migration must preserve and rollback caller-owned transactions safely');
rollback_assert(str_contains($migration,"status_code IN(''prepared'',''replaced'')"),'one-active lifecycle filter missing');
rollback_assert(str_contains($migration,"status_code=''prepared''")&&str_contains($migration,"status_code=''replaced''")&&str_contains($migration,"status_code=''completed''"),'guarded replacement transitions missing');
rollback_assert(str_contains($migration,"status_code=''failed''")&&str_contains($migration,"status_code=''prepared'' AND execution_token=@execution_token"),'only a token-fenced intent may terminalize as failed');
rollback_assert(str_contains($source,'else continue;'),'ambiguous publication must retain its fence');
rollback_assert(str_contains($source,'if(!$mutationAttempted')&&str_contains($source,'PREPARE_FAILED'),'pre-mutation failures must close intent');
rollback_assert(str_contains($source,"hash_equals((string)\$row['candidate_hash'],\$liveHash)")&&str_contains($source,"hash_equals((string)\$row['expected_hash'],\$liveHash)"),'recovery must compare both generations');
rollback_assert(str_contains($migration,"archive_previous_status_code=@status")&&str_contains($migration,"archive_reason=''user-soft-archive''"),'soft archive must retain reversible state');
rollback_assert(str_contains($migration,"@reason<>''user-soft-archive''")&&str_contains($migration,'physical-bytes-and-immutable-current-binding-verified'),'restore must reject scanner archives and verify physical/immutable bytes');
rollback_assert(str_contains($source,"getenv('PORTAL_DOCUMENT_MUTATIONS_ENABLED') !== '1'")&&str_contains($source,'static $enabled'),'production activation gate must default off and be request-scoped');
rollback_assert(!str_contains($migration,'DELETE FROM dbo.documents')&&!str_contains($migration,'DELETE dbo.documents'),'document deletion is forbidden');
echo "[OK] Mutation rollback/crash-window fixture contracts passed.\n";
