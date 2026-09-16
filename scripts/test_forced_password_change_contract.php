<?php
declare(strict_types=1);
function forced_change_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$bootstrap=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');$auth=(string)file_get_contents(dirname(__DIR__).'/app/sqlite_auth.php');
foreach(["'change_password'",'portal_handle_change_password(','portal_sqlite_change_password('] as $token)forced_change_assert(str_contains($index,$token),'Forced change route missing: '.$token);
forced_change_assert(str_contains($bootstrap,'must_change_password'),'Session must carry forced-change state.');
forced_change_assert(str_contains($auth,'function portal_sqlite_change_password('),'Forced change service missing.');
echo "FORCED_PASSWORD_CHANGE_CONTRACT_OK\n";
