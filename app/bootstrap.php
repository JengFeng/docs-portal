<?php
declare(strict_types=1);

/*
 * 供水監測文件協作平台共用服務。
 *
 * 文件本體永遠留在 PORTAL_DOCUMENT_ROOT；資料庫只保存可搜尋的中繼資料、
 * SSDLC 關聯與稽核紀錄。任何讀檔都必須先經過資料庫授權及實體路徑檢查。
 */

const PORTAL_IDLE_TIMEOUT_SECONDS = 1800;
const PORTAL_ABSOLUTE_TIMEOUT_SECONDS = 28800;
const PORTAL_ACCOUNT_MAX_FAILURES = 5;
const PORTAL_ACCOUNT_LOCK_MINUTES = 15;
const PORTAL_IP_MAX_FAILURES = 20;
const PORTAL_IP_LOCK_MINUTES = 30;
const PORTAL_RATE_WINDOW_MINUTES = 15;
const PORTAL_DOCUMENT_LIST_LIMIT = 500;
const PORTAL_DOCUMENT_SYNC_LIMIT = 2000;
const PORTAL_DOCUMENT_HASH_MAX_BYTES = 524288000;
const PORTAL_BRIDGE_RECONCILE_MIN_SECONDS = 30;
const PORTAL_BRIDGE_RECONCILE_MAX_SECONDS = 3600;
const PORTAL_BRIDGE_RECONCILE_DEFAULT_SECONDS = 60;

function portal_bridge_settings_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'bridge_settings.json';
}

function portal_bridge_reconcile_interval(string $value): int
{
    if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
        throw new RuntimeException('漏失校正秒數必須介於 30 到 3600 秒。');
    }
    $seconds = (int) $value;
    if ($seconds < PORTAL_BRIDGE_RECONCILE_MIN_SECONDS || $seconds > PORTAL_BRIDGE_RECONCILE_MAX_SECONDS) {
        throw new RuntimeException('漏失校正秒數必須介於 30 到 3600 秒。');
    }
    return $seconds;
}

function portal_bridge_settings_read(string $path, int $fallback = PORTAL_BRIDGE_RECONCILE_DEFAULT_SECONDS): int
{
    if (!is_file($path) || is_link($path)) {
        return $fallback;
    }
    try {
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > 1024) {
            return $fallback;
        }
        $settings = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($settings) || !array_key_exists('full_reconcile_seconds', $settings)) {
            return $fallback;
        }
        return portal_bridge_reconcile_interval((string) $settings['full_reconcile_seconds']);
    } catch (Throwable) {
        return $fallback;
    }
}

function portal_bridge_settings_write(string $path, int $seconds): void
{
    $seconds = portal_bridge_reconcile_interval((string) $seconds);
    if (is_link($path)) {
        throw new RuntimeException('橋接器設定檔無法安全寫入。');
    }
    $payload = json_encode(
        ['full_reconcile_seconds' => $seconds],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    $written = file_put_contents($path, $payload, LOCK_EX);
    if ($written !== strlen($payload) || portal_bridge_settings_read($path, -1) !== $seconds) {
        throw new RuntimeException('橋接器設定檔無法安全寫入。');
    }
}

function portal_google_login_configured(array $config): bool
{
    $clientId = trim((string) ($config['google_oauth_client_id'] ?? ''));
    $secret = (string) ($config['google_oauth_client_secret'] ?? '');
    $redirect = trim((string) ($config['google_oauth_redirect_uri'] ?? ''));
    $caBundle = trim((string) ($config['google_ca_bundle'] ?? ''));
    return preg_match('/\A[0-9A-Za-z._-]+\.apps\.googleusercontent\.com\z/', $clientId) === 1
        && $secret !== ''
        && str_starts_with($redirect, 'https://')
        && filter_var($redirect, FILTER_VALIDATE_URL) !== false
        && $caBundle !== ''
        && is_file($caBundle)
        && is_readable($caBundle);
}

function portal_normalize_google_email(string $email): string
{
    $email = strtolower(trim($email));
    return strlen($email) <= 320 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
}

function portal_config(bool $requireDocumentRoot = true): array
{
    $required = [
        'db_host' => 'PORTAL_DB_HOST',
        'db_name' => 'PORTAL_DB_NAME',
        'db_username' => 'PORTAL_DB_USERNAME',
        'rate_key' => 'PORTAL_RATE_KEY',
        'session_save_path' => 'PORTAL_SESSION_SAVE_PATH',
    ];
    if ($requireDocumentRoot) {
        $required['document_root'] = 'PORTAL_DOCUMENT_ROOT';
    }

    $config = [];
    $missing = [];
    foreach ($required as $key => $environmentKey) {
        $value = getenv($environmentKey);
        $raw = $value === false ? '' : (string) $value;
        if (trim($raw) === '') {
            $missing[] = $environmentKey;
            $config[$key] = '';
            continue;
        }
        $config[$key] = in_array($key, ['db_password', 'rate_key'], true) ? $raw : trim($raw);
    }

    $databasePassword = portal_database_password_from_environment();
    $config['db_password'] = $databasePassword['value'];
    if (!$databasePassword['valid']) {
        $missing[] = $databasePassword['source'];
    }

    if (($config['rate_key'] ?? '') !== '' && strlen((string) $config['rate_key']) < 32) {
        $missing[] = 'PORTAL_RATE_KEY';
    }
    $directSyncMode = getenv('PORTAL_DIRECT_SYNC_MODE') === '1';
    $directSyncSourceValue = getenv('PORTAL_DIRECT_SYNC_SOURCE_ROOT');
    $directSyncSource = $directSyncSourceValue === false ? '' : trim((string) $directSyncSourceValue);
    $config['direct_sync_mode'] = $directSyncMode;
    $config['direct_sync_source_root'] = $directSyncSource;
    $config['direct_sync_stable_seconds'] = PORTAL_BRIDGE_RECONCILE_DEFAULT_SECONDS;
    if ($requireDocumentRoot && ($config['document_root'] ?? '') !== '') {
        $validRoot = $directSyncMode
            ? portal_direct_sync_root_is_allowed((string) $config['document_root'], $directSyncSource)
            : portal_document_root_is_allowed((string) $config['document_root']);
        if (!$validRoot) $missing[] = 'PORTAL_DOCUMENT_ROOT';
    }
    if (($config['session_save_path'] ?? '') !== '' && !portal_path_is_outside_application_root((string) $config['session_save_path'])) {
        $missing[] = 'PORTAL_SESSION_SAVE_PATH';
    }

    $cookiePath = trim((string) getenv('PORTAL_COOKIE_PATH'));
    if (preg_match('#\A/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/\z#', $cookiePath) !== 1) {
        $cookiePath = '/gary/TWWATER/';
    }

    $config['ready'] = count($missing) === 0;
    $config['missing'] = $missing;
    $config['cookie_path'] = $cookiePath;
    $config['db_encrypt'] = getenv('PORTAL_DB_ENCRYPT') !== '0';
    $config['db_trust_server_certificate'] = getenv('PORTAL_DB_TRUST_SERVER_CERTIFICATE') === '1';
    $sessionRoot = rtrim((string) ($config['session_save_path'] ?? ''), '\\/');
    $config['bridge_signal_path'] = $sessionRoot === ''
        ? ''
        : $sessionRoot . DIRECTORY_SEPARATOR . 'document-bridge.pending';
    $config['bridge_syncing_path'] = $sessionRoot === ''
        ? ''
        : $sessionRoot . DIRECTORY_SEPARATOR . 'document-bridge.syncing';
    $previewRootValue = getenv('PORTAL_PRESENTATION_PREVIEW_ROOT');
    $previewRoot = $previewRootValue === false ? '' : trim((string) $previewRootValue);
    $config['presentation_preview_root'] = $previewRoot;
    $config['presentation_preview_enabled'] = $previewRoot !== ''
        && portal_path_is_outside_application_root($previewRoot);
    $versionRootValue = getenv('PORTAL_DOCUMENT_VERSION_ROOT');
    $versionRoot = $versionRootValue === false ? '' : trim((string) $versionRootValue);
    $config['document_version_root'] = $versionRoot;
    $config['document_version_enabled'] = $versionRoot !== ''
        && portal_path_is_outside_application_root($versionRoot)
        && portal_version_root_is_separate_from_documents($versionRoot, (string) ($config['document_root'] ?? ''));
    $config['document_restore_enabled'] = $config['document_version_enabled']
        && !portal_document_root_is_allowed((string) ($config['document_root'] ?? ''));
    $imageArchiveRootValue = getenv('PORTAL_IMAGE_SOURCE_ARCHIVE_ROOT');
    $imageArchiveRoot = $imageArchiveRootValue === false ? '' : trim((string) $imageArchiveRootValue);
    $config['image_source_archive_root'] = $imageArchiveRoot;
    $config['image_source_archive_enabled'] = $imageArchiveRoot !== ''
        && portal_path_is_outside_application_root($imageArchiveRoot)
        && portal_version_root_is_separate_from_documents($imageArchiveRoot, (string) ($config['document_root'] ?? ''));
    $imageCommandKeyValue = getenv('PORTAL_IMAGE_COMMAND_KEY_PATH');
    $imageCommandKeyPath = $imageCommandKeyValue === false ? '' : trim((string) $imageCommandKeyValue);
    $config['image_command_key_path'] = $imageCommandKeyPath;
    $config['image_command_enabled'] = $config['image_source_archive_enabled']
        && $imageCommandKeyPath !== ''
        && portal_path_is_outside_application_root($imageCommandKeyPath);
    $preuploadRootValue = getenv('PORTAL_PREUPLOAD_PREVIEW_ROOT');
    $preuploadRoot = $preuploadRootValue === false ? '' : trim((string) $preuploadRootValue);
    $config['preupload_preview_root'] = $preuploadRoot;
    $config['preupload_preview_enabled'] = $preuploadRoot !== ''
        && portal_path_is_outside_application_root($preuploadRoot);
    $commitRootValue = getenv('PORTAL_STAGED_COMMIT_ROOT');
    $commitRoot = $commitRootValue === false ? '' : trim((string) $commitRootValue);
    $config['staged_commit_root'] = $commitRoot;
    $config['staged_commit_enabled'] = $commitRoot !== ''
        && portal_path_is_outside_application_root($commitRoot);
    $googleClientId = trim((string) (getenv('PORTAL_GOOGLE_OAUTH_CLIENT_ID') ?: ''));
    $googleClientSecret = (string) (getenv('PORTAL_GOOGLE_OAUTH_CLIENT_SECRET') ?: '');
    $googleRedirectUri = trim((string) (getenv('PORTAL_GOOGLE_OAUTH_REDIRECT_URI') ?: ''));
    $googleCaBundle = trim((string) (getenv('PORTAL_GOOGLE_CA_BUNDLE') ?: ''));
    $config['google_oauth_client_id'] = $googleClientId;
    $config['google_oauth_client_secret'] = $googleClientSecret;
    $config['google_oauth_redirect_uri'] = $googleRedirectUri;
    $config['google_ca_bundle'] = $googleCaBundle;
    $config['google_login_enabled'] = portal_google_login_configured($config);
    $config['image_export_python'] = trim((string) (getenv('PORTAL_IMAGE_EXPORT_PYTHON') ?: ''));
    return $config;
}

/**
 * Base64 is used only to keep a SQL password byte-stable when IIS passes it
 * through a Windows environment variable. It is not a secret-protection layer.
 */
function portal_database_password_from_environment(): array
{
    $encoded = getenv('PORTAL_DB_PASSWORD_B64');
    $encodedRaw = $encoded === false ? '' : trim((string) $encoded);
    if ($encodedRaw !== '') {
        $decoded = base64_decode($encodedRaw, true);
        if ($decoded === false || $decoded === '') {
            return ['value' => '', 'source' => 'PORTAL_DB_PASSWORD_B64', 'valid' => false];
        }
        return ['value' => $decoded, 'source' => 'PORTAL_DB_PASSWORD_B64', 'valid' => true];
    }

    // Temporary backward compatibility for an existing protected deployment.
    $legacy = getenv('PORTAL_DB_PASSWORD');
    $legacyRaw = $legacy === false ? '' : (string) $legacy;
    if ($legacyRaw === '') {
        return ['value' => '', 'source' => 'PORTAL_DB_PASSWORD_B64', 'valid' => false];
    }
    return ['value' => $legacyRaw, 'source' => 'PORTAL_DB_PASSWORD', 'valid' => true];
}

function portal_path_has_reparse_component(string $path): bool
{
    $normalized = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '\\/');
    if (preg_match('/\A([A-Za-z]:)[\\\\](.*)\z/', $normalized, $matches) !== 1) {
        return true;
    }
    $current = strtoupper($matches[1]) . DIRECTORY_SEPARATOR;
    $segments = preg_split('/[\\\\]+/', $matches[2], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($segments === []) {
        return true;
    }
    foreach ($segments as $segment) {
        $current = rtrim($current, '\\/') . DIRECTORY_SEPARATOR . $segment;
        if (!file_exists($current) && !is_link($current)) {
            return true;
        }
        if (is_link($current)) {
            return true;
        }
        $stat = @lstat($current);
        if ($stat === false || (((int) ($stat['mode'] ?? 0)) & 0170000) === 0120000) {
            return true;
        }
    }
    return false;
}

function portal_document_root_is_allowed(string $configuredPath, ?string $applicationRootOverride = null): bool
{
    if ($configuredPath === '' || str_contains($configuredPath, "\0")) {
        return false;
    }
    $segments = preg_split('#[\\\\/]#', $configuredPath) ?: [];
    if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
        return false;
    }
    $applicationRootInput = $applicationRootOverride ?? dirname(__DIR__);
    if (portal_path_has_reparse_component($applicationRootInput)
        || portal_path_has_reparse_component($configuredPath)) {
        return false;
    }
    $pathKey = static fn(string $path): string => strtolower(rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '\\/'));
    $applicationRoot = realpath($applicationRootInput);
    if ($applicationRoot === false || !is_dir($applicationRoot) || is_link($applicationRoot)
        || $pathKey($applicationRootInput) !== $pathKey($applicationRoot)) {
        return false;
    }
    $expected = realpath($applicationRoot . DIRECTORY_SEPARATOR . 'document-library');
    $candidate = realpath($configuredPath);
    if ($expected === false || $candidate === false || !is_dir($candidate) || is_link($candidate)
        || $pathKey($configuredPath) !== $pathKey($candidate)) {
        return false;
    }
    return $pathKey($candidate) === $pathKey($expected)
        && $pathKey(dirname($expected)) === $pathKey($applicationRoot);
}

