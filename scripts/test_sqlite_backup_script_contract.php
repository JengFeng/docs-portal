<?php
declare(strict_types=1);
function backup_script_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/scripts/backup_portal_state.php');
foreach(['PHP_SAPI !== \'cli\'','is_file(PORTAL_STATE_SQLITE_PATH)','portal_state_create_verified_backup(','portal_state_prune_backup_retention('] as $token)backup_script_assert(str_contains($source,$token),'Backup script contract missing: '.$token);
echo "SQLITE_BACKUP_SCRIPT_CONTRACT_OK\n";
