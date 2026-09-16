<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/portal_state.php';
function standalone_audit_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$p='C:\\TWWATER\\runtime\\portal-state\\standalone-audit-contract.sqlite';@unlink($p);@unlink($p.'-wal');@unlink($p.'-shm');try{$db=portal_open_state_database_at($p);portal_state_initialize_schema($db);portal_state_append_audit_event($db,null,'standalone_contract',null,[]);standalone_audit_assert((int)$db->query('SELECT COUNT(*) FROM auth_management_audit_events')->fetchColumn()===1,'Standalone audit event must commit exactly once.');portal_state_verify_audit_chain($db);}finally{unset($db);@unlink($p);@unlink($p.'-wal');@unlink($p.'-shm');}echo "STANDALONE_AUDIT_APPEND_SERVICE_OK\n";
