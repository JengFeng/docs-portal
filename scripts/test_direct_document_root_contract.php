<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function direct_root_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function direct_root_remove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$fixture = dirname(__DIR__) . '/.test-direct-root-' . bin2hex(random_bytes(8));
$ancestorActual = null;
$ancestorAlias = null;
try {
    direct_root_assert(mkdir($fixture), 'Fixture root creation failed.');
    foreach (['document-library', 'app', 'scripts', 'other'] as $directory) {
        direct_root_assert(mkdir($fixture . '/' . $directory), 'Fixture child creation failed: ' . $directory);
    }

    direct_root_assert(
        portal_document_root_is_allowed($fixture . '/document-library', $fixture),
        'Exact document-library root was rejected.'
    );
    foreach ([$fixture, $fixture . '/app', $fixture . '/scripts', $fixture . '/other'] as $rejected) {
        direct_root_assert(
            !portal_document_root_is_allowed($rejected, $fixture),
            'Unsafe application path was accepted: ' . $rejected
        );
    }
    direct_root_assert(
        !portal_document_root_is_allowed($fixture . '/document-library/../app', $fixture),
        'Traversal alias was accepted.'
    );
    direct_root_assert(
        !portal_document_root_is_allowed($fixture . '/missing', $fixture),
        'Missing direct root was accepted.'
    );

    direct_root_assert(rmdir($fixture . '/document-library'), 'Fixture direct root removal failed.');
    $outside = dirname($fixture) . '/.test-direct-root-outside-' . bin2hex(random_bytes(8));
    direct_root_assert(mkdir($outside), 'Outside fixture creation failed.');
    $junction = $fixture . '/document-library';
    $command = 'cmd.exe /d /c mklink /J "' . str_replace('/', '\\', $junction)
        . '" "' . str_replace('/', '\\', $outside) . '"';
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    direct_root_assert(is_resource($process), 'Junction command failed to start.');
    stream_get_contents($pipes[1]);
    $junctionError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    direct_root_assert(proc_close($process) === 0, 'Junction creation failed: ' . trim((string) $junctionError));
    direct_root_assert(
        !portal_document_root_is_allowed($junction, $fixture),
        'Junction-backed direct root was accepted.'
    );
    direct_root_assert(rmdir($junction), 'Junction cleanup failed.');
    direct_root_assert(rmdir($outside), 'Outside fixture cleanup failed.');

    $ancestorActual = dirname($fixture) . '/.test-direct-root-actual-' . bin2hex(random_bytes(8));
    $ancestorAlias = dirname($fixture) . '/.test-direct-root-alias-' . bin2hex(random_bytes(8));
    direct_root_assert(mkdir($ancestorActual), 'Ancestor actual root creation failed.');
    direct_root_assert(mkdir($ancestorActual . '/document-library'), 'Ancestor direct root creation failed.');
    $ancestorCommand = 'cmd.exe /d /c mklink /J "' . str_replace('/', '\\', $ancestorAlias)
        . '" "' . str_replace('/', '\\', $ancestorActual) . '"';
    $ancestorProcess = proc_open($ancestorCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ancestorPipes);
    direct_root_assert(is_resource($ancestorProcess), 'Ancestor junction command failed to start.');
    stream_get_contents($ancestorPipes[1]);
    $ancestorError = stream_get_contents($ancestorPipes[2]);
    fclose($ancestorPipes[1]);
    fclose($ancestorPipes[2]);
    direct_root_assert(proc_close($ancestorProcess) === 0, 'Ancestor junction creation failed: ' . trim((string) $ancestorError));
    direct_root_assert(
        !portal_document_root_is_allowed($ancestorAlias . '/document-library', $ancestorAlias),
        'Junction-backed application ancestor was accepted.'
    );
    direct_root_assert(rmdir($ancestorAlias), 'Ancestor junction cleanup failed.');
    $ancestorAlias = null;
    direct_root_remove($ancestorActual);
    $ancestorActual = null;
} finally {
    if (is_string($ancestorAlias) && is_dir($ancestorAlias)) {
        rmdir($ancestorAlias);
    }
    if (is_string($ancestorActual) && is_dir($ancestorActual)) {
        direct_root_remove($ancestorActual);
    }
    direct_root_remove($fixture);
}

echo "[OK] Exact in-tree document root allowlist passed.\n";
