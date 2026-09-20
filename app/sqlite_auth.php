<?php
declare(strict_types=1);

const PORTAL_SQLITE_ACCOUNT_MAX_FAILURES = 5;
const PORTAL_SQLITE_ACCOUNT_LOCK_MINUTES = 15;

function portal_sqlite_login_mode_allows_local(string $mode): bool
{
    return $mode === 'local_only' || $mode === 'local_and_google';
}

function portal_sqlite_login_mode_allows_google(string $mode): bool
{
    return $mode === 'google_only' || $mode === 'local_and_google';
}

function portal_sqlite_find_user_by_username(PDO $database, string $username): array
{
    $statement = $database->prepare('SELECT * FROM auth_users WHERE username = :username COLLATE NOCASE');
    $statement->execute([':username' => trim($username)]);
    $user = $statement->fetch();
    if (!is_array($user)) {
        throw new RuntimeException('帳號或密碼錯誤。');
    }
    return $user;
}

function portal_sqlite_authentication_failure(PDO $database, array $user): never
{
    $failedCount = (int) $user['failed_login_count'] + 1;
    $lockedUntil = $failedCount >= PORTAL_SQLITE_ACCOUNT_MAX_FAILURES
        ? gmdate('c', time() + PORTAL_SQLITE_ACCOUNT_LOCK_MINUTES * 60)
        : null;
    $statement = $database->prepare(
        'UPDATE auth_users SET failed_login_count = :failed_login_count, locked_until = :locked_until, updated_at = :updated_at WHERE user_id = :user_id'
    );
    $statement->execute([
        ':failed_login_count' => $failedCount,
        ':locked_until' => $lockedUntil,
        ':updated_at' => gmdate('c'),
        ':user_id' => (int) $user['user_id'],
    ]);
    throw new RuntimeException('帳號或密碼錯誤。');
}

function portal_sqlite_reset_password(PDO $database, ?int $actorUserId, int $targetUserId, string $newPassword): array
{
    if (strlen($newPassword) < 16 || strlen($newPassword) > 1024) throw new RuntimeException('重設密碼資料無效。');
    return portal_sqlite_reset_password_with_hash($database, $actorUserId, $targetUserId, password_hash($newPassword, PASSWORD_DEFAULT));
}

function portal_sqlite_reset_password_with_hash(PDO $database, ?int $actorUserId, int $targetUserId, string $stagedPasswordHash): array
{
    if ($targetUserId < 1 || password_get_info($stagedPasswordHash)['algo'] === null) {
        throw new RuntimeException('重設密碼資料無效。');
    }
    $lookup = $database->prepare('SELECT username FROM auth_users WHERE user_id = :user_id AND is_enabled = 1');
    $lookup->execute([':user_id' => $targetUserId]);
    $username = $lookup->fetchColumn();
    if ($username === false) throw new RuntimeException('找不到可重設的帳號。');
    $database->beginTransaction();
    try {
        $statement = $database->prepare('UPDATE auth_users SET password_hash = :password_hash, must_change_password = 1, failed_login_count = 0, locked_until = NULL, updated_at = :updated_at WHERE user_id = :user_id');
        $statement->execute([':password_hash' => $stagedPasswordHash, ':updated_at' => gmdate('c'), ':user_id' => $targetUserId]);
        portal_state_append_audit_event($database, $actorUserId, 'password_reset', $targetUserId, []);
        $database->commit();
    } catch (Throwable $exception) { if ($database->inTransaction()) $database->rollBack(); throw $exception; }
    return portal_sqlite_find_user_by_username($database, (string) $username);
}

