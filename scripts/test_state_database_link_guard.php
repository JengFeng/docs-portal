<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/portal_state.php';
function state_path_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/app/portal_state.php');
state_path_assert(str_contains($source,'is_link($path)'), 'State opener must reject a linked database file before PDO opens it.');
$target='C:\\TWWATER\\runtime\\portal-state\\state-link-target.sqlite';$link='C:\\TWWATER\\runtime\\portal-state\\state-link-contract.sqlite';@unlink($target);@unlink($link);try{file_put_contents($target,'not-a-database');if(!@symlink($target,$link)){echo "STATE_DB_LINK_GUARD_SKIPPED\n";exit(0);}try{portal_open_state_database_at($link);throw new RuntimeException('Linked SQLite file must not open.');}catch(RuntimeException $e){state_path_assert($e->getMessage()==='SQLite 狀態資料庫路徑無效。','Linked SQLite file must be rejected before opening.');}}finally{@unlink($link);@unlink($target);}echo "STATE_DB_LINK_GUARD_OK\n";
