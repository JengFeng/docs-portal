<?php
declare(strict_types=1);
function staged_reset_secret_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pages=(string)file_get_contents(dirname(__DIR__).'/app/admin_pages.php');$auth=(string)file_get_contents(dirname(__DIR__).'/app/sqlite_auth.php');
staged_reset_secret_assert(str_contains($pages,"unset(\$input['new_password'])"),'Reset-password plaintext must be removed before session staging.');
staged_reset_secret_assert(str_contains($pages,"'staged_password_hash'"),'Reset confirmation must call the staged-hash reset service.');
staged_reset_secret_assert(str_contains($auth,'function portal_sqlite_reset_password_with_hash('),'Reset service must support a staged password hash.');
echo "STAGED_PASSWORD_RESET_SECRET_CONTRACT_OK\n";
