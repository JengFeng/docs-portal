<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function direct_manifest_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__) . '/document-library';
$manifestPath = 'C:/TWWATER/maintenance-backups/20260830-130354-direct-document-root/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$expected = [];
foreach (($manifest['documents']['files'] ?? []) as $row) {
    $expected[(string) $row['path']] = [
        'bytes' => (int) $row['bytes'],
        'sha256' => strtolower((string) $row['sha256']),
    ];
}
$actual = [];
foreach (portal_scan_document_source(['document_root' => $root]) as $row) {
    $actual[(string) $row['relative_path']] = [
        'bytes' => (int) $row['file_size_bytes'],
        'sha256' => strtolower((string) $row['content_hash']),
    ];
}
ksort($expected, SORT_NATURAL | SORT_FLAG_CASE);
ksort($actual, SORT_NATURAL | SORT_FLAG_CASE);
direct_manifest_assert(array_keys($actual) === array_keys($expected), 'Portal scan path set differs from frozen manifest.');
foreach ($expected as $path => $identity) {
    direct_manifest_assert($actual[$path] === $identity, 'Portal scan identity mismatch: ' . $path);
}

echo '[OK] Portal direct-root scan matches ' . count($actual) . " frozen manifest files.\n";
