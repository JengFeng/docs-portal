<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
function archive_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$config=portal_config(false);$db=portal_open_database($config);
$schema=(int)$db->query("SELECT COUNT(*) FROM dbo.schema_migrations WHERE script_name='020_site_feedback_archiving.sql'")->fetchColumn();archive_assert($schema===1,'archive migration record missing or duplicated');
$objects=(int)$db->query("SELECT (CASE WHEN COL_LENGTH('dbo.site_feedback_batches','archived_at') IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN COL_LENGTH('dbo.site_feedback_batches','archived_by_user_id') IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.site_feedback_batches') AND name='IX_site_feedback_batches_archive_updated') THEN 1 ELSE 0 END)")->fetchColumn();archive_assert($objects===3,'archive schema objects incomplete');
$admin=$db->query("SELECT TOP (1) user_id,username,display_name,role_code FROM dbo.auth_users WHERE is_active=1 AND role_code='admin' ORDER BY user_id")->fetch();
$nonAdmin=['user_id'=>-1,'username'=>'fixture-non-owner','display_name'=>'Fixture non-owner','role_code'=>'reader'];
if(!$admin)throw new RuntimeException('Archive integration requires an active admin actor.');
$db->beginTransaction();
try{
    $created=portal_feedback_create_batch($db,$admin,['title'=>'[E2E] archive rollback '.gmdate('YmdHis')]);$id=(string)$created['batch']['public_id'];
    archive_assert(in_array($id,array_column(portal_feedback_list($db,$admin,false),'public_id'),true),'new batch missing from active history');
    try{portal_feedback_set_archived($db,$nonAdmin,['batch_id'=>$id],true);throw new RuntimeException('non-owner archived another user batch');}catch(PortalFeedbackHttpException $e){archive_assert($e->statusCode===404,'non-owner archive must fail without exposing the batch');}
    portal_feedback_set_archived($db,$admin,['batch_id'=>$id],true);
    $archived=portal_feedback_snapshot($db,$admin,$id);archive_assert($archived['archived_at']!==null,'archive timestamp missing');
    archive_assert(!in_array($id,array_column(portal_feedback_list($db,$admin,false),'public_id'),true),'archived batch leaked into active history');
    archive_assert(in_array($id,array_column(portal_feedback_list($db,$admin,true),'public_id'),true),'archived batch missing from archive history');
    try{portal_feedback_delete_item($db,$admin,['batch_id'=>$id,'item_id'=>'11111111-1111-4111-8111-111111111111']);throw new RuntimeException('archived mutation was accepted');}catch(PortalFeedbackHttpException $e){archive_assert($e->statusCode===409,'archived mutation did not fail with 409');}
    portal_feedback_set_archived($db,$admin,['batch_id'=>$id],false);
    archive_assert(in_array($id,array_column(portal_feedback_list($db,$admin,false),'public_id'),true),'restored batch missing from active history');
    archive_assert(!in_array($id,array_column(portal_feedback_list($db,$admin,true),'public_id'),true),'restored batch remained in archive history');
    $lookup=$db->prepare('SELECT batch_id FROM dbo.site_feedback_batches WHERE public_id=CONVERT(uniqueidentifier,?)');$lookup->execute([$id]);$internal=(int)$lookup->fetchColumn();$events=$db->prepare("SELECT event_type FROM dbo.site_feedback_events WHERE batch_id=? AND event_type IN ('feedback_batch_archived','feedback_batch_restored')");$events->execute([$internal]);$types=$events->fetchAll(PDO::FETCH_COLUMN);archive_assert(in_array('feedback_batch_archived',$types,true)&&in_array('feedback_batch_restored',$types,true),'archive audit events missing');
    $db->prepare("UPDATE dbo.site_feedback_batches SET status_code='queued_analysis' WHERE batch_id=?")->execute([$internal]);
    try{portal_feedback_set_archived($db,$admin,['batch_id'=>$id],true);throw new RuntimeException('processing batch was archived');}catch(PortalFeedbackHttpException $e){archive_assert($e->statusCode===409,'processing archive rejection did not use 409');}
    $db->rollBack();$prefix='[E2E] archive rollback ';$residue=$db->prepare('SELECT COUNT(*) FROM dbo.site_feedback_batches WHERE LEFT(title,LEN(?))=?');$residue->execute([$prefix,$prefix]);archive_assert((int)$residue->fetchColumn()===0,'archive integration left fixture rows');echo "[OK] feedback archive integration passed with rollback.\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
