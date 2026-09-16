<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/document_library_health.php';

function health_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = sys_get_temp_dir() . '/twwater-health-' . bin2hex(random_bytes(6));
$root = $fixture . '/document-library';
health_assert(mkdir($root . '/nested', 0777, true), 'Unable to create health fixture.');
file_put_contents($root . '/report.txt', 'abc');
file_put_contents($root . '/nested/data.pdf', '12345');
health_assert(mkdir($root . '/empty'), 'Unable to create empty fixture directory.');

try {
    $report = portal_document_library_health_report($root, 100, 100);
    health_assert($report['status'] === 'healthy', 'Healthy fixture must report healthy.');
    health_assert($report['file_count'] === 2, 'Health report must count files without reading contents.');
    health_assert($report['directory_count'] === 2, 'Health report must count non-root directories.');
    health_assert($report['empty_directory_count'] === 1, 'Health report must count empty directories.');
    health_assert($report['total_bytes'] === 8, 'Health report must sum file metadata sizes.');
    health_assert($report['extension_counts'] === ['pdf' => 1, 'txt' => 1], 'Health report must return deterministic extension counts.');
    health_assert($report['exception_count'] === 0, 'Healthy fixture must have no exceptions.');

    $missing = portal_document_library_health_report($fixture . '/missing', 100, 100);
    health_assert($missing['status'] === 'unavailable', 'Missing root must fail closed as unavailable.');
    health_assert($missing['file_count'] === 0 && $missing['total_bytes'] === 0, 'Unavailable root must not fabricate statistics.');
} finally {
    @unlink($root . '/report.txt');
    @unlink($root . '/nested/data.pdf');
    @rmdir($root . '/nested');
    @rmdir($root . '/empty');
    @rmdir($root);
    @rmdir($fixture);
}

echo "DOCUMENT_LIBRARY_HEALTH_CONTRACT_OK\n";
