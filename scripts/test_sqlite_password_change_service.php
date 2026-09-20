<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';
require_once dirname(__DIR__) . '/app/sqlite_auth.php';
function password_change_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$path='C:\\TWWATER\\runtime\\portal-state\\password-change-contract.sqlite';@unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
try{$db=portal_open_state_database_at($path);portal_state_initialize_schema($db);$admin=portal_initialize_first_admin($db,'admin-user','Admin User','Initial-Password-2026');$changed=portal_sqlite_change_password($db,(int)$admin['user_id'],'Initial-Password-2026','Changed-Password-2026');password_change_assert((int)$changed['must_change_password']===0,'Successful password change must clear forced-change state.');$authenticated=portal_sqlite_authenticate_local($db,'admin-user','Changed-Password-2026');password_change_assert($authenticated['user_id']===$admin['user_id'],'Changed password must authenticate.');}finally{unset($db);@unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');}echo "SQLITE_PASSWORD_CHANGE_SERVICE_OK\n";
