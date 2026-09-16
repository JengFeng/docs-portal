<?php
declare(strict_types=1);

const PORTAL_STATE_SCHEMA_VERSION = 1;
const PORTAL_STATE_SQLITE_PATH = 'C:\\TWWATER\\runtime\\portal-state\\twwater-portal.sqlite';
const PORTAL_STATE_BACKUP_ROOT = 'C:\\TWWATER\\runtime\\database-backups\\sqlite';

function portal_state_binding_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'portal-state-binding.json';
}

function portal_state_binding_read(string $path): array
{
    $expectedPath = portal_state_binding_path();
    if ($path !== $expectedPath || str_contains($path, "\0") || !is_file($path) || is_link($path)) {
        throw new RuntimeException('SQLite 狀態設定檔無法安全讀取。');
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '' || strlen($raw) > 1024) {
        throw new RuntimeException('SQLite 狀態設定檔格式無效。');
    }

    try {
        $binding = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('SQLite 狀態設定檔格式無效。');
    }

    $keys = ['schema_version', 'sqlite_path', 'backup_root', 'google_login_enabled'];
    if (!is_array($binding) || array_keys($binding) !== $keys
        || $binding['schema_version'] !== PORTAL_STATE_SCHEMA_VERSION
        || $binding['sqlite_path'] !== PORTAL_STATE_SQLITE_PATH
        || $binding['backup_root'] !== PORTAL_STATE_BACKUP_ROOT
        || !is_bool($binding['google_login_enabled'])) {
        throw new RuntimeException('SQLite 狀態設定檔格式無效。');
    }

    return $binding;
}

function portal_state_path_is_inside(string $path, string $root): bool
{
    $normalizedPath = rtrim(str_replace('/', '\\', $path), '\\');
    $normalizedRoot = rtrim(str_replace('/', '\\', $root), '\\');
    return str_starts_with(strtolower($normalizedPath), strtolower($normalizedRoot . '\\'));
}

function portal_open_state_database_at(string $path): PDO
{
    if (!portal_state_path_is_inside($path, dirname(PORTAL_STATE_SQLITE_PATH)) || str_contains($path, "\0") || is_link($path)) {
        throw new RuntimeException('SQLite 狀態資料庫路徑無效。');
    }
    $directory = dirname($path);
    if (!is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('SQLite 狀態資料庫目錄無法安全使用。');
    }
    $database = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA journal_mode = WAL');
    $database->exec('PRAGMA synchronous = FULL');
    return $database;
}

function portal_open_existing_state_database(): PDO
{
    $binding = portal_state_binding_read(portal_state_binding_path());
    $path = (string) $binding['sqlite_path'];
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('SQLite 狀態尚未完成初始化。');
    }
    return portal_open_state_database_at($path);
}

function portal_open_state_database(): PDO
{
    $binding = portal_state_binding_read(portal_state_binding_path());
    return portal_open_state_database_at((string) $binding['sqlite_path']);
}

