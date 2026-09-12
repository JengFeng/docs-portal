<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
function sync_source_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function sync_source_remove(string $path): void { if (!is_dir($path)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($path); }
$root = dirname(__DIR__) . '/.test-direct-sync-' . bin2hex(random_bytes(8));
try {
    sync_source_assert(mkdir($root), 'fixture root creation failed');
    foreach (['網站文件', 'tmp', 'maintenance-backups', '供水監測雛型網站'] as $dir) sync_source_assert(mkdir($root . '/' . $dir), 'fixture directory creation failed: ' . $dir);
    file_put_contents($root . '/網站文件/report.pptx', 'report');
    file_put_contents($root . '/網站文件/tool.exe', 'tool');
    file_put_contents($root . '/網站文件/LICENSE', 'license');
    file_put_contents($root . '/tmp/hidden.pdf', 'hidden');
    file_put_contents($root . '/maintenance-backups/hidden.zip', 'hidden');
    sync_source_assert(portal_direct_sync_root_is_allowed($root), 'real direct sync source root was rejected');
    $files = portal_scan_document_source(['document_root' => $root, 'direct_sync_mode' => true, 'direct_sync_stable_seconds' => 0]);
    $paths = array_column($files, 'relative_path');
    sync_source_assert(in_array('網站文件/report.pptx', $paths, true), 'pptx was not indexed');
    sync_source_assert(in_array('網站文件/tool.exe', $paths, true), 'all-file direct mode rejected exe');
    $extensions = array_column($files, 'extension', 'relative_path');
    sync_source_assert(($extensions['網站文件/LICENSE'] ?? null) === 'none', 'extensionless file was not normalized');
    sync_source_assert(!in_array('tmp/hidden.pdf', $paths, true), 'tmp was indexed');
    sync_source_assert(!in_array('maintenance-backups/hidden.zip', $paths, true), 'backup was indexed');
} finally { sync_source_remove($root); }
echo "[OK] Direct sync source rules passed.\n";
