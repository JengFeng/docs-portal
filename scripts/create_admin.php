<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$databasePassword = portal_database_password_from_environment();

$config = [
    'db_host' => trim((string) getenv('PORTAL_DB_HOST')),
    'db_name' => trim((string) getenv('PORTAL_DB_NAME')),
    'db_username' => trim((string) getenv('PORTAL_DB_USERNAME')),
    'db_password' => $databasePassword['value'],
    'db_encrypt' => getenv('PORTAL_DB_ENCRYPT') !== '0',
    'db_trust_server_certificate' => getenv('PORTAL_DB_TRUST_SERVER_CERTIFICATE') === '1',
];

if (
    $config['db_host'] === ''
    || $config['db_name'] === ''
    || $config['db_username'] === ''
    || $config['db_password'] === ''
    || !$databasePassword['valid']
) {
    fwrite(STDERR, "Required environment configuration is incomplete.\n");
    exit(1);
}

$username = portal_normalize_username((string) getenv('PORTAL_BOOTSTRAP_USERNAME'));
$displayName = trim((string) getenv('PORTAL_BOOTSTRAP_DISPLAY_NAME'));
$encodedBootstrapPassword = trim((string) getenv('PORTAL_BOOTSTRAP_PASSWORD_B64'));
if ($encodedBootstrapPassword !== '') {
    $decodedBootstrapPassword = base64_decode($encodedBootstrapPassword, true);
    $password = $decodedBootstrapPassword === false ? '' : $decodedBootstrapPassword;
} else {
    // Backward-compatible fallback for a controlled local console only.
    $password = (string) getenv('PORTAL_BOOTSTRAP_PASSWORD');
}

if ($username === '' || $displayName === '' || strlen($password) < 12) {
    fwrite(STDERR, "Set a valid username, display name, and a 12+ character bootstrap password in a secure local console.\n");
    exit(1);
}

try {
    $database = portal_open_database($config);
    $database->beginTransaction();
    $existingAdminCount = (int) $database->query(
        "SELECT COUNT_BIG(*) FROM dbo.auth_users WITH (UPDLOCK, HOLDLOCK) WHERE role_code = 'admin' AND is_active = 1"
    )->fetchColumn();
    if ($existingAdminCount > 0) {
        $database->rollBack();
        fwrite(STDERR, "An active portal administrator already exists. Use the portal administration page to manage accounts.\n");
        exit(1);
    }
    $statement = $database->prepare(
        'INSERT INTO dbo.auth_users
            (username, username_normalized, display_name, password_hash, role_code)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $username,
        $username,
        $displayName,
        password_hash($password, PASSWORD_DEFAULT),
        'admin',
    ]);
    $database->commit();
    echo "Admin account created.\n";
} catch (Throwable $exception) {
    if (isset($database) && $database instanceof PDO && $database->inTransaction()) {
        $database->rollBack();
    }
    fwrite(STDERR, "Unable to create the initial admin account. Check the local bootstrap values and database configuration.\n");
    exit(1);
}
