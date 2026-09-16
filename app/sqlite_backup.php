<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'portal_state.php';

function portal_state_backup_root_is_allowed(string $path): bool
{
    return portal_state_path_is_inside(rtrim($path, '\\/') . '\\backup-probe', PORTAL_STATE_BACKUP_ROOT)
        && !is_link($path)
        && is_dir($path);
}

function portal_state_prune_backup_retention(string $backupRoot, int $dailyKeep = 14, int $weeklyKeep = 8): void
{
    if (!portal_state_backup_root_is_allowed($backupRoot) || $dailyKeep < 1 || $weeklyKeep < 1) {
        throw new RuntimeException('SQLite 備份保留設定無效。');
    }
    foreach (['daily' => $dailyKeep, 'weekly' => $weeklyKeep] as $label => $keep) {
        $files = glob(rtrim($backupRoot, '\\/') . DIRECTORY_SEPARATOR . 'twwater-portal-' . $label . '-*.sqlite') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, $keep) as $path) {
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('SQLite 備份保留清理遇到不安全項目。');
            }
            $manifest = $path . '.json';
            if (is_file($manifest) && !unlink($manifest)) {
                throw new RuntimeException('SQLite 備份資訊檔無法清理。');
            }
            if (!unlink($path)) {
                throw new RuntimeException('SQLite 過期備份無法清理。');
            }
        }
    }
}

function portal_state_create_verified_backup(PDO $database, string $backupRoot, string $label = 'daily'): array
{
    if (!portal_state_backup_root_is_allowed($backupRoot) || preg_match('/\A[a-z0-9_-]{1,32}\z/', $label) !== 1) {
        throw new RuntimeException('SQLite 備份目錄或名稱無效。');
    }
    portal_state_verify_audit_chain($database);
    $timestamp = gmdate('Ymd_His');
    $path = rtrim($backupRoot, '\\/') . DIRECTORY_SEPARATOR . 'twwater-portal-' . $label . '-' . $timestamp . '.sqlite';
    if (is_file($path) || is_link($path)) {
        throw new RuntimeException('SQLite 備份檔案名稱衝突。');
    }
    $escapedPath = str_replace("'", "''", $path);
    $database->exec("VACUUM INTO '" . $escapedPath . "'");
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('SQLite 備份未完成。');
    }
    $copy = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $integrity = strtolower((string) $copy->query('PRAGMA integrity_check')->fetchColumn());
    if ($integrity !== 'ok') {
        @unlink($path);
        throw new RuntimeException('SQLite 備份完整性驗證失敗。');
    }
    portal_state_verify_audit_chain($copy);
    $manifestPath = $path . '.json';
    $manifest = [
        'schema_version' => PORTAL_STATE_SCHEMA_VERSION,
        'created_at' => gmdate('c'),
        'integrity' => 'ok',
        'sha256' => hash_file('sha256', $path),
        'filename' => basename($path),
    ];
    $written = file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    if ($written === false || !is_file($manifestPath)) {
        @unlink($path);
        throw new RuntimeException('SQLite 備份資訊檔無法安全寫入。');
    }
    return ['path' => $path, 'manifest_path' => $manifestPath, 'integrity' => 'ok', 'sha256' => $manifest['sha256']];
}
