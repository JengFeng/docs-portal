<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$sync = (string) file_get_contents($root . '/scripts/sync_documents.php');
$worker = (string) file_get_contents($root . '/scripts/direct_sync_index_worker.ps1');
if (!str_contains($sync, "in_array('--diagnostic', \$argv, true)")) throw new RuntimeException('SYNC_CLI_DIAGNOSTIC_FLAG_MISSING');
if (!str_contains($worker, "[string]\$PhpExecutable='C:\\PHP\\8.5.9\\php.exe'")) throw new RuntimeException('WORKER_MUST_USE_IIS_PHP_CLI_RUNTIME');
echo "SYNC_DIAGNOSTIC_CONTRACT_OK\n";
