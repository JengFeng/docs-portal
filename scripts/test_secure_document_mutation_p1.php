<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'helper' => (string) file_get_contents($root . '/scripts/atomic-replace-document.ps1'),
    'module' => (string) file_get_contents($root . '/app/document_mutations.php'),
    'migration' => (string) file_get_contents($root . '/database/029_secure_document_mutations.sql'),
    'index' => (string) file_get_contents($root . '/index.php'),
    'dbtest' => (string) file_get_contents($root . '/scripts/test_secure_document_mutation_db.php'),
];
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// P1.1: opened identities stay pinned through replacement and the root itself is handle-derived.
$check(str_contains($files['helper'], 'FileShare]::Delete'), 'P1.1 validated streams must permit delete while remaining open');
$check(strpos($files['helper'], '[IO.File]::Replace') < strpos($files['helper'], '$directoryHandle.Dispose()') && strpos($files['helper'], '[IO.File]::Replace') < strpos($files['helper'], '$rootHandle.Dispose()'), 'P1.1 handle-derived root/directory fences must remain open through File.Replace');
$check(str_contains($files['helper'], '$rootFinal') && str_contains($files['helper'], 'GetFinalPathNameByHandle'), 'P1.1 root identity must come from a handle');
$check(!str_contains($files['helper'], '$targetFinal.EndsWith'), 'P1.1 suffix-only final path checks are forbidden');

// P1.2: durable lease/token fencing plus an exclusive candidate claim prevents stale recovery races.
foreach (['execution_token', 'execution_lease_expires_at', 'portal_claim_document_replacement'] as $needle) {
    $check(str_contains($files['migration'], $needle), 'P1.2 migration missing ' . $needle);
}
$check(str_contains($files['module'], 'portal_claim_document_replacement'), 'P1.2 execution path must claim a durable lease');
$check(str_contains($files['module'], 'portal_claim_replacement_candidate_exclusive'), 'P1.2 recovery must prove exclusive candidate ownership');

// P1.3: mutation and recovery share the existing cross-process sync lock; mark is idempotent.
$check(str_contains($files['module'], 'portal_acquire_document_sync_lock'), 'P1.3 replacement must acquire shared document sync lock');
$check(str_contains($files['module'], 'portal_sync_documents_locked'), 'P1.3 sync must run while the shared lock remains held');
$check(str_contains($files['migration'], "status_code IN(''prepared'',''replaced'')"), 'P1.3 mark must accept already-replaced recovery');

// P1.4: backup identity is durable and cleanup is verified/idempotent after completion.
foreach (['backup_name', 'backup_hash', 'backup_cleaned_at', 'portal_mark_document_replacement_backup_cleaned'] as $needle) {
    $check(str_contains($files['migration'], $needle), 'P1.4 migration missing ' . $needle);
}
$check(str_contains($files['module'], 'portal_cleanup_document_replacement_backup'), 'P1.4 verified backup cleanup helper missing');
$check(!str_contains($files['helper'], 'Remove-Item -LiteralPath $backup'), 'P1.4 helper must not delete operation evidence before immutable completion');

// P1.5: runtime readiness proves exact shape and effective execute/select permissions.
foreach (['COL_LENGTH(N\'dbo.document_mutation_operations\',N\'execution_token\')', 'HAS_PERMS_BY_NAME', 'OBJECT_DEFINITION'] as $needle) {
    $check(str_contains($files['module'], $needle), 'P1.5 runtime readiness missing ' . $needle);
}

// P1.6: fail transition and event use the same nested transaction/savepoint boundary.
$failProc = strstr($files['migration'], "CREATE OR ALTER PROCEDURE dbo.portal_fail_document_replacement");
$check(is_string($failProc) && str_contains(substr($failProc, 0, 1800), 'SAVE TRANSACTION mutation_fail'), 'P1.6 fail proc needs outer transaction savepoint');
$check(is_string($failProc) && str_contains(substr($failProc, 0, 1800), 'BEGIN TRY') && str_contains(substr($failProc, 0, 1800), 'BEGIN CATCH'), 'P1.6 fail update/event must be atomic');

// P1.7: restore binds physical bytes to catalog and immutable current version.
$restoreProc = strstr($files['migration'], "CREATE OR ALTER PROCEDURE dbo.portal_restore_archived_document");
$check(is_string($restoreProc) && str_contains(substr($restoreProc, 0, 700), '@expected_hash CHAR(64)') && str_contains(substr($restoreProc, 0, 700), '@expected_size BIGINT'), 'P1.7 restore proc must require expected hash and size');
$check(str_contains($files['module'], 'portal_open_verified_document_handle'), 'P1.7 restore must verify physical authoritative bytes');
$check(str_contains($files['migration'], 'document_version_contents') && str_contains($files['migration'], 'DATALENGTH(c.content_bytes)'), 'P1.7 restore must verify immutable current binding');

// P1.8: one cached request predicate includes env, exact schema/permissions, and root; no direct renderer getenv.
$check(str_contains($files['module'], 'portal_document_mutations_request_enabled'), 'P1.8 shared request predicate missing');
$check(str_contains($files['module'], 'static $enabled'), 'P1.8 predicate must be request-cached');
$check(str_contains($files['module'], 'portal_assert_document_mutation_root'), 'P1.8 predicate must include root readiness');
$check(!str_contains($files['index'], "getenv('PORTAL_DOCUMENT_MUTATIONS_ENABLED')"), 'P1.8 routes/renderers must not bypass shared predicate');

// The DB fixture must compare distinct size aliases, never a value to itself.
$check(!str_contains($files['dbtest'], '(int)$probe[\'file_size_bytes\']===(int)$probe[\'file_size_bytes\']'), 'size fixture assertion is tautological');
$check(str_contains($files['dbtest'], 'source_file_size_bytes') && str_contains($files['dbtest'], 'content_file_size_bytes'), 'size fixture needs distinct aliases');

if ($failures !== []) {
    fwrite(STDERR, "P1 contract failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "[OK] All eight bounded P1 repair contracts passed.\n";
