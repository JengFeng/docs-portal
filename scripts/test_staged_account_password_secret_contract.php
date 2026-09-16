<?php
declare(strict_types=1);
function staged_secret_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pages=(string)file_get_contents(dirname(__DIR__).'/app/admin_pages.php');$accounts=(string)file_get_contents(dirname(__DIR__).'/app/sqlite_accounts.php');
staged_secret_assert(str_contains($pages,"unset(\$input['password'])"),'Create-password plaintext must be removed before session staging.');
staged_secret_assert(str_contains($pages,"'initial_password'=>'已提供，不顯示'"),'Create confirmation must mask its initial password.');
staged_secret_assert(str_contains($accounts,'staged_password_hash'),'Account creation must accept only a staged password hash after confirmation.');
echo "STAGED_ACCOUNT_PASSWORD_SECRET_CONTRACT_OK\n";
