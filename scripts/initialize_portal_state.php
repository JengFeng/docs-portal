<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('此工具只能由本機命令列執行。');
}

$username = trim((string) ($argv[1] ?? ''));
$displayName = trim((string) ($argv[2] ?? ''));
if ($username === '' || $displayName === '' || isset($argv[3])) {
    fwrite(STDERR, "Usage: php scripts/initialize_portal_state.php <username> <display-name>\n");
    exit(64);
}

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$binding = portal_state_binding_read(portal_state_binding_path());
$path = (string) $binding['sqlite_path'];
if (is_file($path) || is_link($path)) {
    throw new RuntimeException('SQLite 狀態已存在；拒絕覆寫或再次初始化。');
}
try {
    $database = portal_open_state_database_at($path);
    portal_state_initialize_schema($database);
    $user = portal_initialize_first_admin($database, $username, $displayName, $password);
} catch (Throwable $exception) {
    unset($database);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
    @unlink($path);
    throw $exception;
}

fwrite(STDOUT, "SQLite first administrator initialized.\n");
fwrite(STDOUT, 'USERNAME=' . $user['username'] . "\n");
fwrite(STDOUT, 'FIRST_ADMIN_PASSWORD=' . $password . "\n");
fwrite(STDOUT, "Sign in once, then change this password immediately. This password is not stored in configuration or audit records.\n");