function portal_sqlite_change_password(PDO $database, int $userId, string $currentPassword, string $newPassword): array
{
    if ($userId < 1 || strlen($newPassword) < 16 || strlen($newPassword) > 1024) {
        throw new RuntimeException('密碼變更資料無效。');
    }
    $user = portal_sqlite_find_user_by_username($database, (string) ($database->query('SELECT username FROM auth_users WHERE user_id = ' . (int) $userId)->fetchColumn() ?: ''));
    if ($user === null || (int) $user['is_enabled'] !== 1 || !password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
        throw new RuntimeException('目前密碼不正確。');
    }
    $database->beginTransaction();
    try {
        $now = gmdate('c');
        $statement = $database->prepare('UPDATE auth_users SET password_hash = :password_hash, must_change_password = 0, failed_login_count = 0, locked_until = NULL, updated_at = :updated_at WHERE user_id = :user_id');
        $statement->execute([':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':updated_at' => $now, ':user_id' => $userId]);
        portal_state_append_audit_event($database, $userId, 'password_changed', $userId, []);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
    return portal_sqlite_find_user_by_username($database, (string) $user['username']);
}

function portal_sqlite_authenticate_google(PDO $database, string $email, string $subject): array
{
    $email = portal_account_normalize_google_email($email);
    $subject = trim($subject);
    if ($email === null || $subject === '' || strlen($subject) > 255) {
        throw new RuntimeException('Google 登入驗證失敗。');
    }
    $statement = $database->prepare('SELECT * FROM auth_users WHERE google_email = :google_email COLLATE NOCASE');
    $statement->execute([':google_email' => $email]);
    $user = $statement->fetch();
    if (!is_array($user) || (int) $user['is_enabled'] !== 1 || !portal_sqlite_login_mode_allows_google((string) $user['login_mode'])) {
        throw new RuntimeException('Google 登入驗證失敗。');
    }
    $lockedUntil = (string) ($user['locked_until'] ?? '');
    if ($lockedUntil !== '' && strtotime($lockedUntil) > time()) {
        throw new RuntimeException('Google 登入驗證失敗。');
    }
    $currentSubject = $user['google_subject'] === null ? '' : (string) $user['google_subject'];
    if ($currentSubject !== '' && !hash_equals($currentSubject, $subject)) {
        throw new RuntimeException('Google 登入驗證失敗。');
    }
    $database->beginTransaction();
    try {
        $now = gmdate('c');
        if ($currentSubject === '') {
            $pin = $database->prepare('UPDATE auth_users SET google_subject = :google_subject, google_bound_at = :google_bound_at, updated_at = :updated_at WHERE user_id = :user_id');
            $pin->execute([':google_subject' => $subject, ':google_bound_at' => $now, ':updated_at' => $now, ':user_id' => (int) $user['user_id']]);
            portal_state_append_audit_event($database, (int) $user['user_id'], 'google_subject_pinned', (int) $user['user_id'], ['google_email_bound' => true]);
        }
        $success = $database->prepare('UPDATE auth_users SET failed_login_count = 0, locked_until = NULL, last_login_at = :last_login_at, updated_at = :updated_at WHERE user_id = :user_id');
        $success->execute([':last_login_at' => $now, ':updated_at' => $now, ':user_id' => (int) $user['user_id']]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    return portal_sqlite_find_user_by_username($database, (string) $user['username']);
}

function portal_sqlite_authenticate_local(PDO $database, string $username, string $password): array
{
    try {
        $user = portal_sqlite_find_user_by_username($database, $username);
    } catch (RuntimeException) {
        throw new RuntimeException('帳號或密碼錯誤。');
    }
    $lockedUntil = (string) ($user['locked_until'] ?? '');
    if ((int) $user['is_enabled'] !== 1 || ($lockedUntil !== '' && strtotime($lockedUntil) > time())) {
        throw new RuntimeException('帳號或密碼錯誤。');
    }
    if (!portal_sqlite_login_mode_allows_local((string) $user['login_mode'])
        || !is_string($user['password_hash'])
        || !password_verify($password, $user['password_hash'])) {
        portal_sqlite_authentication_failure($database, $user);
    }
    $statement = $database->prepare(
        'UPDATE auth_users SET failed_login_count = 0, locked_until = NULL, last_login_at = :last_login_at, updated_at = :updated_at WHERE user_id = :user_id'
    );
    $now = gmdate('c');
    $statement->execute([':last_login_at' => $now, ':updated_at' => $now, ':user_id' => (int) $user['user_id']]);
    return portal_sqlite_find_user_by_username($database, (string) $user['username']);
}
