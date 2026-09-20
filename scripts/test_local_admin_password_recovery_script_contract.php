<?php
declare(strict_types=1);
function recovery_script_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/scripts/reset_portal_admin_password.php');
foreach(['PHP_SAPI !== \'cli\'','isset($argv[2])','portal_open_existing_state_database()','portal_sqlite_login_mode_allows_local(','random_bytes(24)','portal_sqlite_reset_password($database, null,','RECOVERY_PASSWORD='] as $required)recovery_script_assert(str_contains($source,$required),'Recovery script missing: '.$required);
foreach(['$password = (string) ($argv','password=','$_POST'] as $forbidden)recovery_script_assert(!str_contains($source,$forbidden),'Recovery script must not accept a password as input: '.$forbidden);
echo "LOCAL_ADMIN_PASSWORD_RECOVERY_SCRIPT_CONTRACT_OK\n";
