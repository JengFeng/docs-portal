<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = portal_config();
if (!$config['ready']) {
    exit("Required environment configuration is incomplete.\n");
}

try {
    $database = portal_open_database($config);
    $summary = portal_sync_documents($database, $config, null);
    echo 'Discovered: ' . $summary['discovered'] . PHP_EOL;
    echo 'Inserted: ' . $summary['inserted'] . PHP_EOL;
    echo 'Updated: ' . $summary['updated'] . PHP_EOL;
    echo 'Skipped: ' . $summary['skipped'] . PHP_EOL;
    echo 'Version captured: ' . (int) ($summary['version_captured'] ?? 0) . PHP_EOL;
    echo 'Version already ready: ' . (int) ($summary['version_already_ready'] ?? 0) . PHP_EOL;
    echo 'Version failed: ' . (int) ($summary['version_failed'] ?? 0) . PHP_EOL;
    echo 'Version complete: ' . (($summary['version_complete'] ?? false) ? 'yes' : 'no') . PHP_EOL;
    if ((int) ($summary['version_failed'] ?? 0) > 0 || (($summary['version_capture_enabled'] ?? false) === true && ($summary['version_complete'] ?? false) !== true)) {
        echo 'Version errors: ' . json_encode($summary['version_errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Document sync failed. Check the protected environment and server logs.\n");
    exit(1);
}
