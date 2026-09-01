<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function read_only_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$directRoot = dirname(__DIR__) . '/document-library';
$versionRoot = 'C:/TWWATER/document-versions';
$environment = [
    'PORTAL_DOCUMENT_ROOT' => $directRoot,
    'PORTAL_DOCUMENT_VERSION_ROOT' => $versionRoot,
    'PORTAL_DB_HOST' => 'test',
    'PORTAL_DB_NAME' => 'test',
    'PORTAL_DB_USERNAME' => 'test',
    'PORTAL_DB_PASSWORD' => 'test',
    'PORTAL_RATE_KEY' => str_repeat('x', 32),
    'PORTAL_SESSION_SAVE_PATH' => 'C:/TWWATER/runtime/sessions',
];
foreach ($environment as $name => $value) { putenv($name . '=' . $value); }
try {
    $config = portal_config(true);
    read_only_assert(($config['document_version_enabled'] ?? false) === true, 'Version history must remain readable.');
    read_only_assert(($config['document_restore_enabled'] ?? true) === false, 'Direct-root Phase 1 enabled restore writes.');
} finally {
    foreach (array_keys($environment) as $name) { putenv($name); }
}

$currentHash = str_repeat('a', 64);
$targetHash = hash('sha256', 'old');
$document = [
    'public_id' => '8D22046C-ECE0-4818-A67E-12799B05AE25',
    'title' => 'fixture',
    'file_name' => 'fixture.md',
    'extension' => 'md',
    'status_code' => 'draft',
    'content_hash' => $currentHash,
    'file_size_bytes' => 10,
    'source_modified_at' => '2026-08-30 00:00:00',
];
$target = [
    'is_current' => false,
    'content_hash' => $targetHash,
    'version_number' => 1,
    'file_size_bytes' => 8,
    'source_modified_at' => '2026-08-29 00:00:00',
    'archived_utc' => '2026-08-29 01:00:00',
];
$comparison = portal_render_document_version_compare($document, $target, ['role_code' => 'admin'], null, false);
read_only_assert(!str_contains($comparison, 'action=document_version_restore'), 'Read-only comparison exposed restore POST.');
read_only_assert(str_contains($comparison, 'Phase 1'), 'Read-only comparison did not explain the Phase-1 gate.');

$fixtureRoot = 'C:/TWWATER/maintenance-backups/20260830-130354-direct-document-root/.test-read-only-' . bin2hex(random_bytes(6));
$publicId = strtolower((string) $document['public_id']);
$archiveDir = $fixtureRoot . '/' . $publicId . '/' . $targetHash;
if (!mkdir($archiveDir, 0700, true) && !is_dir($archiveDir)) {
    throw new RuntimeException('Unable to create read-only version fixture.');
}
file_put_contents($archiveDir . '/source.md', 'old');
file_put_contents($archiveDir . '/manifest.json', json_encode([
    'schema_version' => 1,
    'document_public_id' => $publicId,
    'document_relative_path' => 'fixture.md',
    'original_file_name' => 'fixture.md',
    'extension' => 'md',
    'content_hash' => hash('sha256', 'old'),
    'file_size_bytes' => 3,
    'source_modified_utc' => '2026-08-29T00:00:00Z',
    'archived_utc' => '2026-08-29T01:00:00Z',
    'archived_reason' => 'publish',
    'artifact_file' => 'source.md',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$fixtureDocument = $document;
$fixtureDocument['relative_path'] = 'fixture.md';
$fixtureDocument['content_hash'] = $currentHash;
try {
    $panel = portal_render_document_version_panel([
        'document_version_root' => $fixtureRoot,
        'document_version_enabled' => true,
        'document_restore_enabled' => false,
    ], $fixtureDocument, ['role_code' => 'admin']);
    read_only_assert(str_contains($panel, 'action=document_version_file'), 'Read-only panel removed historical downloads.');
    read_only_assert(!str_contains($panel, 'action=document_version_compare'), 'Read-only panel exposed compare-and-restore action.');
    read_only_assert(str_contains($panel, 'Phase 1'), 'Read-only panel did not explain restore unavailability.');
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($fixtureRoot);
}

$markerRoot = 'C:/TWWATER/maintenance-backups/20260830-130354-direct-document-root/.test-bridge-marker-' . bin2hex(random_bytes(6));
if (!mkdir($markerRoot, 0700, true) && !is_dir($markerRoot)) {
    throw new RuntimeException('Unable to create bridge marker fixture.');
}
$pendingMarker = $markerRoot . '/document-bridge.pending';
$syncingMarker = $markerRoot . '/document-bridge.syncing';
file_put_contents($pendingMarker, '1');
file_put_contents($syncingMarker, '1');
try {
    $markerConfig = [
        'document_root' => $directRoot,
        'bridge_signal_path' => $pendingMarker,
        'bridge_syncing_path' => $syncingMarker,
    ];
    read_only_assert(!portal_bridge_documents_blocked($markerConfig), 'Legacy Bridge marker blocked the authoritative direct root.');
    $markerConfig['document_root'] = 'C:/TWWATER/document-cache-20260823-132335';
    read_only_assert(portal_bridge_documents_blocked($markerConfig), 'Legacy external-cache mode stopped honoring Bridge markers.');
} finally {
    @unlink($pendingMarker);
    @unlink($syncingMarker);
    @rmdir($markerRoot);
}

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$handler = strpos($index, 'function portal_handle_document_version_restore');
$handlerEnd = $handler === false ? false : strpos($index, "\nfunction ", $handler + 10);
$restoreSource = $handler === false ? '' : substr($index, $handler, $handlerEnd === false ? null : $handlerEnd - $handler);
$postGate = strpos($restoreSource, "REQUEST_METHOD'] !== 'POST'");
$csrf = strpos($restoreSource, 'portal_assert_csrf()');
$lookup = strpos($restoreSource, 'portal_get_catalog_document');
$scope = strpos($restoreSource, 'portal_document_version_config_for_document');
$gate = strpos($restoreSource, "document_restore_enabled");
$queue = strpos($restoreSource, 'portal_create_document_version_restore_operation');
read_only_assert($postGate !== false && $csrf !== false && $lookup !== false && $scope !== false && $gate !== false && $queue !== false && $postGate < $csrf && $csrf < $lookup && $lookup < $scope && $scope < $gate && $gate < $queue, 'Restore handler must enforce POST and CSRF, resolve the authorized catalog document, select its scope gate, then queue.');
read_only_assert(str_contains($index, "if (\$action === 'document_version_restore')") && str_contains($index, 'portal_require_admin($database);'), 'Restore dispatcher must require an administrator before the handler.');

echo "[OK] Direct-root Phase-1 version restore read-only gate passed.\n";
