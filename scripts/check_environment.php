<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = portal_config();
$transportKeyEncoded = trim((string) getenv('PORTAL_DIAGNOSTIC_HMAC_KEY_B64'));
$expectedPasswordHmacEncoded = trim((string) getenv('PORTAL_DIAGNOSTIC_PASSWORD_HMAC_B64'));
$passwordTransportValid = $transportKeyEncoded === '' && $expectedPasswordHmacEncoded === '';
if (!$passwordTransportValid) {
    $transportKey = base64_decode($transportKeyEncoded, true);
    $expectedPasswordHmac = base64_decode($expectedPasswordHmacEncoded, true);
    $passwordTransportValid = $transportKey !== false
        && $transportKey !== ''
        && $expectedPasswordHmac !== false
        && hash_equals(
            $expectedPasswordHmac,
            hash_hmac('sha256', (string) $config['db_password'], $transportKey, true)
        );
}
$checks = [
    'PHP version 8+' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO extension' => extension_loaded('pdo'),
    'PDO_SQLSRV extension' => extension_loaded('pdo_sqlsrv'),
    'Password transport integrity' => $passwordTransportValid,
    'Protected environment variables' => $config['ready'],
    'Session directory writable' => $config['ready'] && is_dir($config['session_save_path']) && is_writable($config['session_save_path']),
    'Document root readable' => $config['ready'] && is_dir($config['document_root']) && is_readable($config['document_root']),
];

echo '[INFO] PHP ' . PHP_VERSION
    . ' / PDO_SQLSRV ' . (phpversion('pdo_sqlsrv') ?: 'unknown')
    . ' / SQLSRV ' . (phpversion('sqlsrv') ?: 'not-loaded')
    . PHP_EOL;

$ok = true;
foreach ($checks as $name => $passed) {
    echo ($passed ? '[OK]   ' : '[FAIL] ') . $name . PHP_EOL;
    $ok = $ok && $passed;
}

if ($ok) {
    if (function_exists('sqlsrv_connect')) {
        $sqlsrvConnection = @sqlsrv_connect((string) $config['db_host'], [
            'Database' => (string) $config['db_name'],
            'UID' => (string) $config['db_username'],
            'PWD' => portal_sqlsrv_escape_password((string) $config['db_password']),
            'Encrypt' => (bool) $config['db_encrypt'],
            'TrustServerCertificate' => (bool) $config['db_trust_server_certificate'],
            'CharacterSet' => 'UTF-8',
            'LoginTimeout' => 10,
        ]);
        if ($sqlsrvConnection !== false) {
            echo "[DIAG] SQLSRV procedural connection succeeded\n";
            sqlsrv_close($sqlsrvConnection);
        } else {
            $sqlsrvErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
            $sqlsrvState = is_array($sqlsrvErrors) && isset($sqlsrvErrors[0]['SQLSTATE'])
                ? (string) $sqlsrvErrors[0]['SQLSTATE']
                : '';
            $sqlsrvCode = is_array($sqlsrvErrors) && isset($sqlsrvErrors[0]['code'])
                ? (string) $sqlsrvErrors[0]['code']
                : '';
            $sqlsrvSuffix = $sqlsrvState !== ''
                ? ' [SQLSTATE=' . $sqlsrvState . ($sqlsrvCode !== '' ? ', driver=' . $sqlsrvCode : '') . ']'
                : '';
            echo "[DIAG] SQLSRV procedural connection failed{$sqlsrvSuffix}\n";
        }
    }

    try {
        $database = portal_open_database($config);
        echo "[OK]   SQL Server connection\n";
        $classificationReady = (int) $database->query(
            "SELECT CASE
                WHEN OBJECT_ID(N'dbo.document_type_links', N'U') IS NOT NULL
                 AND HAS_PERMS_BY_NAME(N'dbo.document_type_links', N'OBJECT', N'SELECT') = 1
                 AND HAS_PERMS_BY_NAME(N'dbo.document_type_links', N'OBJECT', N'INSERT') = 1
                 AND HAS_PERMS_BY_NAME(N'dbo.document_type_links', N'OBJECT', N'DELETE') = 1
                THEN 1 ELSE 0 END"
        )->fetchColumn() === 1;
        echo ($classificationReady ? '[OK]   ' : '[FAIL] ')
            . "Document kind multi-select schema and permissions\n";
        $ok = $ok && $classificationReady;
    } catch (Throwable $exception) {
        $sqlState = $exception instanceof PDOException && isset($exception->errorInfo[0])
            ? (string) $exception->errorInfo[0]
            : (string) $exception->getCode();
        $driverCode = $exception instanceof PDOException && isset($exception->errorInfo[1])
            ? (string) $exception->errorInfo[1]
            : '';
        $suffix = $sqlState !== '' ? ' [SQLSTATE=' . $sqlState . ($driverCode !== '' ? ', driver=' . $driverCode : '') . ']' : '';
        echo "[FAIL] SQL Server connection{$suffix}\n";
        $ok = false;
    }
}
exit($ok ? 0 : 1);
