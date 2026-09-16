<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';

if (PHP_SAPI !== 'cli') throw new RuntimeException('此工具只能由本機命令列執行。');
$oldUsername = trim((string) ($argv[1] ?? ''));
$newUsername = trim((string) ($argv[2] ?? ''));
if ($oldUsername === '' || $newUsername === '' || isset($argv[3])) {
    fwrite(STDERR, "Usage: php scripts/rename_portal_admin_username.php <old-admin-username> <new-admin-username>\n");
    exit(64);
}

$database = portal_open_existing_state_database();
portal_state_verify_audit_chain($database);
$current = portal_account_read_by_username($database, $oldUsername);
if ((string) $current['role_code'] !== 'admin' || (int) $current['is_enabled'] !== 1) {
    throw new RuntimeException('指定帳號不是啟用中的管理員。');
}
$updated = portal_rename_account_username($database, null, (int) $current['user_id'], $newUsername);
portal_state_verify_audit_chain($database);
printf("Administrator username renamed: %s\n", (string) $updated['username']);