function portal_path_is_outside_application_root(string $configuredPath): bool
{
    $applicationRoot = realpath(dirname(__DIR__));
    $candidate = realpath($configuredPath);
    if ($applicationRoot === false || $candidate === false) {
        return false;
    }
    $applicationPrefix = strtolower(rtrim($applicationRoot, '\\/') . DIRECTORY_SEPARATOR);
    $candidatePrefix = strtolower(rtrim($candidate, '\\/') . DIRECTORY_SEPARATOR);
    return !str_starts_with($candidatePrefix, $applicationPrefix);
}

function portal_sqlsrv_escape_password(string $password): string
{
    // Microsoft SQLSRV/PDO_SQLSRV requires every closing brace in a PWD
    // connection attribute to be escaped with a second closing brace.
    return str_replace('}', '}}', $password);
}

function portal_open_database(array $config): PDO
{
    foreach (['db_host', 'db_name'] as $key) {
        if (preg_match('/[;{}]/', (string) $config[$key]) === 1) {
            throw new RuntimeException('資料庫連線設定格式無效。');
        }
    }

    // Pin the installed ODBC 18 driver so that a host with both ODBC 17 and
    // 18 cannot bind a different driver than the one validated for this site.
    $dsn = 'sqlsrv:Driver={ODBC Driver 18 for SQL Server};Server=' . $config['db_host']
        . ';Database=' . $config['db_name']
        . ';Encrypt=' . ($config['db_encrypt'] ? 'yes' : 'no')
        . ';TrustServerCertificate=' . ($config['db_trust_server_certificate'] ? 'yes' : 'no');

    return new PDO($dsn, $config['db_username'], portal_sqlsrv_escape_password((string) $config['db_password']), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);
}

function portal_start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!is_dir($config['session_save_path']) || !is_writable($config['session_save_path'])) {
        throw new RuntimeException('工作階段儲存區尚未正確設定。');
    }

    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set(
        'error_log',
        rtrim((string) $config['session_save_path'], '\\/') . DIRECTORY_SEPARATOR . 'portal-errors.log'
    );
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_save_path($config['session_save_path']);
    session_name('twwater_portal');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $config['cookie_path'],
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function portal_send_security_headers(bool $allowSameOriginFrame = false): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . ($allowSameOriginFrame ? 'SAMEORIGIN' : 'DENY'));
    header('Referrer-Policy: same-origin');
    $frameAncestors=$allowSameOriginFrame?"frame-ancestors 'self';":"frame-ancestors 'none';";
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; {$frameAncestors} object-src 'none'; script-src 'self'; worker-src 'self'; connect-src 'self'; img-src 'self' data: blob:; font-src 'self' data: blob:; style-src 'self'");
}

/**
 * The parent IIS site locks system.webServer/security/access, so setting
 * sslFlags in this application's web.config causes IIS 500.19. Keep the
 * portal HTTPS-only at the entry point using its approved public hostname.
 */
function portal_enforce_https(): void
{
    $httpsValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttps = $httpsValue !== '' && $httpsValue !== 'off';
    $isHttps = $isHttps || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    if ($isHttps) {
        return;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/gary/TWWATER/');
    if (
        preg_match('#\A/gary/TWWATER(?:/|\?|\z)#', $requestUri) !== 1
        || str_contains($requestUri, "\r")
        || str_contains($requestUri, "\n")
    ) {
        $requestUri = '/gary/TWWATER/';
    }

    header('Location: https://aiwork.ddns.net' . $requestUri, true, 308);
    exit;
}

function portal_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function portal_trim_text(string $value, int $maxLength): string
{
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function portal_url(string $action = 'home', array $parameters = []): string
{
    $query = $parameters;
    if ($action !== 'home') {
        $query = array_merge(['action' => $action], $query);
    }
    if ($query === []) {
        return '?';
    }
    return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function portal_csrf_token(): string
{
    return (string) ($_SESSION['csrf'] ?? '');
}

function portal_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . portal_e(portal_csrf_token()) . '">';
}

function portal_assert_csrf(): void
{
    $provided = (string) ($_POST['csrf_token'] ?? '');
    $expected = portal_csrf_token();
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('安全驗證失敗，請重新操作。');
    }
}

function portal_normalize_username(string $username): string
{
    $username = strtolower(trim($username));
    return preg_match('/\A[a-z0-9][a-z0-9._-]{2,127}\z/', $username) === 1 ? $username : '';
}

function portal_normalize_code(string $value, int $maxLength = 64): string
{
    $value = strtolower(trim($value));
    $pattern = '\A[a-z0-9][a-z0-9_-]{0,' . max(0, $maxLength - 1) . '}\z';
    return preg_match('/' . $pattern . '/', $value) === 1 ? $value : '';
}

function portal_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return preg_match('/\A[0-9a-fA-F:.]{3,45}\z/', $ip) === 1 ? $ip : '';
}

function portal_user_agent(): string
{
    return portal_trim_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 512);
}

function portal_clear_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'] ?: '/',
            'secure' => (bool) $cookie['secure'],
            'httponly' => (bool) $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?: 'Lax',
        ]);
        session_destroy();
    }
}

function portal_reset_anonymous_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function portal_flash_set(string $kind, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['flash'] = ['kind' => $kind, 'message' => portal_trim_text($message, 500)];
}

function portal_flash_take(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!is_array($flash) || !isset($flash['kind'], $flash['message'])) {
        return '';
    }
    $kind = $flash['kind'] === 'error' ? 'error-message' : 'success-message';
    return '<p class="' . $kind . '" role="status">' . portal_e((string) $flash['message']) . '</p>';
}

function portal_current_user(PDO $database): ?array
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $lastSeen = isset($_SESSION['last_seen_at']) ? (int) $_SESSION['last_seen_at'] : 0;
    $authenticatedAt = isset($_SESSION['authenticated_at']) ? (int) $_SESSION['authenticated_at'] : 0;
    $now = time();
    if ($userId <= 0 || $lastSeen <= 0 || $authenticatedAt <= 0) {
        // A new anonymous session already has a CSRF token. Regenerating it
        // here invalidates the token rendered by the login form before POST.
        return null;
    }
    if ($now - $lastSeen > PORTAL_IDLE_TIMEOUT_SECONDS
        || $now - $authenticatedAt > PORTAL_ABSOLUTE_TIMEOUT_SECONDS
    ) {
        portal_reset_anonymous_session();
        return null;
    }

    $statement = $database->prepare(
        'SELECT user_id, username, display_name, role_code
         FROM dbo.auth_users
         WHERE user_id = ? AND is_active = 1'
    );
    $statement->execute([$userId]);
    $user = $statement->fetch();
    if ($user === false) {
        portal_reset_anonymous_session();
        return null;
    }
    $_SESSION['last_seen_at'] = $now;
    return $user;
}

function portal_require_user(PDO $database): array
{
    $user = portal_current_user($database);
    if ($user === null) {
        header('Location: ' . portal_url('login'), true, 303);
        exit;
    }
    return $user;
}

function portal_is_admin(array $user): bool
{
    return (string) ($user['role_code'] ?? '') === 'admin';
}

function portal_can_edit_documents(array $user): bool
{
    return in_array((string) ($user['role_code'] ?? ''), ['editor', 'admin'], true);
}

function portal_can_confirm_staged_revision(array $user, int $createdByUserId): bool
{
    $userId = (int) ($user['user_id'] ?? 0);
    return portal_is_admin($user)
        || (portal_can_edit_documents($user) && $userId > 0 && $userId === $createdByUserId);
}

function portal_require_editor(PDO $database): array
{
    $user = portal_require_user($database);
    if (!portal_can_edit_documents($user)) {
        http_response_code(403);
        portal_render_page(
            '沒有權限',
            '<section class="panel narrow"><p class="eyebrow">ACCESS DENIED</p><h1>沒有編輯權限</h1><p>此功能僅限編輯者或系統管理員使用。</p><a class="primary-button" href="' . portal_url('documents') . '">返回文件庫</a></section>',
            $user
        );
        exit;
    }
    return $user;
}

function portal_require_admin(PDO $database): array
{
    $user = portal_require_user($database);
    if (!portal_is_admin($user)) {
        http_response_code(403);
        portal_render_page(
            '沒有權限',
            '<section class="panel narrow"><p class="eyebrow">ACCESS DENIED</p><h1>沒有管理權限</h1><p>此功能僅限系統管理員使用。</p><a class="primary-button" href="' . portal_url('documents') . '">返回文件庫</a></section>',
            $user
        );
        exit;
    }
    return $user;
}

function portal_audit(PDO $database, string $eventType, string $outcome, ?int $actorUserId, ?string $loginName = null): void
{
    $statement = $database->prepare(
        'INSERT INTO dbo.auth_audit_events
            (event_type, outcome, actor_user_id, login_name_normalized, client_ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        portal_trim_text($eventType, 64),
        portal_trim_text($outcome, 32),
        $actorUserId,
        $loginName === null ? null : portal_trim_text($loginName, 128),
        portal_client_ip() ?: null,
        portal_user_agent() ?: null,
    ]);
}

function portal_document_audit(PDO $database, string $eventType, string $outcome, ?int $actorUserId, ?int $documentId = null, ?string $detail = null): void
{
    $statement = $database->prepare(
        'INSERT INTO dbo.document_audit_events
            (event_type, outcome, actor_user_id, document_id, detail, client_ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        portal_trim_text($eventType, 64),
        portal_trim_text($outcome, 32),
        $actorUserId,
        $documentId,
        $detail === null ? null : portal_trim_text($detail, 1000),
        portal_client_ip() ?: null,
        portal_user_agent() ?: null,
    ]);
}

function portal_ip_rate_key(array $config): string
{
    return hash_hmac('sha256', portal_client_ip(), $config['rate_key']);
}

function portal_is_ip_locked(PDO $database, array $config): bool
{
    $statement = $database->prepare(
        'SELECT 1
         FROM dbo.auth_rate_limits
         WHERE scope_code = ? AND scope_key = ? AND locked_until > SYSUTCDATETIME()'
    );
    $statement->execute(['ip', portal_ip_rate_key($config)]);
    return $statement->fetch() !== false;
}

