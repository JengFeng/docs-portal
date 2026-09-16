<?php
declare(strict_types=1);

function portal_account_normalize_username(string $username): string
{
    $username = trim($username);
    if (preg_match('/\A[a-zA-Z0-9_.-]{3,80}\z/', $username) !== 1) {
        throw new RuntimeException('帳號格式無效。');
    }
    return $username;
}

function portal_account_normalize_role(string $role): string
{
    if (!in_array($role, ['reader', 'admin'], true)) {
        throw new RuntimeException('帳號角色無效。');
    }
    return $role;
}

function portal_account_normalize_login_mode(string $mode): string
{
    if (!in_array($mode, ['local_only', 'google_only', 'local_and_google'], true)) {
        throw new RuntimeException('登入方式無效。');
    }
    return $mode;
}

function portal_account_normalize_google_email(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    if (strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Google Email 格式無效。');
    }
    return $email;
}

function portal_account_read_by_username(PDO $database, string $username): array
{
    $statement = $database->prepare('SELECT * FROM auth_users WHERE username = :username COLLATE NOCASE');
    $statement->execute([':username' => $username]);
    $user = $statement->fetch();
    if (!is_array($user)) throw new RuntimeException('找不到指定帳號。');
    return $user;
}

function portal_rename_account_username(PDO $database, ?int $actorUserId, int $targetUserId, string $newUsername): array
{
    $newUsername = portal_account_normalize_username($newUsername);
    $lookup = $database->prepare('SELECT username FROM auth_users WHERE user_id = :user_id');
    $lookup->execute([':user_id' => $targetUserId]);
    $oldUsername = $lookup->fetchColumn();
    if ($oldUsername === false) throw new RuntimeException('找不到指定帳號。');
    if (strcasecmp((string) $oldUsername, $newUsername) === 0) {
        return portal_account_read_by_username($database, (string) $oldUsername);
    }
    $database->beginTransaction();
    try {
        $statement = $database->prepare('UPDATE auth_users SET username = :username, updated_at = :updated_at WHERE user_id = :user_id');
        $statement->execute([':username'=>$newUsername, ':updated_at'=>gmdate('c'), ':user_id'=>$targetUserId]);
        portal_state_append_audit_event($database, $actorUserId, 'account_username_renamed', $targetUserId, ['old_username'=>(string)$oldUsername,'new_username'=>$newUsername]);
        $database->commit();
    } catch (Throwable $exception) { if ($database->inTransaction()) $database->rollBack(); throw $exception; }
    return portal_account_read_by_username($database, $newUsername);
}