function portal_state_audit_event_hash(string $occurredAt, ?int $actorUserId, string $eventType, ?int $targetUserId, string $summaryJson, ?string $previousHash): string
{
    return hash('sha256', json_encode([
        'occurred_at' => $occurredAt,
        'actor_user_id' => $actorUserId,
        'event_type' => $eventType,
        'target_user_id' => $targetUserId,
        'summary_json' => $summaryJson,
        'previous_event_hash' => $previousHash,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function portal_state_verify_audit_chain(PDO $database): void
{
    $rows = $database->query('SELECT occurred_at, actor_user_id, event_type, target_user_id, summary_json, previous_event_hash, event_hash FROM auth_management_audit_events ORDER BY event_id ASC')->fetchAll();
    $expectedPreviousHash = null;
    foreach ($rows as $row) {
        $actualPreviousHash = $row['previous_event_hash'] === null ? null : (string) $row['previous_event_hash'];
        if ($actualPreviousHash !== $expectedPreviousHash) {
            throw new RuntimeException('稽核完整性驗證失敗。');
        }
        $expectedHash = portal_state_audit_event_hash(
            (string) $row['occurred_at'],
            $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            (string) $row['event_type'],
            $row['target_user_id'] === null ? null : (int) $row['target_user_id'],
            (string) $row['summary_json'],
            $actualPreviousHash
        );
        if (!hash_equals($expectedHash, (string) $row['event_hash'])) {
            throw new RuntimeException('稽核完整性驗證失敗。');
        }
        $expectedPreviousHash = (string) $row['event_hash'];
    }
}

function portal_state_append_audit_event(PDO $database, ?int $actorUserId, string $eventType, ?int $targetUserId, array $summary): void
{
    $ownsTransaction = !$database->inTransaction();
    if ($ownsTransaction) {
        $database->exec('BEGIN IMMEDIATE');
    }
    try {
        $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $previousHash = $database->query('SELECT event_hash FROM auth_management_audit_events ORDER BY event_id DESC LIMIT 1')->fetchColumn();
        $occurredAt = gmdate('c');
        $eventHash = portal_state_audit_event_hash($occurredAt, $actorUserId, $eventType, $targetUserId, $summaryJson, $previousHash === false ? null : (string) $previousHash);
        $statement = $database->prepare('INSERT INTO auth_management_audit_events (occurred_at, actor_user_id, event_type, target_user_id, summary_json, previous_event_hash, event_hash) VALUES (:occurred_at, :actor_user_id, :event_type, :target_user_id, :summary_json, :previous_event_hash, :event_hash)');
        $statement->execute([':occurred_at'=>$occurredAt,':actor_user_id'=>$actorUserId,':event_type'=>$eventType,':target_user_id'=>$targetUserId,':summary_json'=>$summaryJson,':previous_event_hash'=>$previousHash === false ? null : $previousHash,':event_hash'=>$eventHash]);
        if ($ownsTransaction) $database->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

function portal_initialize_first_admin(PDO $database, string $username, string $displayName, string $initialPassword): array
{
    $username = trim($username);
    $displayName = trim($displayName);
    if (preg_match('/\A[a-zA-Z0-9_.-]{3,80}\z/', $username) !== 1 || $displayName === '' || mb_strlen($displayName) > 160 || strlen($initialPassword) < 16) {
        throw new RuntimeException('初始管理員資料無效。');
    }
    $database->beginTransaction();
    try {
        $count = (int) $database->query('SELECT COUNT(*) FROM auth_users')->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('SQLite 已完成初始化，拒絕再次建立第一位管理員。');
        }
        $now = gmdate('c');
        $statement = $database->prepare(
            'INSERT INTO auth_users '
            . '(username, display_name, role_code, login_mode, password_hash, must_change_password, is_enabled, created_at, updated_at) '
            . "VALUES (:username, :display_name, 'admin', 'local_only', :password_hash, 1, 1, :created_at, :updated_at)"
        );
        $statement->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => password_hash($initialPassword, PASSWORD_DEFAULT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $userId = (int) $database->lastInsertId();
        portal_state_append_audit_event($database, null, 'first_admin_initialized', $userId, ['username' => $username]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    $lookup = $database->prepare('SELECT * FROM auth_users WHERE user_id = :user_id');
    $lookup->execute([':user_id' => $userId]);
    $user = $lookup->fetch();
    if (!is_array($user)) {
        throw new RuntimeException('初始管理員建立後讀回失敗。');
    }
    return $user;
}

function portal_state_initialize_schema(PDO $database): void
{
    $database->beginTransaction();
    try {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS portal_state_schema ('
            . 'schema_version INTEGER PRIMARY KEY NOT NULL, '
            . 'applied_at TEXT NOT NULL'
            . ')'
        );
        $database->exec(
            'CREATE TABLE IF NOT EXISTS auth_users ('
            . 'user_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'username TEXT NOT NULL COLLATE NOCASE UNIQUE, '
            . 'display_name TEXT NOT NULL, '
            . "role_code TEXT NOT NULL CHECK (role_code IN ('reader', 'admin')), "
            . "login_mode TEXT NOT NULL DEFAULT 'local_only' CHECK (login_mode IN ('local_only', 'google_only', 'local_and_google')), "
            . 'password_hash TEXT NULL, '
            . 'must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)), '
            . 'google_email TEXT NULL COLLATE NOCASE UNIQUE, '
            . 'google_subject TEXT NULL UNIQUE, '
            . 'google_bound_at TEXT NULL, '
            . 'is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)), '
            . 'failed_login_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_login_count >= 0), '
            . 'locked_until TEXT NULL, '
            . 'last_login_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')'
        );
        $database->exec(
            'CREATE TABLE IF NOT EXISTS portal_state_settings ('
            . 'setting_key TEXT PRIMARY KEY NOT NULL, '
            . 'setting_value TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')'
        );
        $database->exec(
            'CREATE TABLE IF NOT EXISTS auth_management_audit_events ('
            . 'event_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'occurred_at TEXT NOT NULL, '
            . 'actor_user_id INTEGER NULL REFERENCES auth_users(user_id), '
            . 'event_type TEXT NOT NULL, '
            . 'target_user_id INTEGER NULL REFERENCES auth_users(user_id), '
            . 'summary_json TEXT NOT NULL, '
            . 'previous_event_hash TEXT NULL, '
            . 'event_hash TEXT NOT NULL UNIQUE'
            . ')'
        );
        $database->exec('CREATE INDEX IF NOT EXISTS ix_auth_users_login_state ON auth_users (is_enabled, locked_until, username)');
        $database->exec('CREATE INDEX IF NOT EXISTS ix_auth_management_audit_events_occurred_at ON auth_management_audit_events (occurred_at DESC, event_id DESC)');
        $database->exec('CREATE TRIGGER IF NOT EXISTS trg_auth_management_audit_events_no_update BEFORE UPDATE ON auth_management_audit_events BEGIN SELECT RAISE(ABORT, \'Audit events are append-only.\'); END');
        $database->exec('CREATE TRIGGER IF NOT EXISTS trg_auth_management_audit_events_no_delete BEFORE DELETE ON auth_management_audit_events BEGIN SELECT RAISE(ABORT, \'Audit events are append-only.\'); END');
        $statement = $database->prepare('INSERT OR IGNORE INTO portal_state_schema (schema_version, applied_at) VALUES (:version, :applied_at)');
        $statement->execute([':version' => PORTAL_STATE_SCHEMA_VERSION, ':applied_at' => gmdate('c')]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}