function portal_register_ip_failure(PDO $database, array $config): bool
{
    $scope = 'ip';
    $key = portal_ip_rate_key($config);
    $database->beginTransaction();
    try {
        $select = $database->prepare(
            'SELECT failure_count,
                    CASE WHEN window_started_at > DATEADD(MINUTE, ?, SYSUTCDATETIME()) THEN 1 ELSE 0 END AS window_is_active
             FROM dbo.auth_rate_limits WITH (UPDLOCK, HOLDLOCK)
             WHERE scope_code = ? AND scope_key = ?'
        );
        $select->bindValue(1, -PORTAL_RATE_WINDOW_MINUTES, PDO::PARAM_INT);
        $select->bindValue(2, $scope, PDO::PARAM_STR);
        $select->bindValue(3, $key, PDO::PARAM_STR);
        $select->execute();
        $row = $select->fetch();
        $willLock = false;
        if ($row === false) {
            $insert = $database->prepare(
                'INSERT INTO dbo.auth_rate_limits
                    (scope_code, scope_key, window_started_at, failure_count, locked_until, updated_at)
                 VALUES (?, ?, SYSUTCDATETIME(), 1, NULL, SYSUTCDATETIME())'
            );
            $insert->execute([$scope, $key]);
        } elseif ((int) $row['window_is_active'] !== 1) {
            $reset = $database->prepare(
                'UPDATE dbo.auth_rate_limits
                 SET window_started_at = SYSUTCDATETIME(), failure_count = 1, locked_until = NULL, updated_at = SYSUTCDATETIME()
                 WHERE scope_code = ? AND scope_key = ?'
            );
            $reset->execute([$scope, $key]);
        } else {
            $newCount = (int) $row['failure_count'] + 1;
            $willLock = $newCount >= PORTAL_IP_MAX_FAILURES;
            $update = $database->prepare(
                'UPDATE dbo.auth_rate_limits
                 SET failure_count = ?,
                     locked_until = CASE WHEN ? = 1 THEN DATEADD(MINUTE, ?, SYSUTCDATETIME()) ELSE locked_until END,
                     updated_at = SYSUTCDATETIME()
                 WHERE scope_code = ? AND scope_key = ?'
            );
            $update->bindValue(1, $newCount, PDO::PARAM_INT);
            $update->bindValue(2, $willLock ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(3, PORTAL_IP_LOCK_MINUTES, PDO::PARAM_INT);
            $update->bindValue(4, $scope, PDO::PARAM_STR);
            $update->bindValue(5, $key, PDO::PARAM_STR);
            $update->execute();
        }
        $database->commit();
        return $willLock;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function portal_clear_ip_rate_limit(PDO $database, array $config): void
{
    $statement = $database->prepare('DELETE FROM dbo.auth_rate_limits WHERE scope_code = ? AND scope_key = ?');
    $statement->execute(['ip', portal_ip_rate_key($config)]);
}

function portal_complete_login_session(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_seen_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function portal_attempt_login(PDO $database, array $config, string $username, string $password): array
{
    $normalized = portal_normalize_username($username);
    $dummyHash = '$2y$10$2VTk0fciVqMBNYpVcWeZu.CjuywuvTHFZ6M2Fuq4uSPjncKfMjDdu';
    $ipLocked = portal_is_ip_locked($database, $config);
    if ($normalized === '' || $ipLocked) {
        password_verify($password, $dummyHash);
        if (!$ipLocked) {
            $rateLimited = portal_register_ip_failure($database, $config);
            portal_audit($database, $rateLimited ? 'rate_limited' : 'login_failure', 'rejected', null, $normalized ?: null);
        }
        return [false, '帳號或密碼錯誤，或帳號暫時無法使用。'];
    }

    $statement = $database->prepare(
        'SELECT user_id, username, username_normalized, display_name, role_code, password_hash, is_active,
                CASE WHEN locked_until > SYSUTCDATETIME() THEN 1 ELSE 0 END AS is_locked
         FROM dbo.auth_users WHERE username_normalized = ?'
    );
    $statement->execute([$normalized]);
    $user = $statement->fetch();
    $passwordValid = password_verify($password, $user === false ? $dummyHash : (string) $user['password_hash']);
    $isLocked = $user !== false && (int) $user['is_locked'] === 1;
    if ($user === false || !$passwordValid || (int) $user['is_active'] !== 1 || $isLocked) {
        if ($user !== false && (int) $user['is_active'] === 1 && !$isLocked) {
            $database->beginTransaction();
            try {
                $count = $database->prepare(
                    'SELECT failed_login_count,
                            CASE WHEN locked_until IS NOT NULL AND locked_until <= SYSUTCDATETIME() THEN 1 ELSE 0 END AS lock_expired
                     FROM dbo.auth_users WITH (UPDLOCK, HOLDLOCK) WHERE user_id = ?'
                );
                $count->execute([(int) $user['user_id']]);
                $lockedRow = $count->fetch();
                $previousFailures = (int) $lockedRow['lock_expired'] === 1 ? 0 : (int) $lockedRow['failed_login_count'];
                $failures = $previousFailures + 1;
                $lock = $failures >= PORTAL_ACCOUNT_MAX_FAILURES;
                $update = $database->prepare(
                    'UPDATE dbo.auth_users
                     SET failed_login_count = ?,
                         locked_until = CASE WHEN ? = 1 THEN DATEADD(MINUTE, ?, SYSUTCDATETIME()) ELSE NULL END,
                         updated_at = SYSUTCDATETIME()
                     WHERE user_id = ?'
                );
                $update->execute([$failures, $lock ? 1 : 0, PORTAL_ACCOUNT_LOCK_MINUTES, (int) $user['user_id']]);
                $database->commit();
            } catch (Throwable $exception) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $exception;
            }
        }
        $rateLimited = portal_register_ip_failure($database, $config);
        portal_audit($database, $rateLimited ? 'rate_limited' : 'login_failure', 'rejected', $user === false ? null : (int) $user['user_id'], $normalized);
        return [false, '帳號或密碼錯誤，或帳號暫時無法使用。'];
    }

    $update = $database->prepare(
        'UPDATE dbo.auth_users
         SET failed_login_count = 0, locked_until = NULL, last_login_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
         WHERE user_id = ?'
    );
    $update->execute([(int) $user['user_id']]);
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $database->prepare(
            'UPDATE dbo.auth_users
             SET password_hash = ?, password_changed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
             WHERE user_id = ?'
        );
        $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['user_id']]);
    }

    portal_clear_ip_rate_limit($database, $config);
    portal_audit($database, 'login_success', 'accepted', (int) $user['user_id'], $normalized);
    portal_complete_login_session((int) $user['user_id']);
    return [true, ''];
}

function portal_google_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function portal_google_begin_authorization(array $config): string
{
    if (!portal_google_login_configured($config)) {
        throw new RuntimeException('Google 登入尚未完成設定。');
    }
    $verifier = portal_google_base64url(random_bytes(64));
    $state = portal_google_base64url(random_bytes(32));
    $nonce = portal_google_base64url(random_bytes(32));
    $_SESSION['google_oauth'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'created_at' => time(),
    ];
    $parameters = [
        'client_id' => (string) $config['google_oauth_client_id'],
        'redirect_uri' => (string) $config['google_oauth_redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => portal_google_base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

function portal_google_validate_identity(array $claims, string $clientId, string $nonce): array
{
    $issuer = (string) ($claims['iss'] ?? '');
    $expires = (int) ($claims['exp'] ?? 0);
    $verified = $claims['email_verified'] ?? false;
    $email = portal_normalize_google_email((string) ($claims['email'] ?? ''));
    $subject = (string) ($claims['sub'] ?? '');
    if (!hash_equals($clientId, (string) ($claims['aud'] ?? ''))
        || !in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
        || $expires < time()
        || !in_array($verified, [true, 1, '1', 'true'], true)
        || $email === ''
        || preg_match('/\A[0-9]{1,255}\z/', $subject) !== 1
        || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
        throw new RuntimeException('Google 帳號驗證失敗。');
    }
    return ['email' => $email, 'subject' => $subject];
}

function portal_google_curl_tls_options(string $caBundle): array
{
    $resolved = realpath($caBundle);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        throw new RuntimeException('Google TLS 憑證設定無法使用。');
    }
    return [
        CURLOPT_CAINFO => $resolved,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
}

function portal_google_http_json(string $url, string $caBundle, array $postFields = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Google 驗證服務目前無法使用。');
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, array_replace([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ], portal_google_curl_tls_options($caBundle)));
    if ($postFields !== []) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($postFields, '', '&', PHP_QUERY_RFC3986));
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
    }
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($body) || $status !== 200 || strlen($body) > 65536) {
        throw new RuntimeException('Google 驗證服務目前無法使用。');
    }
    $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Google 驗證回應格式不正確。');
    }
    return $decoded;
}

function portal_google_exchange_code(array $config, string $code, string $verifier, string $nonce): array
{
    if ($code === '' || strlen($code) > 4096 || $verifier === '') {
        throw new RuntimeException('Google 登入回應不完整。');
    }
    $tokens = portal_google_http_json('https://oauth2.googleapis.com/token', (string) $config['google_ca_bundle'], [
        'code' => $code,
        'client_id' => (string) $config['google_oauth_client_id'],
        'client_secret' => (string) $config['google_oauth_client_secret'],
        'redirect_uri' => (string) $config['google_oauth_redirect_uri'],
        'grant_type' => 'authorization_code',
        'code_verifier' => $verifier,
    ]);
    $idToken = (string) ($tokens['id_token'] ?? '');
    if ($idToken === '' || strlen($idToken) > 16384) {
        throw new RuntimeException('Google 登入回應缺少身分憑證。');
    }
    $claims = portal_google_http_json(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken),
        (string) $config['google_ca_bundle']
    );
    return portal_google_validate_identity($claims, (string) $config['google_oauth_client_id'], $nonce);
}

function portal_attempt_google_login(PDO $database, array $config, array $identity): array
{
    $email = portal_normalize_google_email((string) ($identity['email'] ?? ''));
    $subject = (string) ($identity['subject'] ?? '');
    if ($email === '' || preg_match('/\A[0-9]{1,255}\z/', $subject) !== 1 || portal_is_ip_locked($database, $config)) {
        portal_audit($database, 'google_login_failure', 'rejected', null, $email === '' ? null : $email);
        return [false, '此 Google 帳號尚未由管理員綁定，或帳號暫時無法使用。'];
    }
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'SELECT user_id, username, is_active, google_subject,
                    CASE WHEN locked_until > SYSUTCDATETIME() THEN 1 ELSE 0 END AS is_locked
             FROM dbo.auth_users WITH (UPDLOCK, HOLDLOCK) WHERE google_email_normalized = ?'
        );
        $statement->execute([$email]);
        $user = $statement->fetch();
        $valid = $user !== false && (int) $user['is_active'] === 1 && (int) $user['is_locked'] !== 1
            && (((string) ($user['google_subject'] ?? '')) === '' || hash_equals((string) $user['google_subject'], $subject));
        if (!$valid) {
            $database->rollBack();
            portal_register_ip_failure($database, $config);
            portal_audit($database, 'google_login_failure', 'rejected', $user === false ? null : (int) $user['user_id'], $email);
            return [false, '此 Google 帳號尚未由管理員綁定，或帳號暫時無法使用。'];
        }
        $update = $database->prepare(
            'UPDATE dbo.auth_users
             SET google_subject = COALESCE(google_subject, ?), google_last_login_at = SYSUTCDATETIME(),
                 failed_login_count = 0, locked_until = NULL, last_login_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
             WHERE user_id = ?'
        );
        $update->execute([$subject, (int) $user['user_id']]);
        portal_audit($database, 'google_login_success', 'accepted', (int) $user['user_id'], $email);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    portal_clear_ip_rate_limit($database, $config);
    portal_complete_login_session((int) $user['user_id']);
    return [true, ''];
}

function portal_get_document_types(PDO $database, bool $includeInactive = false): array
{
    $sql = 'SELECT document_type_id, type_code, display_name, description, sort_order, is_active
            FROM dbo.document_types';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, display_name';
    return $database->query($sql)->fetchAll();
}

function portal_document_type_links_available(PDO $database): bool
{
    static $availability = [];
    $key = spl_object_id($database);
    if (!array_key_exists($key, $availability)) {
        $statement = $database->query(
            "SELECT CASE WHEN OBJECT_ID(N'dbo.document_type_links', N'U') IS NULL THEN 0 ELSE 1 END"
        );
        $availability[$key] = (int) $statement->fetchColumn() === 1;
    }
    return $availability[$key];
}

