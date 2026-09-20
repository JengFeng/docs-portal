<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/sqlite_backup.php';
function retention_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root='C:\\TWWATER\\runtime\\database-backups\\sqlite\\retention-contract';@mkdir($root,0770,true);
try { foreach (glob($root.'\\*')?:[] as $f)@unlink($f); $now=strtotime('2026-09-15 12:00:00 UTC');
    for($i=0;$i<20;$i++){ $date=gmdate('Ymd_His',$now-$i*86400); file_put_contents($root.'\\twwater-portal-daily-'.$date.'.sqlite','x'); file_put_contents($root.'\\twwater-portal-daily-'.$date.'.sqlite.json','{}'); }
    for($i=0;$i<12;$i++){ $date=gmdate('Ymd_His',$now-$i*7*86400); file_put_contents($root.'\\twwater-portal-weekly-'.$date.'.sqlite','x'); file_put_contents($root.'\\twwater-portal-weekly-'.$date.'.sqlite.json','{}'); }
    portal_state_prune_backup_retention($root,14,8);
    retention_assert(count(glob($root.'\\twwater-portal-daily-*.sqlite')?:[])===14,'Daily retention must keep 14 backup databases.');
    retention_assert(count(glob($root.'\\twwater-portal-weekly-*.sqlite')?:[])===8,'Weekly retention must keep 8 backup databases.');
} finally {foreach(glob($root.'\\*')?:[] as $f)@unlink($f);@rmdir($root);} echo "SQLITE_BACKUP_RETENTION_CONTRACT_OK\n";
