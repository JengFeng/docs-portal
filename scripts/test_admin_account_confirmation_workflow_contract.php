<?php
declare(strict_types=1);
function account_workflow_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$pages=(string)file_get_contents(dirname(__DIR__).'/app/admin_pages.php');$auth=(string)file_get_contents(dirname(__DIR__).'/app/sqlite_auth.php');
foreach(["'admin_account_confirm'","'admin_account_reset_password'",'portal_render_admin_account_confirmation(','portal_handle_admin_account_confirmation(','portal_handle_admin_account_reset_password('] as $required)account_workflow_assert(str_contains($index.$pages,$required),'Account workflow missing: '.$required);
account_workflow_assert(str_contains($auth,'function portal_sqlite_reset_password('),'Password reset service missing.');
account_workflow_assert(str_contains($pages,'old/new impact summary'),'Confirmation must render an old/new impact summary.');
echo "ADMIN_ACCOUNT_CONFIRMATION_WORKFLOW_CONTRACT_OK\n";