function portal_require_document_type_links(PDO $database): void
{
    if (!portal_document_type_links_available($database)) {
        throw new RuntimeException('文件種類多選資料表或權限尚未完成部署。');
    }
}

function portal_get_ssdlc_phases(PDO $database, bool $includeInactive = false): array
{
    $sql = 'SELECT phase_id, phase_code, display_name, description, sort_order, is_active
            FROM dbo.ssdlc_phases';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, phase_code';
    return $database->query($sql)->fetchAll();
}

function portal_catalog_filter_values(array $source, string $key, callable $normalize, int $limit = 50): array
{
    $raw = $source[$key] ?? [];
    $raw = is_array($raw) ? $raw : [$raw];
    $values = [];
    foreach (array_slice($raw, 0, $limit) as $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $normalized = $normalize((string) $value);
        if ($normalized !== '' && !in_array($normalized, $values, true)) {
            $values[] = $normalized;
        }
    }
    return $values;
}

function portal_catalog_filters(array $source): array
{
    $view = (string) ($source['view'] ?? 'stage');
    $view = in_array($view, ['stage', 'type', 'directory'], true) ? $view : 'stage';
    $normalizeStage = static function (string $value): string {
        $value = trim($value);
        return preg_match('/\A[0-9]{2}\z/', $value) === 1 ? $value : '';
    };
    $normalizeRole = static function (string $value): string {
        return in_array($value, ['input', 'output'], true) ? $value : '';
    };
    $normalizeType = static fn(string $value): string => portal_normalize_code($value);
    $stages = portal_catalog_filter_values($source, 'stage', $normalizeStage);
    $roles = portal_catalog_filter_values($source, 'role', $normalizeRole, 2);
    $types = portal_catalog_filter_values($source, 'type', $normalizeType);
    $search = portal_trim_text((string) ($source['q'] ?? ''), 120);
    $hasStageState = array_key_exists('stage_state', $source);
    $stageStates = portal_catalog_filter_values($source, 'stage_state', $normalizeStage);
    $hasRoleState = array_key_exists('role_state', $source);
    $roleStates = portal_catalog_filter_values($source, 'role_state', $normalizeRole, 2);
    $hasTypeState = array_key_exists('type_state', $source);
    $typeStates = portal_catalog_filter_values($source, 'type_state', $normalizeType);
    if ($view === 'stage') {
        if (!$hasTypeState && $types !== []) {
            $typeStates = $types;
        }
        $types = [];
    } elseif ($view === 'type') {
        if (!$hasStageState && $stages !== []) {
            $stageStates = $stages;
        }
        if (!$hasRoleState && $roles !== []) {
            $roleStates = $roles;
        }
        $stages = [];
        $roles = [];
    } else {
        if (!$hasStageState && $stages !== []) {
            $stageStates = $stages;
        }
        if (!$hasRoleState && $roles !== []) {
            $roleStates = $roles;
        }
        if (!$hasTypeState && $types !== []) {
            $typeStates = $types;
        }
        $stages = [];
        $roles = [];
        $types = [];
    }
    $sort = (string) ($source['sort'] ?? 'modified_desc');
    if (!in_array($sort, [
        'created_desc', 'created_asc', 'modified_desc', 'modified_asc', 'name_asc', 'name_desc',
    ], true)) {
        $sort = 'modified_desc';
    }
    return [
        'view' => $view,
        'stages' => $stages, 'roles' => $roles, 'types' => $types,
        'stage_states' => $stageStates, 'role_states' => $roleStates, 'type_states' => $typeStates,
        // Scalar aliases retain compatibility for existing single-value links and renderers.
        'stage' => count($stages) === 1 ? $stages[0] : '',
        'role' => count($roles) === 1 ? $roles[0] : 'all',
        'type' => count($types) === 1 ? $types[0] : '',
        'stage_state' => count($stageStates) === 1 ? $stageStates[0] : '',
        'role_state' => count($roleStates) === 1 ? $roleStates[0] : 'all',
        'type_state' => count($typeStates) === 1 ? $typeStates[0] : '',
        'search' => $search, 'sort' => $sort,
    ];
}

function portal_build_document_directory_tree(array $documents): array
{
    $root = ['name' => '/', 'path' => '', 'directories' => [], 'documents' => []];
    $seen = [];
    foreach ($documents as $document) {
        $relativePath = str_replace('\\', '/', (string) ($document['relative_path'] ?? ''));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:\//', $relativePath) === 1) {
            continue;
        }
        $pathKey = mb_strtolower($relativePath, 'UTF-8');
        if (isset($seen[$pathKey])) {
            continue;
        }
        $segments = explode('/', $relativePath);
        $fileName = array_pop($segments);
        if ($fileName === '' || $fileName === '.' || $fileName === '..' || array_filter($segments, static fn(string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []) {
            continue;
        }
        $seen[$pathKey] = true;
        $node =& $root;
        $path = [];
        foreach ($segments as $segment) {
            $path[] = $segment;
            $key = mb_strtolower($segment, 'UTF-8');
            if (!isset($node['directories'][$key])) {
                $node['directories'][$key] = ['name' => $segment, 'path' => implode('/', $path), 'directories' => [], 'documents' => []];
            }
            $node =& $node['directories'][$key];
        }
        $node['documents'][] = $document;
        unset($node);
    }
    $sortDirectories = static function (array &$node) use (&$sortDirectories): void {
        uasort($node['directories'], static fn(array $left, array $right): int => strnatcasecmp((string) $left['name'], (string) $right['name']));
        foreach ($node['directories'] as &$child) {
            $sortDirectories($child);
        }
        unset($child);
    };
    $sortDirectories($root);
    return $root;
}

function portal_admin_catalog_filters(array $source): array
{
    $filenameSearch = portal_trim_text((string) ($source['file_q'] ?? ''), 120);
    $type = portal_normalize_code((string) ($source['type'] ?? ''));
    $extension = strtolower(trim((string) ($source['extension'] ?? '')));
    if (!in_array($extension, ['', 'md', 'txt', 'pdf', 'docx', 'xlsx', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
        $extension = '';
    }
    $modified = (string) ($source['modified'] ?? '');
    if (!in_array($modified, ['', '7d', '30d', '90d', '365d'], true)) {
        $modified = '';
    }
    $sort = (string) ($source['sort'] ?? 'modified_desc');
    if (!in_array($sort, [
        'modified_desc', 'modified_asc', 'filename_asc', 'filename_desc',
        'title_asc', 'title_desc', 'extension_asc', 'extension_desc',
    ], true)) {
        $sort = 'modified_desc';
    }
    return [
        'view' => 'type',
        'stage' => '',
        'role' => 'all',
        'search' => '',
        'filename_search' => $filenameSearch,
        'type' => $type,
        'extension' => $extension,
        'modified' => $modified,
        'sort' => $sort,
    ];
}

function portal_escape_like_pattern(string $value): string
{
    return str_replace(['~', '%', '_'], ['~~', '~%', '~_'], $value);
}

function portal_list_catalog_documents(PDO $database, array $user, array $filters): array
{
    portal_require_document_type_links($database);
    $where = [];
    $parameters = [];
    if (empty($filters['include_archived'])) {
        $where[] = "d.status_code <> 'archived'";
    }
    if (!portal_can_edit_documents($user)) {
        $where[] = "d.status_code = 'published'";
    }
    if (!empty($filters['exclude_visual_assets'])) {
        $where[] = "d.extension NOT IN ('png','jpg','jpeg','webp','gif')";
    }
    if (!empty($filters['exclude_internal_markdown'])) {
        $where[] = "d.extension <> 'md'";
    }
    $types = array_values($filters['types'] ?? (($filters['type'] ?? '') !== '' ? [(string) $filters['type']] : []));
    if ($types !== []) {
        $placeholders = implode(',',array_fill(0,count($types),'?'));
        $where[] = 'EXISTS (
                SELECT 1 FROM dbo.document_type_links type_link
                INNER JOIN dbo.document_types filter_type ON filter_type.document_type_id = type_link.document_type_id
                WHERE type_link.document_id = d.document_id AND filter_type.type_code IN (' . $placeholders . ')
              )';
        array_push($parameters,...$types);
    }
    $stages = array_values($filters['stages'] ?? (($filters['stage'] ?? '') !== '' ? [(string) $filters['stage']] : []));
    $roles = array_values($filters['roles'] ?? (in_array(($filters['role'] ?? 'all'), ['input','output'], true) ? [(string) $filters['role']] : []));
    if ($stages !== [] || $roles !== []) {
        $subquery = 'EXISTS (
            SELECT 1
            FROM dbo.document_phase_roles pr
            INNER JOIN dbo.ssdlc_phases p ON p.phase_id = pr.phase_id
            WHERE pr.document_id = d.document_id';
        if ($stages !== []) {
            $placeholders = implode(',',array_fill(0,count($stages),'?'));
            $subquery .= ' AND p.phase_code IN (' . $placeholders . ')';
            array_push($parameters,...$stages);
        }
        if ($roles !== []) {
            $placeholders = implode(',',array_fill(0,count($roles),'?'));
            $subquery .= ' AND pr.role_code IN (' . $placeholders . ')';
            array_push($parameters,...$roles);
        }
        $subquery .= ')';
        $where[] = $subquery;
    }
    if (($filters['search'] ?? '') !== '') {
        $typeSearch = 'EXISTS (
                SELECT 1 FROM dbo.document_type_links search_link
                INNER JOIN dbo.document_types search_type ON search_type.document_type_id = search_link.document_type_id
                WHERE search_link.document_id = d.document_id AND search_type.display_name LIKE ?
              )';
        $where[] = '(d.title LIKE ? OR d.file_name LIKE ? OR d.summary LIKE ? OR ' . $typeSearch . ')';
        $like = '%' . $filters['search'] . '%';
        array_push($parameters, $like, $like, $like, $like);
    }
    if (($filters['filename_search'] ?? '') !== '') {
        $where[] = "d.file_name LIKE ? ESCAPE '~'";
        $parameters[] = '%' . portal_escape_like_pattern($filters['filename_search']) . '%';
    }
    if (($filters['extension'] ?? '') !== '') {
        $where[] = 'd.extension = ?';
        $parameters[] = $filters['extension'];
    }
    $modifiedDays = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365];
    if (isset($modifiedDays[$filters['modified'] ?? ''])) {
        $where[] = 'd.source_modified_at >= DATEADD(DAY, ?, SYSUTCDATETIME())';
        $parameters[] = -$modifiedDays[$filters['modified']];
    }
    $sql = 'SELECT TOP ' . PORTAL_DOCUMENT_LIST_LIMIT . '
                d.document_id, CONVERT(varchar(36), d.public_id) AS public_id, d.relative_path, d.file_name, d.title, d.extension,
                d.file_size_bytes, d.source_modified_at, d.created_at, d.updated_at, d.status_code, d.summary, LOWER(d.content_hash) AS content_hash
            FROM dbo.documents d';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $orderBy = [
        'created_desc' => 'd.created_at DESC, d.document_id DESC',
        'created_asc' => 'd.created_at ASC, d.document_id ASC',
        'modified_desc' => 'd.source_modified_at DESC, d.document_id DESC',
        'modified_asc' => 'd.source_modified_at ASC, d.document_id ASC',
        'name_asc' => 'd.title ASC, d.file_name ASC, d.document_id ASC',
        'name_desc' => 'd.title DESC, d.file_name DESC, d.document_id DESC',
        'filename_asc' => 'd.file_name ASC, d.document_id ASC',
        'filename_desc' => 'd.file_name DESC, d.document_id DESC',
        'title_asc' => 'd.title ASC, d.document_id ASC',
        'title_desc' => 'd.title DESC, d.document_id DESC',
        'extension_asc' => 'd.extension ASC, d.file_name ASC',
        'extension_desc' => 'd.extension DESC, d.file_name ASC',
    ];
    $sql .= ' ORDER BY ' . ($orderBy[$filters['sort'] ?? 'modified_desc'] ?? $orderBy['modified_desc']);
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    $documents = $statement->fetchAll();
    return portal_attach_document_facets($database, $documents);
}

