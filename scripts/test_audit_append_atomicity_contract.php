<?php
declare(strict_types=1);
function audit_atomic_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$state=(string)file_get_contents(dirname(__DIR__).'/app/portal_state.php');$index=(string)file_get_contents(dirname(__DIR__).'/index.php');
audit_atomic_assert(str_contains($state,"\$database->exec('BEGIN IMMEDIATE')"),'Standalone audit append must acquire an immediate write transaction.');
audit_atomic_assert(str_contains($state,'$ownsTransaction'),'Audit append must not commit a caller transaction.');
audit_atomic_assert(str_contains($index,'function portal_handle_logout') && str_contains($index,'$database->beginTransaction();try{portal_state_append_audit_event('),'Logout audit must be transactionally protected.');
echo "AUDIT_APPEND_ATOMICITY_CONTRACT_OK\n";