function portal_update_account(PDO $database, int $actorUserId, int $targetUserId, array $input): array
{
    $lookup = $database->prepare('SELECT * FROM auth_users WHERE user_id = :user_id');
    $lookup->execute([':user_id' => $targetUserId]);
    $existing = $lookup->fetch();
    if (!is_array($existing)) {
        throw new RuntimeException('找不到指定帳號。');
    }
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $role = portal_account_normalize_role((string) ($input['role_code'] ?? ''));
    $loginMode = portal_account_normalize_login_mode((string) ($input['login_mode'] ?? ''));
    $googleEmail = portal_account_normalize_google_email((string) ($input['google_email'] ?? ''));
    $enabled = in_array($input['is_enabled'] ?? false, [true, 1, '1'], true);
    if ($displayName === '' || mb_strlen($displayName) > 160) {
        throw new RuntimeException('顯示名稱無效。');
    }
    if (($loginMode === 'google_only' || $loginMode === 'local_and_google') && $googleEmail === null) {
        throw new RuntimeException('Google 登入帳號必須預先綁定 Google Email。');
    }
    if ((string) ($existing['google_subject'] ?? '') !== ''
        && $googleEmail !== portal_account_normalize_google_email((string) ($existing['google_email'] ?? ''))) {
        throw new RuntimeException('Google immutable 身分已固定，不能改綁 Email。');
    }
    if ($actorUserId === $targetUserId && (!$enabled || $role !== 'admin')) {
        throw new RuntimeException('管理員不得停用自己或將自己降為閱讀者。');
    }
    if ($actorUserId === $targetUserId && $loginMode === 'google_only' && $googleEmail === null) {
        throw new RuntimeException('管理員不得移除自己的全部可用登入方式。');
    }

    $willBeActiveAdmin = $enabled && $role === 'admin';
    if ((string) $existing['role_code'] === 'admin' && (int) $existing['is_enabled'] === 1 && !$willBeActiveAdmin) {
        $activeAdminCount = (int) $database->query("SELECT COUNT(*) FROM auth_users WHERE role_code = 'admin' AND is_enabled = 1")->fetchColumn();
        if ($activeAdminCount <= 1) {
            throw new RuntimeException('系統至少必須保留一位啟用中的管理員。');
        }
    }

    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'UPDATE auth_users SET display_name = :display_name, role_code = :role_code, login_mode = :login_mode, google_email = :google_email, is_enabled = :is_enabled, updated_at = :updated_at WHERE user_id = :user_id'
        );
        $statement->execute([
            ':display_name' => $displayName,
            ':role_code' => $role,
            ':login_mode' => $loginMode,
            ':google_email' => $googleEmail,
            ':is_enabled' => $enabled ? 1 : 0,
            ':updated_at' => gmdate('c'),
            ':user_id' => $targetUserId,
        ]);
        portal_state_append_audit_event($database, $actorUserId, 'account_updated', $targetUserId, [
            'username' => (string) $existing['username'],
            'role_code' => $role,
            'login_mode' => $loginMode,
            'is_enabled' => $enabled,
            'google_email_bound' => $googleEmail !== null,
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    $lookup->execute([':user_id' => $targetUserId]);
    $updated = $lookup->fetch();
    if (!is_array($updated)) {
        throw new RuntimeException('帳號更新後讀回失敗。');
    }
    return $updated;
}

function portal_create_account(PDO $database, int $actorUserId, array $input): array
{
    $username = portal_account_normalize_username((string) ($input['username'] ?? ''));
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $role = portal_account_normalize_role((string) ($input['role_code'] ?? ''));
    $loginMode = portal_account_normalize_login_mode((string) ($input['login_mode'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $stagedPasswordHash = (string) ($input['staged_password_hash'] ?? '');
    if ($stagedPasswordHash !== '' && password_get_info($stagedPasswordHash)['algo'] === null) {
        throw new RuntimeException('暫存初始密碼資料無效。');
    }
    $googleEmail = portal_account_normalize_google_email((string) ($input['google_email'] ?? ''));
    if ($displayName === '' || mb_strlen($displayName) > 160) {
        throw new RuntimeException('顯示名稱無效。');
    }
    if (($loginMode === 'local_only' || $loginMode === 'local_and_google') && strlen($password) < 16 && $stagedPasswordHash === '') {
        throw new RuntimeException('本機密碼至少須為 16 個字元。');
    }
    if (($loginMode === 'google_only' || $loginMode === 'local_and_google') && $googleEmail === null) {
        throw new RuntimeException('Google 登入帳號必須預先綁定 Google Email。');
    }

    $database->beginTransaction();
    try {
        $now = gmdate('c');
        $statement = $database->prepare(
            'INSERT INTO auth_users '
            . '(username, display_name, role_code, login_mode, password_hash, must_change_password, google_email, is_enabled, created_at, updated_at) '
            . 'VALUES (:username, :display_name, :role_code, :login_mode, :password_hash, :must_change_password, :google_email, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':role_code' => $role,
            ':login_mode' => $loginMode,
            ':password_hash' => $stagedPasswordHash !== '' ? $stagedPasswordHash : ($password === '' ? null : password_hash($password, PASSWORD_DEFAULT)),
            ':must_change_password' => ($password === '' && $stagedPasswordHash === '') ? 0 : 1,
            ':google_email' => $googleEmail,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $userId = (int) $database->lastInsertId();
        portal_state_append_audit_event($database, $actorUserId, 'account_created', $userId, [
            'username' => $username,
            'role_code' => $role,
            'login_mode' => $loginMode,
            'google_email_bound' => $googleEmail !== null,
        ]);
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
        throw new RuntimeException('帳號建立後讀回失敗。');
    }
    return $user;
}