function portal_attach_document_types(PDO $database, array $documents): array
{
    if ($documents === []) {
        return $documents;
    }
    $ids = array_map(static fn(array $document): int => (int) $document['document_id'], $documents);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    portal_require_document_type_links($database);
    $sql = 'SELECT link.document_id, dt.document_type_id, dt.type_code, dt.display_name, dt.description, dt.sort_order
            FROM dbo.document_type_links link
            INNER JOIN dbo.document_types dt ON dt.document_type_id = link.document_type_id
            WHERE link.document_id IN (' . $placeholders . ')
            ORDER BY dt.sort_order, dt.display_name';
    $statement = $database->prepare($sql);
    $statement->execute($ids);
    $typesByDocument = [];
    foreach ($statement->fetchAll() as $type) {
        $typesByDocument[(int) $type['document_id']][] = $type;
    }
    foreach ($documents as &$document) {
        $document['document_types'] = $typesByDocument[(int) $document['document_id']] ?? [];
        $firstType = $document['document_types'][0] ?? null;
        $document['type_code'] = $firstType === null ? null : (string) $firstType['type_code'];
        $document['document_type_name'] = $firstType === null ? null : (string) $firstType['display_name'];
    }
    unset($document);
    return $documents;
}

function portal_attach_document_phase_roles(PDO $database, array $documents): array
{
    if ($documents === []) {
        return $documents;
    }
    $ids = array_map(static fn(array $document): int => (int) $document['document_id'], $documents);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $database->prepare(
        'SELECT pr.document_id, p.phase_code, p.display_name, p.sort_order, pr.role_code
         FROM dbo.document_phase_roles pr
         INNER JOIN dbo.ssdlc_phases p ON p.phase_id = pr.phase_id
         WHERE pr.document_id IN (' . $placeholders . ')
         ORDER BY p.sort_order, pr.role_code'
    );
    $statement->execute($ids);
    $rolesByDocument = [];
    foreach ($statement->fetchAll() as $role) {
        $rolesByDocument[(int) $role['document_id']][] = $role;
    }
    foreach ($documents as &$document) {
        $document['phase_roles'] = $rolesByDocument[(int) $document['document_id']] ?? [];
    }
    unset($document);
    return $documents;
}

function portal_attach_document_facets(PDO $database, array $documents): array
{
    return portal_attach_document_phase_roles($database, portal_attach_document_types($database, $documents));
}

function portal_valid_public_id(string $value): string
{
    $value = strtolower(trim($value));
    return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) === 1 ? $value : '';
}

function portal_get_catalog_document(PDO $database, array $user, string $publicId): ?array
{
    $publicId = portal_valid_public_id($publicId);
    if ($publicId === '') {
        return null;
    }
    $sql = 'SELECT d.document_id, CONVERT(varchar(36), d.public_id) AS public_id, d.relative_path, d.file_name, d.title,
                   d.extension, d.file_size_bytes, d.source_modified_at, d.content_hash, d.status_code, d.summary,
                   d.published_at, d.created_at, d.updated_at, d.current_version_id, d.version_capture_status,
                   d.version_capture_error_code, LOWER(cv.source_content_hash) AS current_version_hash
            FROM dbo.documents d
            LEFT JOIN dbo.document_versions cv ON cv.version_id=d.current_version_id AND cv.document_id=d.document_id
            WHERE d.public_id = ?';
    if (!portal_can_edit_documents($user)) {
        $sql .= " AND d.status_code = 'published'";
    }
    $statement = $database->prepare($sql);
    $statement->execute([$publicId]);
    $document = $statement->fetch();
    if ($document === false) {
        return null;
    }
    return portal_attach_document_facets($database, [$document])[0];
}

function portal_document_version_delivery_ready(array $document): bool
{
    if (!array_key_exists('version_capture_status', $document)) {
        return true;
    }
    $catalogHash = strtolower((string) ($document['content_hash'] ?? ''));
    $versionHash = strtolower((string) ($document['current_version_hash'] ?? ''));
    return (string) ($document['version_capture_status'] ?? '') === 'ready'
        && (int) ($document['current_version_id'] ?? 0) > 0
        && preg_match('/\A[0-9a-f]{64}\z/', $catalogHash) === 1
        && hash_equals($catalogHash, $versionHash);
}

function portal_open_verified_document_handle(string $path, string $expectedHash, int $expectedSize)
{
    $expectedHash = strtolower(trim($expectedHash));
    if (preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1 || $expectedSize < 0 || $expectedSize > PORTAL_DOCUMENT_HASH_MAX_BYTES) {
        throw new RuntimeException('文件驗證資料無效。');
    }
    $handle = fopen($path, 'rb');
    if (!is_resource($handle) || !flock($handle, LOCK_SH)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('無法鎖定文件內容。');
    }
    $stat = fstat($handle);
    $context = hash_init('sha256');
    hash_update_stream($context, $handle);
    $actualHash = hash_final($context);
    if (!is_array($stat) || (int) ($stat['size'] ?? -1) !== $expectedSize || !hash_equals($expectedHash, $actualHash) || !rewind($handle)) {
        flock($handle, LOCK_UN); fclose($handle);
        throw new RuntimeException('文件內容與安全版本不一致。');
    }
    return $handle;
}

function portal_document_file_path(array $config, array $document): string
{
    if (!portal_document_version_delivery_ready($document)) {
        throw new RuntimeException('文件版本內容尚未安全就緒。');
    }
    $relativePath = (string) ($document['relative_path'] ?? '');
    if ($relativePath === '' || str_contains($relativePath, "\0")) {
        throw new RuntimeException('文件路徑無效。');
    }
    $root = realpath($config['document_root']);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('文件來源目錄尚未正確設定。');
    }
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
    if (preg_match('#(^|[\\\\/])\.\.?(?:$|[\\\\/])#', $normalized) === 1) {
        throw new RuntimeException('文件路徑無效。');
    }
    foreach (preg_split('#[\\\\/]#', $normalized) as $segment) {
        if ($segment !== '' && str_starts_with($segment, '.')) {
            throw new RuntimeException('文件路徑無效。');
        }
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $normalized);
    $prefix = strtolower(rtrim($root, '\\/') . DIRECTORY_SEPARATOR);
    if ($path === false || !str_starts_with(strtolower($path), $prefix) || !is_file($path)) {
        throw new RuntimeException('找不到指定文件。');
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['md', 'txt', 'pdf', 'docx', 'xlsx', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true) || $extension !== strtolower((string) $document['extension'])) {
        throw new RuntimeException('此類型不允許提供。');
    }
    if (array_key_exists('version_capture_status', $document)) {
        $verifiedHandle = portal_open_verified_document_handle($path, (string) $document['content_hash'], (int) $document['file_size_bytes']);
        flock($verifiedHandle, LOCK_UN);
        fclose($verifiedHandle);
    }
    return $path;
}

function portal_document_inline_allowed(string $extension): bool
{
    return in_array(strtolower($extension), ['pdf', 'pptx', 'md', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true);
}

function portal_document_content_disposition(string $extension, bool $inline): string
{
    return $inline && portal_document_inline_allowed($extension) ? 'inline' : 'attachment';
}

function portal_stream_document(string $path, array $document, bool $inline = false, string $expectedHash = '', ?int $expectedSize = null): void
{
    $expectedHash = $expectedHash === '' ? (string) ($document['content_hash'] ?? '') : $expectedHash;
    $expectedSize = $expectedSize ?? (int) ($document['file_size_bytes'] ?? -1);
    $handle = portal_open_verified_document_handle($path, $expectedHash, $expectedSize);
    $contentTypes = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'md' => 'text/markdown; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];
    $extension = strtolower((string) $document['extension']);
    $type = $contentTypes[$extension] ?? 'application/octet-stream';
    $disposition = portal_document_content_disposition($extension, $inline);
    header('Content-Type: ' . $type);
    header('Content-Length: ' . (string) $expectedSize);
    header('Content-Disposition: ' . $disposition . '; filename*=UTF-8\'\'' . rawurlencode((string) $document['file_name']));
    header('X-Content-Type-Options: nosniff');
    fpassthru($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    exit;
}

function portal_relative_path_from_file(string $root, SplFileInfo $file): ?string
{
    $absolute = $file->getRealPath();
    $prefix = strtolower(rtrim($root, '\\/') . DIRECTORY_SEPARATOR);
    if ($absolute === false || !str_starts_with(strtolower($absolute), $prefix)) {
        return null;
    }
    return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen(rtrim($root, '\\/')) + 1));
}

function portal_document_path_component_is_excluded(string $name, bool $directSyncMode = false): bool
{
    if ($name === '' || str_starts_with($name, '~$')) return true;
    $excluded = $directSyncMode
        ? ['.discord_log', '.discord_uploads', '.npm-cache', '.ppt_build', '.pptx_build_tool', '_pptx_build_tool', '.transcription_tmp', 'maintenance-backups', 'tmp', 'verification', '供水監測雛型網站']
        : ['node_modules', 'vendor', 'tmp', '_pptx_build_tool'];
    return in_array(strtolower($name), array_map('strtolower', $excluded), true);
}

function portal_scan_document_source(array $config): array
{
    $root = realpath($config['document_root']);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('文件來源目錄尚未正確設定。');
    }
    $allowed = ['md', 'txt', 'pdf', 'docx', 'xlsx', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'gif'];
    $files = [];
    try {
        $directoryIterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    } catch (UnexpectedValueException $exception) {
        throw new RuntimeException('文件來源目錄無法列舉。', 0, $exception);
    }
    $filteredIterator = new RecursiveCallbackFilterIterator(
        $directoryIterator,
        static function (SplFileInfo $current): bool {
            if ($current->isLink() || portal_document_path_component_is_excluded($current->getFilename())) {
                return false;
            }
            return true;
        }
    );
    $iterator = new RecursiveIteratorIterator($filteredIterator, RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()
            || portal_document_path_component_is_excluded($file->getFilename())) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, $allowed, true)) {
            continue;
        }
        $relativePath = portal_relative_path_from_file($root, $file);
        if ($relativePath === null) {
            continue;
        }
        $size = $file->getSize();
        $path = $file->getRealPath();
        $hash = ($path !== false && $size <= PORTAL_DOCUMENT_HASH_MAX_BYTES) ? hash_file('sha256', $path) : null;
        if (count($files) >= PORTAL_DOCUMENT_SYNC_LIMIT) {
            throw new RuntimeException('文件數量超過單次索引上限，未進行不完整索引。');
        }
        $files[] = [
            'relative_path' => $relativePath,
            'file_name' => $file->getFilename(),
            'title' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
            'extension' => $extension,
            'file_size_bytes' => $size,
            'source_modified_at' => gmdate('Y-m-d H:i:s', $file->getMTime()),
            'content_hash' => $hash === false ? null : $hash,
        ];
    }
    usort($files, static fn(array $left, array $right): int => strcmp($left['relative_path'], $right['relative_path']));
    return $files;
}

function portal_acquire_document_sync_lock(array $config)
{
    $lockPath = rtrim((string) $config['session_save_path'], '\\/')
        . DIRECTORY_SEPARATOR . 'document-index.lock';
    $handle = @fopen($lockPath, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('無法建立文件索引鎖定。');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('文件索引正在進行中，請稍後再試。');
    }
    return $handle;
}

