<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$config = portal_config();
if (!$config['ready']) {
    fwrite(STDERR, "DIRECT_DB_CONFIG_NOT_READY\n");
    exit(1);
}
$expectedRoot = realpath(dirname(__DIR__) . '/document-library');
$actualRoot = realpath((string) $config['document_root']);
if ($expectedRoot === false || $actualRoot === false || strcasecmp($expectedRoot, $actualRoot) !== 0) {
    fwrite(STDERR, "DIRECT_DB_ROOT_MISMATCH\n");
    exit(1);
}
$manifestPath = 'C:/TWWATER/maintenance-backups/20260830-130354-direct-document-root/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$expected = [];
foreach (($manifest['documents']['files'] ?? []) as $row) {
    $expected[(string) $row['path']] = [(int) $row['bytes'], strtolower((string) $row['sha256'])];
}

try {
    $database = portal_open_database($config);
    $statement = $database->query(
        "SELECT relative_path, file_size_bytes, LOWER(content_hash) AS content_hash, status_code
         FROM dbo.documents
         WHERE status_code <> 'archived'
         ORDER BY relative_path"
    );
    $actual = [];
    $statusCounts = [];
    foreach ($statement as $row) {
        $path = (string) $row['relative_path'];
        $actual[$path] = [(int) $row['file_size_bytes'], strtolower((string) $row['content_hash'])];
        $status = (string) $row['status_code'];
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    }
    ksort($expected, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($actual, SORT_NATURAL | SORT_FLAG_CASE);
    $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
    $extra = array_values(array_diff(array_keys($actual), array_keys($expected)));
    $mismatch = [];
    foreach (array_intersect(array_keys($expected), array_keys($actual)) as $path) {
        if ($expected[$path] !== $actual[$path]) {
            $mismatch[] = $path;
        }
    }
    $result = [
        'document_root' => $actualRoot,
        'expected_count' => count($expected),
        'active_count' => count($actual),
        'status_counts' => $statusCounts,
        'missing_count' => count($missing),
        'extra_count' => count($extra),
        'mismatch_count' => count($mismatch),
        'verified' => $missing === [] && $extra === [] && $mismatch === [],
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($result['verified'] ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "DIRECT_DB_VERIFY_FAILED\n");
    exit(1);
}
