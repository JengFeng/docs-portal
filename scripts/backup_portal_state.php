<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_backup.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('此工具只能由本機命令列執行。');
}
$binding = portal_state_binding_read(portal_state_binding_path());
if (!is_file(PORTAL_STATE_SQLITE_PATH) || is_link(PORTAL_STATE_SQLITE_PATH)) {
    throw new RuntimeException('SQLite 尚未完成初始化，拒絕建立空白備份。');
}
$database = portal_open_state_database();
portal_state_verify_audit_chain($database);
$label = gmdate('N') === '7' ? 'weekly' : 'daily';
$backup = portal_state_create_verified_backup($database, (string) $binding['backup_root'], $label);
portal_state_prune_backup_retention((string) $binding['backup_root'], 14, 8);
printf("SQLite backup verified: %s (%s)\n", basename((string) $backup['path']), (string) $backup['sha256']);