function portal_sync_documents(PDO $database, array $config, ?array $actor): array
{
    $lockHandle = portal_acquire_document_sync_lock($config);
    try {
        $summary = portal_sync_documents_locked($database, $config, $actor);
        $versionSummary = function_exists('portal_capture_current_document_version_contents')
            ? portal_capture_current_document_version_contents($database, $config, $actor)
            : ['enabled' => false, 'captured' => 0, 'already_ready' => 0, 'failed' => 0, 'errors' => []];
        $summary['version_capture_enabled'] = (bool) ($versionSummary['enabled'] ?? false);
        $summary['version_required'] = (int) ($versionSummary['required'] ?? 0);
        $summary['version_captured'] = (int) ($versionSummary['captured'] ?? 0);
        $summary['version_already_ready'] = (int) ($versionSummary['already_ready'] ?? 0);
        $summary['version_ready'] = (int) ($versionSummary['ready'] ?? 0);
        $summary['version_failed'] = (int) ($versionSummary['failed'] ?? 0);
        $summary['version_complete'] = (bool) ($versionSummary['complete'] ?? false);
        $summary['version_errors'] = (array) ($versionSummary['errors'] ?? []);
        return $summary;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function portal_sync_documents_locked(PDO $database, array $config, ?array $actor): array
{
    $actorId = $actor === null ? null : (int) $actor['user_id'];
    $start = $database->prepare(
        'INSERT INTO dbo.document_sync_runs (initiated_by_user_id) OUTPUT INSERTED.sync_run_id VALUES (?)'
    );
    $start->execute([$actorId]);
    $runId = (int) $start->fetchColumn();
    $summary = [
        'discovered' => 0,
        'inserted' => 0,
        'updated' => 0,
        'drafted' => 0,
        'archived' => 0,
        'skipped' => 0,
    ];
    try {
        $files = portal_scan_document_source($config);
        $versionApprovals = function_exists('portal_read_document_version_approvals')
            ? portal_read_document_version_approvals($config)
            : [];
        $consumedVersionApprovals = [];
        $summary['discovered'] = count($files);
        $database->beginTransaction();
        $existing = $database->prepare(
            'SELECT document_id, CONVERT(varchar(36), public_id) AS public_id,
                    file_size_bytes, source_modified_at, content_hash, status_code
             FROM dbo.documents WHERE relative_path = ?'
        );
        $insert = $database->prepare(
            'INSERT INTO dbo.documents
                (relative_path, file_name, title, extension, file_size_bytes, source_modified_at, content_hash, status_code, created_by_user_id, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $update = $database->prepare(
            "UPDATE dbo.documents
             SET file_name = ?, extension = ?, file_size_bytes = ?, source_modified_at = ?, content_hash = ?,
                 status_code = CASE WHEN ? = 1 AND status_code = 'published' THEN 'draft' ELSE status_code END,
                 published_at = CASE WHEN ? = 1 AND status_code = 'published' THEN NULL ELSE published_at END,
                 updated_by_user_id = ?, updated_at = SYSUTCDATETIME()
             WHERE document_id = ?"
        );
        $seenPaths = [];
        foreach ($files as $file) {
            $relativePath = (string) $file['relative_path'];
            $seenPaths[strtolower($relativePath)] = true;
            $existing->execute([$relativePath]);
            $row = $existing->fetch();
            if ($row === false) {
                $insert->execute([
                    $relativePath, $file['file_name'], $file['title'], $file['extension'],
                    $file['file_size_bytes'], $file['source_modified_at'], $file['content_hash'], 'draft', $actorId, $actorId,
                ]);
                $summary['inserted']++;
                continue;
            }
            $storedModifiedAt = strtotime((string) $row['source_modified_at'] . ' UTC');
            $sourceModifiedAt = strtotime((string) $file['source_modified_at'] . ' UTC');
            $storedHash = (string) ($row['content_hash'] ?? '');
            $sourceHash = (string) ($file['content_hash'] ?? '');
            $approvalKey = strtolower(str_replace('\\', '/', $relativePath));
            $approval = $versionApprovals[$approvalKey] ?? null;
            $controlledVersion = is_array($approval)
                && portal_document_version_approval_matches(
                    $approval,
                    ['public_id' => (string) $row['public_id'], 'relative_path' => $relativePath],
                    $sourceHash
                );
            if ($controlledVersion) {
                $consumedVersionApprovals[$approvalKey] = $approval;
                if (function_exists('portal_mark_document_version_operation_indexed')) {
                    portal_mark_document_version_operation_indexed($database, $approval, $sourceHash);
                }
            }
            $sizeChanged = (int) $row['file_size_bytes'] !== (int) $file['file_size_bytes'];
            $timeChanged = $storedModifiedAt !== $sourceModifiedAt;
            $hashChanged = $storedHash !== $sourceHash;
            $changed = $sizeChanged || $timeChanged || $hashChanged;
            if (!$changed) {
                $summary['skipped']++;
                continue;
            }
            $hashesComparable = $storedHash !== '' && $sourceHash !== '';
            $contentChanged = $hashesComparable
                ? !hash_equals($storedHash, $sourceHash)
                : ($sizeChanged || $timeChanged);
            $requiresReview = $contentChanged && !$controlledVersion ? 1 : 0;
            if ($requiresReview === 1 && (string) $row['status_code'] === 'published') {
                $summary['drafted']++;
            }
            $update->execute([
                $file['file_name'], $file['extension'], $file['file_size_bytes'], $file['source_modified_at'],
                $file['content_hash'], $requiresReview, $requiresReview, $actorId, (int) $row['document_id'],
            ]);
            $summary['updated']++;
        }

        $allDocuments = $database->query('SELECT document_id, relative_path, status_code FROM dbo.documents')->fetchAll();
        $archive = $database->prepare(
            "UPDATE dbo.documents
             SET status_code = 'archived', published_at = NULL, updated_by_user_id = ?, updated_at = SYSUTCDATETIME()
             WHERE document_id = ? AND status_code <> 'archived'"
        );
        foreach ($allDocuments as $document) {
            if (isset($seenPaths[strtolower((string) $document['relative_path'])])
                || (string) $document['status_code'] === 'archived') {
                continue;
            }
            $archive->execute([$actorId, (int) $document['document_id']]);
            $summary['archived']++;
        }

        if (function_exists('portal_presentation_reconcile_catalog')) {
            try {
                portal_presentation_reconcile_catalog($database, $actorId);
            } catch (Throwable $previewException) {
                // Preview work is derived data. A queue failure must not make the
                // authoritative document index unavailable; it is logged for an
                // administrator to retry after repairing the preview deployment.
                error_log('TWWATER presentation reconciliation failed: ' . $previewException->getMessage());
            }
        }

        $complete = $database->prepare(
            'UPDATE dbo.document_sync_runs
             SET completed_at = SYSUTCDATETIME(), status_code = ?, discovered_count = ?, inserted_count = ?, updated_count = ?, skipped_count = ?
             WHERE sync_run_id = ?'
        );
        $complete->execute(['completed', $summary['discovered'], $summary['inserted'], $summary['updated'], $summary['skipped'], $runId]);
        portal_document_audit(
            $database,
            'document_sync',
            'accepted',
            $actorId,
            null,
            'run=' . $runId . ';drafted=' . $summary['drafted'] . ';archived=' . $summary['archived']
                . ';controlled_versions=' . count($consumedVersionApprovals)
        );
        $database->commit();
        if ($consumedVersionApprovals !== []) {
            portal_consume_document_version_approvals(array_values($consumedVersionApprovals));
        }
        return $summary;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        try {
            $failed = $database->prepare(
                'UPDATE dbo.document_sync_runs
                 SET completed_at = SYSUTCDATETIME(), status_code = ?, discovered_count = ?, inserted_count = ?, updated_count = ?, skipped_count = ?, error_message = ?
                 WHERE sync_run_id = ?'
            );
            $failed->execute(['failed', $summary['discovered'], $summary['inserted'], $summary['updated'], $summary['skipped'], portal_trim_text($exception->getMessage(), 1000), $runId]);
            portal_document_audit($database, 'document_sync', 'failed', $actorId, null, 'run=' . $runId);
        } catch (Throwable $auditException) {
            error_log('TWWATER document sync failure audit failed: ' . $auditException->getMessage());
        }
        throw $exception;
    }
}

function portal_read_bridge_generation(array $config): ?string
{
    $path = (string) ($config['bridge_signal_path'] ?? '');
    if ($path === '' || !is_file($path) || is_link($path)) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size < 8 || $size > 256) {
        error_log('TWWATER bridge signal has an invalid size.');
        return null;
    }
    $generation = trim((string) @file_get_contents($path));
    if (preg_match('/\A[A-Za-z0-9_.:+-]{8,128}\z/', $generation) !== 1) {
        error_log('TWWATER bridge signal has an invalid generation value.');
        return null;
    }
    return $generation;
}

function portal_bridge_documents_blocked(array $config): bool
{
    if (portal_document_root_is_allowed((string) ($config['document_root'] ?? ''))) {
        return false;
    }
    $syncingPath = (string) ($config['bridge_syncing_path'] ?? '');
    $signalPath = (string) ($config['bridge_signal_path'] ?? '');
    return ($syncingPath !== '' && is_file($syncingPath))
        || ($signalPath !== '' && is_file($signalPath));
}

function portal_process_bridge_signal(PDO $database, array $config): void
{
    $signalPath = (string) ($config['bridge_signal_path'] ?? '');
    $syncingPath = (string) ($config['bridge_syncing_path'] ?? '');
    if ($signalPath === '' || ($syncingPath !== '' && is_file($syncingPath))) {
        return;
    }

    // A new generation may arrive while an earlier one is being indexed. A
    // bounded loop consumes the latest marker without allowing unbounded web
    // request work.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        clearstatcache(true, $signalPath);
        $generation = portal_read_bridge_generation($config);
        if ($generation === null) {
            return;
        }
        try {
            $syncSummary = portal_sync_documents($database, $config, null);
            if (($syncSummary['version_capture_enabled'] ?? false) === true
                && (($syncSummary['version_complete'] ?? false) !== true || (int) ($syncSummary['version_failed'] ?? 0) > 0)) {
                throw new RuntimeException('文件版本內容尚未全部完成，保留橋接訊號等待重試。');
            }
        } catch (Throwable $exception) {
            error_log('TWWATER bridge index deferred: ' . $exception->getMessage());
            return;
        }

        clearstatcache(true, $signalPath);
        $currentGeneration = portal_read_bridge_generation($config);
        if ($currentGeneration === null) {
            return;
        }
        if (hash_equals($generation, $currentGeneration)) {
            if (!@unlink($signalPath)) {
                error_log('TWWATER bridge signal could not be consumed.');
            }
            return;
        }
    }
}

function portal_parse_phase_roles(array $values, PDO $database): array
{
    $available = [];
    foreach (portal_get_ssdlc_phases($database) as $phase) {
        $available[(string) $phase['phase_code']] = (int) $phase['phase_id'];
    }
    $roles = [];
    foreach ($values as $value) {
        $value = (string) $value;
        if (preg_match('/\A([0-9]{2}):(input|output)\z/', $value, $matches) !== 1) {
            continue;
        }
        if (!isset($available[$matches[1]])) {
            continue;
        }
        $roles[$matches[1] . ':' . $matches[2]] = ['phase_id' => $available[$matches[1]], 'role_code' => $matches[2]];
    }
    return array_values($roles);
}

function portal_parse_document_types(array $values, PDO $database): array
{
    $available = [];
    foreach (portal_get_document_types($database) as $type) {
        $available[(string) $type['type_code']] = [
            'document_type_id' => (int) $type['document_type_id'],
            'type_code' => (string) $type['type_code'],
            'sort_order' => (int) $type['sort_order'],
        ];
    }
    $selected = [];
    foreach ($values as $value) {
        $code = portal_normalize_code((string) $value);
        if ($code !== '' && isset($available[$code])) {
            $selected[$code] = $available[$code];
        }
    }
    uasort($selected, static fn(array $first, array $second): int => $first['sort_order'] <=> $second['sort_order']);
    return array_values($selected);
}

function portal_update_document_metadata(PDO $database, array $actor, string $publicId, array $input): void
{
    $document = portal_get_catalog_document($database, $actor, $publicId);
    if ($document === null) {
        throw new RuntimeException('找不到指定文件。');
    }
    $title = portal_trim_text((string) ($input['title'] ?? ''), 255);
    $summary = portal_trim_text((string) ($input['summary'] ?? ''), 1000);
    $status = (string) ($input['status_code'] ?? 'draft');
    if ($title === '' || !in_array($status, ['draft', 'published', 'archived'], true)) {
        throw new RuntimeException('文件標題或狀態不正確。');
    }
    portal_require_document_type_links($database);
    $typeInput = isset($input['type_codes']) && is_array($input['type_codes']) ? $input['type_codes'] : [];
    $types = portal_parse_document_types($typeInput, $database);
    $primaryTypeId = $types === [] ? null : (int) $types[0]['document_type_id'];
    $roleInput = isset($input['phase_roles']) && is_array($input['phase_roles']) ? $input['phase_roles'] : [];
    $roles = portal_parse_phase_roles($roleInput, $database);
    if ($status === 'published' && ($types === [] || $roles === [])) {
        throw new RuntimeException('發布前必須勾選至少一個文件種類與一個 SSDLC 階段角色。');
    }
    $actorId = (int) $actor['user_id'];

    $database->beginTransaction();
    try {
        $update = $database->prepare(
            'UPDATE dbo.documents
             SET title = ?, summary = ?, document_type_id = ?, status_code = ?,
                 published_at = CASE
                     WHEN ? = \'published\' AND status_code <> \'published\' THEN SYSUTCDATETIME()
                     WHEN ? <> \'published\' THEN NULL
                     ELSE published_at END,
                 updated_by_user_id = ?, updated_at = SYSUTCDATETIME()
             WHERE document_id = ?'
        );
        $update->execute([$title, $summary === '' ? null : $summary, $primaryTypeId, $status, $status, $status, $actorId, (int) $document['document_id']]);
        $deleteTypes = $database->prepare('DELETE FROM dbo.document_type_links WHERE document_id = ?');
        $deleteTypes->execute([(int) $document['document_id']]);
        $insertType = $database->prepare(
            'INSERT INTO dbo.document_type_links (document_id, document_type_id, created_by_user_id) VALUES (?, ?, ?)'
        );
        foreach ($types as $type) {
            $insertType->execute([(int) $document['document_id'], (int) $type['document_type_id'], $actorId]);
        }
        $delete = $database->prepare('DELETE FROM dbo.document_phase_roles WHERE document_id = ?');
        $delete->execute([(int) $document['document_id']]);
        $insert = $database->prepare(
            'INSERT INTO dbo.document_phase_roles (document_id, phase_id, role_code, created_by_user_id) VALUES (?, ?, ?, ?)'
        );
        foreach ($roles as $role) {
            $insert->execute([(int) $document['document_id'], $role['phase_id'], $role['role_code'], $actorId]);
        }
        portal_document_audit(
            $database,
            'document_metadata_update',
            'accepted',
            $actorId,
            (int) $document['document_id'],
            'status=' . $status . ';types=' . count($types) . ';phase_roles=' . count($roles)
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function portal_admin_batch_selection(array $input): array
{
    $values = isset($input['document_ids']) && is_array($input['document_ids'])
        ? $input['document_ids']
        : [];
    if (count($values) > PORTAL_DOCUMENT_LIST_LIMIT) {
        throw new RuntimeException('一次最多可批次設定 ' . PORTAL_DOCUMENT_LIST_LIMIT . ' 份文件。');
    }
    $selected = [];
    foreach ($values as $value) {
        $publicId = portal_valid_public_id((string) $value);
        if ($publicId !== '') {
            $selected[$publicId] = true;
        }
    }
    return array_keys($selected);
}

function portal_admin_batch_mode(string $value): string
{
    if ($value === '' || $value === 'unchanged') {
        return 'unchanged';
    }
    if (!in_array($value, ['replace', 'add', 'remove'], true)) {
        throw new RuntimeException('批次套用方式不正確。');
    }
    return $value;
}

function portal_apply_batch_set(array $current, array $selected, string $mode): array
{
    $mode = portal_admin_batch_mode($mode);
    $currentSet = [];
    foreach ($current as $value) {
        $currentSet[(string) $value] = $value;
    }
    $selectedSet = [];
    foreach ($selected as $value) {
        $selectedSet[(string) $value] = $value;
    }
    if ($mode === 'replace') {
        return array_values($selectedSet);
    }
    if ($mode === 'add') {
        foreach ($selectedSet as $key => $value) {
            $currentSet[$key] = $value;
        }
        return array_values($currentSet);
    }
    if ($mode === 'remove') {
        foreach ($selectedSet as $key => $_value) {
            unset($currentSet[$key]);
        }
    }
    return array_values($currentSet);
}

function portal_bulk_update_document_metadata(PDO $database, array $actor, array $input): int
{
    $publicIds = portal_admin_batch_selection($input);
    if ($publicIds === []) {
        throw new RuntimeException('請至少選取一份文件。');
    }
    $status = (string) ($input['status_code'] ?? '');
    if (!in_array($status, ['', 'draft', 'published', 'archived'], true)) {
        throw new RuntimeException('批次發布狀態不正確。');
    }
    $typeMode = portal_admin_batch_mode((string) ($input['type_mode'] ?? 'unchanged'));
    $phaseMode = portal_admin_batch_mode((string) ($input['phase_mode'] ?? 'unchanged'));
    if ($status === '' && $typeMode === 'unchanged' && $phaseMode === 'unchanged') {
        throw new RuntimeException('請至少選擇一項要套用的批次屬性。');
    }
    portal_require_document_type_links($database);
    $types = $typeMode === 'unchanged'
        ? []
        : portal_parse_document_types(isset($input['type_codes']) && is_array($input['type_codes']) ? $input['type_codes'] : [], $database);
    $roles = $phaseMode === 'unchanged'
        ? []
        : portal_parse_phase_roles(isset($input['phase_roles']) && is_array($input['phase_roles']) ? $input['phase_roles'] : [], $database);
    if ($typeMode !== 'unchanged' && $types === []) {
        throw new RuntimeException('請先勾選至少一個要套用的文件種類。');
    }
    if ($phaseMode !== 'unchanged' && $roles === []) {
        throw new RuntimeException('請先勾選至少一個要套用的 SSDLC 階段角色。');
    }
    $placeholders = implode(',', array_fill(0, count($publicIds), '?'));
    $actorId = (int) $actor['user_id'];

    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'SELECT d.document_id, CONVERT(varchar(36), d.public_id) AS public_id, d.status_code
             FROM dbo.documents d WITH (UPDLOCK, ROWLOCK)
             WHERE CONVERT(varchar(36), d.public_id) IN (' . $placeholders . ')'
        );
        $statement->execute($publicIds);
        $documents = $statement->fetchAll();
        if (count($documents) !== count($publicIds)) {
            throw new RuntimeException('部分文件已不存在，請重新整理後再試。');
        }

        $deleteAllTypes = $database->prepare('DELETE FROM dbo.document_type_links WHERE document_id = ?');
        $deleteType = $database->prepare('DELETE FROM dbo.document_type_links WHERE document_id = ? AND document_type_id = ?');
        $addType = $database->prepare(
            'IF NOT EXISTS (SELECT 1 FROM dbo.document_type_links WHERE document_id = ? AND document_type_id = ?)
             INSERT INTO dbo.document_type_links (document_id, document_type_id, created_by_user_id) VALUES (?, ?, ?)'
        );
        $syncPrimaryType = $database->prepare(
            'UPDATE dbo.documents
             SET document_type_id = (
                 SELECT TOP (1) tl.document_type_id
                 FROM dbo.document_type_links tl
                 INNER JOIN dbo.document_types dt ON dt.document_type_id = tl.document_type_id
                 WHERE tl.document_id = ?
                 ORDER BY dt.is_active DESC, dt.sort_order, dt.display_name
             )
             WHERE document_id = ?'
        );
        $deleteAllRoles = $database->prepare('DELETE FROM dbo.document_phase_roles WHERE document_id = ?');
        $deleteRole = $database->prepare('DELETE FROM dbo.document_phase_roles WHERE document_id = ? AND phase_id = ? AND role_code = ?');
        $addRole = $database->prepare(
            'IF NOT EXISTS (SELECT 1 FROM dbo.document_phase_roles WHERE document_id = ? AND phase_id = ? AND role_code = ?)
             INSERT INTO dbo.document_phase_roles (document_id, phase_id, role_code, created_by_user_id) VALUES (?, ?, ?, ?)'
        );
        $touch = $database->prepare(
            'UPDATE dbo.documents SET updated_by_user_id = ?, updated_at = SYSUTCDATETIME() WHERE document_id = ?'
        );
        $updateStatus = $database->prepare(
            'UPDATE dbo.documents
             SET status_code = ?,
                 published_at = CASE
                     WHEN ? = \'published\' AND status_code <> \'published\' THEN SYSUTCDATETIME()
                     WHEN ? <> \'published\' THEN NULL
                     ELSE published_at END,
                 updated_by_user_id = ?, updated_at = SYSUTCDATETIME()
             WHERE document_id = ?'
        );
        $validatePublished = $database->prepare(
            'SELECT
                (SELECT COUNT(*) FROM dbo.document_type_links tl
                 INNER JOIN dbo.document_types dt ON dt.document_type_id = tl.document_type_id
                 WHERE tl.document_id = ? AND dt.is_active = 1) AS active_type_count,
                (SELECT COUNT(*) FROM dbo.document_phase_roles pr
                 INNER JOIN dbo.ssdlc_phases p ON p.phase_id = pr.phase_id
                 WHERE pr.document_id = ? AND p.is_active = 1) AS active_role_count'
        );
        foreach ($documents as $document) {
            $documentId = (int) $document['document_id'];
            if ($status === '') {
                $touch->execute([$actorId, $documentId]);
            } else {
                $updateStatus->execute([$status, $status, $status, $actorId, $documentId]);
            }
            if ($typeMode === 'replace') {
                $deleteAllTypes->execute([$documentId]);
            }
            if ($typeMode !== 'unchanged') {
                foreach ($types as $type) {
                    $typeId = (int) $type['document_type_id'];
                    if ($typeMode === 'remove') {
                        $deleteType->execute([$documentId, $typeId]);
                    } else {
                        $addType->execute([$documentId, $typeId, $documentId, $typeId, $actorId]);
                    }
                }
                $syncPrimaryType->execute([$documentId, $documentId]);
            }
            if ($phaseMode === 'replace') {
                $deleteAllRoles->execute([$documentId]);
            }
            if ($phaseMode !== 'unchanged') {
                foreach ($roles as $role) {
                    $phaseId = (int) $role['phase_id'];
                    $roleCode = (string) $role['role_code'];
                    if ($phaseMode === 'remove') {
                        $deleteRole->execute([$documentId, $phaseId, $roleCode]);
                    } else {
                        $addRole->execute([$documentId, $phaseId, $roleCode, $documentId, $phaseId, $roleCode, $actorId]);
                    }
                }
            }
            $targetStatus = $status === '' ? (string) $document['status_code'] : $status;
            if ($targetStatus === 'published') {
                $validatePublished->execute([$documentId, $documentId]);
                $counts = $validatePublished->fetch();
                if ($counts === false || (int) $counts['active_type_count'] === 0 || (int) $counts['active_role_count'] === 0) {
                    throw new RuntimeException('發布前每份文件都必須至少有一個文件種類與一個 SSDLC 階段角色。');
                }
            }
            portal_document_audit(
                $database,
                'document_metadata_bulk_update',
                'accepted',
                $actorId,
                $documentId,
                'status=' . ($status === '' ? 'unchanged' : $status)
                    . ';type_mode=' . $typeMode . ';types=' . count($types)
                    . ';phase_mode=' . $phaseMode . ';phase_roles=' . count($roles)
            );
        }
        $database->commit();
        return count($documents);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function portal_list_recent_admin_documents(PDO $database, int $limit = 10): array
{
    $limit = max(1, min(30, $limit));
    return $database->query(
        'SELECT TOP ' . $limit . '
                CONVERT(varchar(36), d.public_id) AS public_id, d.title, d.file_name, d.extension,
                d.status_code, d.updated_at, COALESCE(u.display_name, u.username, N\'系統\') AS updated_by_name
         FROM dbo.documents d
         LEFT JOIN dbo.auth_users u ON u.user_id = d.updated_by_user_id
         ORDER BY d.updated_at DESC, d.document_id DESC'
    )->fetchAll();
}

function portal_list_admin_users(PDO $database): array
{
    return $database->query(
        'SELECT user_id, username, display_name, role_code, is_active, failed_login_count, locked_until, last_login_at, created_at,
                google_email_normalized, google_subject, google_linked_at, google_last_login_at
         FROM dbo.auth_users ORDER BY is_active DESC, role_code DESC, username'
    )->fetchAll();
}

function portal_create_user(PDO $database, array $actor, array $input): void
{
    $username = portal_normalize_username((string) ($input['username'] ?? ''));
    $displayName = portal_trim_text((string) ($input['display_name'] ?? ''), 100);
    $password = (string) ($input['password'] ?? '');
    $role = (string) ($input['role_code'] ?? 'reader');
    $googleRaw = trim((string) ($input['google_email'] ?? ''));
    $googleEmail = portal_normalize_google_email($googleRaw);
    if ($username === '' || $displayName === '' || strlen($password) < 12 || !in_array($role, ['reader', 'editor', 'admin'], true)
        || ($googleRaw !== '' && $googleEmail === '')) {
        throw new RuntimeException('帳號資料不正確；密碼至少需要 12 個字元。');
    }
    $statement = $database->prepare(
        'INSERT INTO dbo.auth_users (username, username_normalized, display_name, password_hash, role_code, google_email_normalized, google_linked_at)
         VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? IS NULL THEN NULL ELSE SYSUTCDATETIME() END)'
    );
    try {
        $boundEmail = $googleEmail === '' ? null : $googleEmail;
        $statement->execute([$username, $username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role, $boundEmail, $boundEmail]);
    } catch (PDOException $exception) {
        throw new RuntimeException('帳號已存在或無法建立。');
    }
    portal_audit($database, 'admin_user_create', 'accepted', (int) $actor['user_id'], $username);
}

function portal_update_user(PDO $database, array $actor, array $input): void
{
    $targetId = (int) ($input['user_id'] ?? 0);
    $displayName = portal_trim_text((string) ($input['display_name'] ?? ''), 100);
    $role = (string) ($input['role_code'] ?? 'reader');
    $active = isset($input['is_active']) && (string) $input['is_active'] === '1';
    $newPassword = (string) ($input['new_password'] ?? '');
    $googleRaw = trim((string) ($input['google_email'] ?? ''));
    $googleEmail = portal_normalize_google_email($googleRaw);
    if ($targetId <= 0 || $displayName === '' || !in_array($role, ['reader', 'editor', 'admin'], true)
        || ($googleRaw !== '' && $googleEmail === '')) {
        throw new RuntimeException('帳號資料不正確。');
    }
    if ($newPassword !== '' && strlen($newPassword) < 12) {
        throw new RuntimeException('新密碼至少需要 12 個字元。');
    }
    $targetStatement = $database->prepare('SELECT user_id, username, role_code, is_active, google_email_normalized FROM dbo.auth_users WHERE user_id = ?');
    $targetStatement->execute([$targetId]);
    $target = $targetStatement->fetch();
    if ($target === false) {
        throw new RuntimeException('找不到帳號。');
    }
    if ((int) $actor['user_id'] === $targetId && (!$active || $role !== 'admin')) {
        throw new RuntimeException('不可停用自己或移除自己的管理權限。');
    }
    $boundEmail = $googleEmail === '' ? null : $googleEmail;
    $googleChanged = strtolower((string) ($target['google_email_normalized'] ?? '')) !== ($boundEmail ?? '');
    $sql = 'UPDATE dbo.auth_users SET display_name = ?, role_code = ?, is_active = ?,
                google_email_normalized = ?,
                google_subject = CASE WHEN ? = 1 THEN NULL ELSE google_subject END,
                google_linked_at = CASE WHEN ? IS NULL THEN NULL WHEN ? = 1 THEN SYSUTCDATETIME() ELSE google_linked_at END,
                updated_at = SYSUTCDATETIME()';
    $parameters = [$displayName, $role, $active ? 1 : 0, $boundEmail, $googleChanged ? 1 : 0, $boundEmail, $googleChanged ? 1 : 0];
    if ($newPassword !== '') {
        $sql .= ', password_hash = ?, password_changed_at = SYSUTCDATETIME(), failed_login_count = 0, locked_until = NULL';
        $parameters[] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    $sql .= ' WHERE user_id = ?';
    $parameters[] = $targetId;
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    portal_audit($database, 'admin_user_update', 'accepted', (int) $actor['user_id'], (string) $target['username']);
}

function portal_expected_stage_artifacts(string $stageCode): array
{
    $map = [
        '00' => ['inputs' => ['專案背景與治理規範', '共用資安基線', '跨階段決策紀錄'], 'outputs' => ['需求追溯矩陣', '共用規範與基線']],
        '01' => ['inputs' => ['RFP／原始需求', '訪談、會議與決策紀錄'], 'outputs' => ['正式需求文件', '系統需求規格（SRS）', '可執行規格', '需求追溯矩陣']],
        '02' => ['inputs' => ['Phase 01 正式需求與追溯輸出'], 'outputs' => ['資料庫 Schema', 'ER 圖', 'API 規格', 'UI 雛型', 'UML 圖']],
        '03' => ['inputs' => ['Phase 02 設計規格'], 'outputs' => ['實作任務', '程式碼', '單元測試結果']],
        '04' => ['inputs' => ['Phase 03 實作與單元測試輸出'], 'outputs' => ['Bug 追蹤紀錄', 'pytest／Playwright 測試報告']],
        '05' => ['inputs' => ['Phase 04 測試驗證輸出'], 'outputs' => ['建置產物清單', '部署紀錄', 'SHA-256 驗證報告']],
        '06' => ['inputs' => ['Phase 05 部署發布輸出'], 'outputs' => ['故障分析', '修補紀錄', '營運日誌']],
    ];
    return $map[$stageCode] ?? ['inputs' => [], 'outputs' => []];
}

function portal_format_file_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1048576, 1) . ' MB';
}

function portal_format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? portal_trim_text($value, 64) : gmdate('Y-m-d H:i', $timestamp);
}

function portal_render_phase_roles(array $roles, ?string $focusStage = null): string
{
    if ($roles === []) {
        return '<span class="muted">尚未標記 SSDLC 階段</span>';
    }
    $html = '';
    foreach ($roles as $role) {
        if ($focusStage !== null && (string) $role['phase_code'] !== $focusStage) {
            continue;
        }
        $class = (string) $role['role_code'] === 'output' ? 'output' : 'input';
        $html .= '<span class="relation-chip ' . $class . '">' . portal_e((string) $role['phase_code']) . ' ' . portal_e((string) $role['role_code'] === 'output' ? '產出' : '輸入') . '</span>';
    }
    return $html === '' ? '<span class="muted">未標記此階段角色</span>' : $html;
}

function portal_page_body_class(bool $presentationAssets): string
{
    return $presentationAssets ? 'presentation-page' : '';
}

function portal_render_page(string $title, string $content, ?array $user = null, bool $presentationAssets = false, string $bodyClassOverride = '', bool $siteFeedbackAssets = false, bool $htmlStudioAssets = false): void
{
    $stylesheetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.css';
    $stylesheetVersion = is_file($stylesheetPath) ? (string) filemtime($stylesheetPath) : '1';
    $appScriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.js';
    $appScriptVersion = is_file($appScriptPath) ? (string) filemtime($appScriptPath) : '1';
    $imageScriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'image-library.js';
    $imageScriptVersion = is_file($imageScriptPath) ? (string) filemtime($imageScriptPath) : '1';
    $imageGeometryPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'image-annotation-geometry.js';
    $imageGeometryVersion = is_file($imageGeometryPath) ? (string) filemtime($imageGeometryPath) : '1';
    $siteFeedbackStylesheetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'site-feedback.css';
    $siteFeedbackScriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'site-feedback.js';
    $html2canvasPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'html2canvas' . DIRECTORY_SEPARATOR . 'html2canvas.min.js';
    $siteFeedbackStylesheetVersion = is_file($siteFeedbackStylesheetPath) ? (string) filemtime($siteFeedbackStylesheetPath) : '1';
    $siteFeedbackScriptVersion = is_file($siteFeedbackScriptPath) ? (string) filemtime($siteFeedbackScriptPath) : '1';
    $html2canvasVersion = is_file($html2canvasPath) ? (string) filemtime($html2canvasPath) : '1';
    $htmlStudioStylesheetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'html-studio.css';
    $htmlStudioScriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'html-studio.js';
    $htmlStudioStylesheetVersion = is_file($htmlStudioStylesheetPath) ? (string) filemtime($htmlStudioStylesheetPath) : '1';
    $htmlStudioScriptVersion = is_file($htmlStudioScriptPath) ? (string) filemtime($htmlStudioScriptPath) : '1';
    $presentationStylesheetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'presentation.css';
    $presentationScriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'presentation.js';
    $pptxRendererPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'pptx-renderer.js';
    $markdownRendererPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'markdown-renderer.js';
    $jsZipPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'jszip' . DIRECTORY_SEPARATOR . 'jszip.min.js';
    $presentationStylesheetVersion = is_file($presentationStylesheetPath) ? (string) filemtime($presentationStylesheetPath) : '1';
    $presentationScriptVersion = is_file($presentationScriptPath) ? (string) filemtime($presentationScriptPath) : '1';
    $pptxRendererVersion = is_file($pptxRendererPath) ? (string) filemtime($pptxRendererPath) : '1';
    $markdownRendererVersion = is_file($markdownRendererPath) ? (string) filemtime($markdownRendererPath) : '1';
    $jsZipVersion = is_file($jsZipPath) ? (string) filemtime($jsZipPath) : '1';
    $navigation = '';
    $feedbackShortcut = '';
    $userArea = '';
    $fontControl = '<div class="site-font-size-control" data-font-size-control role="group" aria-label="全站文字大小">'
        . '<span>字級</span>'
        . '<button type="button" class="site-font-size-button" data-font-size="small" aria-pressed="false" title="小字型">小</button>'
        . '<button type="button" class="site-font-size-button" data-font-size="medium" aria-pressed="false" title="中字型">中</button>'
        . '<button type="button" class="site-font-size-button" data-font-size="large" aria-pressed="true" title="大字型">大</button></div>';
    if ($user !== null) {
        $navigation = '<nav class="site-nav" aria-label="主要導覽"><a href="' . portal_url('documents') . '">文件庫</a>';
        if (portal_is_admin($user)) {
            $navigation .= '<a href="' . portal_url('visual_assets') . '">AI 圖像／資訊圖表</a>'
                . '<a href="' . portal_url('html_studio') . '">HTML 工作室</a>'
                . '<a href="' . portal_url('admin') . '">管理後台</a>';
            $feedbackShortcut = '<a class="site-feedback-shortcut" href="' . portal_url('feedback') . '" aria-label="畫面修改需求" title="畫面修改需求"><span aria-hidden="true">✎</span></a>';
        }
        $navigation .= '</nav>';
        $userArea = '<form method="post" action="' . portal_url('logout') . '" class="logout-form">'
            . portal_csrf_field()
            . '<span>' . portal_e((string) $user['display_name']) . '（' . portal_e((string) $user['role_code']) . '）</span>'
            . '<button type="submit" class="link-button">登出</button></form>';
    }
    $bodyClass = trim(portal_page_body_class($presentationAssets) . ' ' . preg_replace('/[^a-zA-Z0-9_-]+/', ' ', $bodyClassOverride));
    echo '<!doctype html><html lang="zh-Hant" data-font-size="large"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . portal_e($title) . '｜供水監測文件平台</title>'
        . '<link rel="stylesheet" href="assets/app.css?v=' . portal_e($stylesheetVersion) . '">'
        . ($presentationAssets
            ? '<link rel="stylesheet" href="assets/presentation.css?v=' . portal_e($presentationStylesheetVersion) . '">'
            : '')
        . ($siteFeedbackAssets
            ? '<link rel="stylesheet" href="assets/site-feedback.css?v=' . portal_e($siteFeedbackStylesheetVersion) . '">'
            : '')
        . ($htmlStudioAssets
            ? '<link rel="stylesheet" href="assets/html-studio.css?v=' . portal_e($htmlStudioStylesheetVersion) . '">'
            : '')
        . '</head><body' . ($bodyClass === '' ? '' : ' class="' . portal_e($bodyClass) . '"') . '>'
        . '<header class="site-header"><a class="brand" href="' . ($user === null ? portal_url('login') : portal_url('documents')) . '"><span>供水監測</span><small>文件協作平台</small></a>'
        . $navigation . $feedbackShortcut . $fontControl . $userArea . '</header>'
        . '<main class="page-shell">' . portal_flash_take() . $content . '</main>'
        . '<script src="assets/app.js?v=' . portal_e($appScriptVersion) . '"></script>'
        . '<script src="assets/image-annotation-geometry.js?v=' . portal_e($imageGeometryVersion) . '"></script>'
        . '<script src="assets/image-library.js?v=' . portal_e($imageScriptVersion) . '"></script>'
        . ($presentationAssets
            ? '<script src="assets/vendor/jszip/jszip.min.js?v=' . portal_e($jsZipVersion) . '"></script>'
                . '<script src="assets/pptx-renderer.js?v=' . portal_e($pptxRendererVersion) . '"></script>'
                . '<script src="assets/markdown-renderer.js?v=' . portal_e($markdownRendererVersion) . '"></script>'
                . '<script type="module" src="assets/presentation.js?v=' . portal_e($presentationScriptVersion) . '"></script>'
            : '')
        . ($siteFeedbackAssets
            ? '<script src="assets/vendor/html2canvas/html2canvas.min.js?v=' . portal_e($html2canvasVersion) . '"></script>'
                . '<script src="assets/site-feedback.js?v=' . portal_e($siteFeedbackScriptVersion) . '"></script>'
            : '')
        . ($htmlStudioAssets
            ? '<script src="assets/html-studio.js?v=' . portal_e($htmlStudioScriptVersion) . '"></script>'
            : '')
        . '</body></html>';
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'document_versions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'document_mutations.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'document_version_operations.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'presentation.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'presentation_queue_trigger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'staging.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'image_library.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'site_feedback.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'html_studio.php';
