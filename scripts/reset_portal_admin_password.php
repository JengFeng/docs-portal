<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';
require_once dirname(__DIR__) . '/app/sqlite_auth.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('此工具只能由本機命令列執行。');
}

$username = trim((string) ($argv[1] ?? ''));
if ($username === '' || isset($argv[2])) {
    fwrite(STDERR, "Usage: php scripts/reset_portal_admin_password.php <admin-username>\n");
    exit(64);
}

$database = portal_open_existing_state_database();
portal_state_verify_audit_chain($database);
$user = portal_sqlite_find_user_by_username($database, $username);
if ((string) $user['role_code'] !== 'admin' || (int) $user['is_enabled'] !== 1 || !portal_sqlite_login_mode_allows_local((string) $user['login_mode'])) {
    throw new RuntimeException('指定帳號不是可用的本機登入管理員。');
}

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
portal_sqlite_reset_password($database, null, (int) $user['user_id'], $password);

fwrite(STDOUT, "SQLite administrator password recovered.\n");
fwrite(STDOUT, 'USERNAME=' . $user['username'] . "\n");
fwrite(STDOUT, 'RECOVERY_PASSWORD=' . $password . "\n");
fwrite(STDOUT, "Sign in once, then change this password immediately. This password is not stored in configuration or audit records.\n");
