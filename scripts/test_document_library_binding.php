<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function binding_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function binding_remove(string $path): void { if (!is_dir($path)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($path); }
function binding_rejected(string $path): bool { try { portal_document_library_binding_read($path); return false; } catch (RuntimeException) { return true; } }

$root = dirname(__DIR__) . '/.test-document-binding-' . bin2hex(random_bytes(8));
try {
    binding_assert(mkdir($root), 'fixture root creation failed');
    $documents = $root . '/document-library';
    binding_assert(mkdir($documents), 'document root creation failed');
    $settings = $root . '/document-library-binding.json';
    file_put_contents($settings, json_encode(['documentLibraryRoot' => $documents], JSON_UNESCAPED_SLASHES) . "\n");
    $binding = portal_document_library_binding_read($settings);
    binding_assert($binding['document_root'] === realpath($documents), 'binding did not resolve the exact root');

    file_put_contents($settings, json_encode(['documentLibraryRoot' => $documents, 'unexpected' => true], JSON_UNESCAPED_SLASHES) . "\n");
    binding_assert(binding_rejected($settings), 'binding accepted unexpected fields');
    file_put_contents($settings, "{bad-json\n");
    binding_assert(binding_rejected($settings), 'binding accepted malformed JSON');
    file_put_contents($settings, json_encode(['documentLibraryRoot' => $root . '/missing'], JSON_UNESCAPED_SLASHES) . "\n");
    binding_assert(binding_rejected($settings), 'binding accepted a missing root');
} finally { binding_remove($root); }
echo "[OK] document library binding contracts passed.\n";
