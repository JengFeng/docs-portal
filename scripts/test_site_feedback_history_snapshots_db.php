<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
function history_snapshot_fail(string $message):never{fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}
$database=portal_open_database(portal_config());
$database->beginTransaction();
register_shutdown_function(static function()use($database):void{if($database->inTransaction())$database->rollBack();});
$user=['user_id'=>0,'role_code'=>'admin'];
foreach([false,true] as $archived){
    $rows=portal_feedback_list($database,$user,$archived);
    if(count($rows)>50)history_snapshot_fail('history list exceeded bounded limit');
    foreach($rows as $row){
        if(array_key_exists('batch_id',$row))history_snapshot_fail('internal batch id leaked into history state');
        if(!is_array($row['snapshots']??null))history_snapshot_fail('history row lacks snapshot array');
        $count=$database->prepare("SELECT COUNT(*) FROM dbo.site_feedback_snapshots s JOIN dbo.site_feedback_batches b ON b.batch_id=s.batch_id AND b.revision_number=s.revision_number WHERE b.public_id=CONVERT(uniqueidentifier,?)");
        $count->execute([(string)$row['public_id']]);
        if((int)$count->fetchColumn()!==count($row['snapshots']))history_snapshot_fail('attached snapshot count differs from independent current-revision query');
        foreach($row['snapshots'] as $snapshot){
            if(!in_array((string)($snapshot['snapshot_kind']??''),['before','after'],true))history_snapshot_fail('unexpected snapshot kind');
            if((int)($snapshot['revision_number']??0)!==(int)$row['revision_number'])history_snapshot_fail('stale revision snapshot attached');
            if(!str_starts_with((string)($snapshot['image_url']??''),'?action=feedback_snapshot_image&id='))history_snapshot_fail('snapshot image did not retain private authenticated route');
        }
    }
}
$database->rollBack();
echo "[OK] history rows attach only current-revision private snapshot metadata without internal IDs.\n";
