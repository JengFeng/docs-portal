<?php
declare(strict_types=1);

function switch_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function position_or_fail(string $source, string $needle, string $message): int
{
    $position = strpos($source, $needle);
    switch_contract_assert($position !== false, $message . ': ' . $needle);
    return $position;
}

$path = __DIR__ . '/switch_direct_document_root.ps1';
switch_contract_assert(is_file($path), 'Direct-root switch script is missing.');
$source = file_get_contents($path);
switch_contract_assert(is_string($source), 'Switch script is unreadable.');

foreach ([
    '[switch] $Apply',
    '[switch] $Rollback',
    '[switch] $MigrateEvidenceOnly',
    'TWWATER_PortalPool',
    'PORTAL_DOCUMENT_ROOT',
    'C:\\web\\gary\\TWWATER\\document-library',
    'Invoke-DirectRootSwitchTransaction',
    'Resolve-DirectRootEvidenceSet',
    'Write-DirectRootEvidenceDurable',
    'ConvertFrom-DirectRootLegacyEvidence',
    'primary_valid',
    'cleanup_pending',
    'Wait-AppPoolReadyAndRoot',
    'evidence_migrated_utc',
] as $token) {
    switch_contract_assert(str_contains($source, $token), 'Switch script integration missing: ' . $token);
}

switch_contract_assert(str_contains($source, "DIRECT_ROOT_APP_POOL_IDENTITY_MISMATCH"), 'Exact AppPool identity gate is missing.');
switch_contract_assert(str_contains($source, "DIRECT_ROOT_BACKUP_ROOT_IDENTITY_MISMATCH"), 'Exact canonical BackupRoot identity gate is missing.');
switch_contract_assert(str_contains($source, 'DIRECT_ROOT_MODE_CONFLICT'), 'Mutually exclusive mode gate is missing.');
switch_contract_assert(preg_match('/\$modeCount\s*=.*\$Apply.*\$Rollback.*\$MigrateEvidenceOnly/s', $source) === 1, 'All modes must be counted and rejected when combined.');

switch_contract_assert(
    preg_match('/\.\s*\(Join-Path\s+\$PSScriptRoot\s+[\'\"]direct_root_evidence\.ps1[\'\"]\)/i', $source) === 1,
    'Switch script must source the production evidence module.'
);
switch_contract_assert(
    preg_match('/if\s*\(\s*-not\s+\$Apply\s+-and\s+-not\s+\$MigrateEvidenceOnly\s*\)/i', $source) === 1,
    'Default execution must remain a non-mutating plan.'
);
switch_contract_assert(
    !str_contains($source, 'Write-VerifiedJson') &&
    !preg_match('/\[IO\.File\]::Delete\(\$successStatePath\).*\[IO\.File\]::Move/s', $source),
    'Accepted evidence must not use delete-before-move publication.'
);
switch_contract_assert(
    !str_contains($source, 'ConvertTo-DirectRootSchema2Evidence'),
    'Legacy schema-2 upgrade path must be removed from the switch integration.'
);

// Ordering: strict reconciliation must precede every Apply/rollback mutation transaction.
$resolvePos = position_or_fail($source, 'Resolve-DirectRootEvidenceSet', 'Strict evidence reconciliation is absent');
$transactionPos = position_or_fail($source, 'Invoke-DirectRootSwitchTransaction', 'Outer transaction is absent');
switch_contract_assert($resolvePos < $transactionPos, 'Evidence reconciliation must occur before the mutation transaction.');

// Evidence-only migration must exit before admin/IIS mutation setup, CommitChanges, or recycle can be reached.
$migrationPos = position_or_fail($source, 'if ($MigrateEvidenceOnly)', 'Evidence-only branch is absent');
$adminPos = position_or_fail($source, 'if (-not (Test-IsAdministrator))', 'Administrator mutation gate is absent');
switch_contract_assert($migrationPos < $adminPos, 'Evidence-only migration branch must precede the IIS mutation gate.');
$migrationExitPos = strpos($source, 'exit 0', $migrationPos);
switch_contract_assert($migrationExitPos !== false && $migrationExitPos < $adminPos, 'Evidence-only migration must exit before IIS mutation setup.');
$migrationBlock = substr($source, $migrationPos, $adminPos - $migrationPos);
switch_contract_assert(
    str_contains($migrationBlock, 'source_evidence_sha256') && str_contains($migrationBlock, 'DIRECT_ROOT_MIGRATION_EXISTING_EVIDENCE_CONFLICT'),
    'Repeated migration must verify immutable source provenance before returning without a rewrite.'
);
switch_contract_assert(
    !str_contains($migrationBlock, 'Commit-AppPoolRootState') &&
    !str_contains($migrationBlock, 'Recycle-AppPool') &&
    !str_contains($migrationBlock, 'CommitChanges()') &&
    !str_contains($migrationBlock, 'Recycle()'),
    'Evidence-only migration branch contains an IIS mutation operation.'
);

// Already-applied replay accepts only strict schema-3 primary evidence and exits without publication or mutation.
$alreadyPos = position_or_fail($source, "mode = 'already-applied'", 'Already-applied branch is absent');
$alreadyBranchStart = strrpos(substr($source, 0, $alreadyPos), 'if (');
$alreadyExitPos = strpos($source, 'exit 0', $alreadyPos);
switch_contract_assert($alreadyBranchStart !== false && $alreadyExitPos !== false, 'Already-applied branch boundaries are not identifiable.');
$alreadyBlock = substr($source, $alreadyBranchStart, $alreadyExitPos - $alreadyBranchStart);
switch_contract_assert(str_contains($alreadyBlock, 'primary_valid'), 'Already-applied replay must require primary_valid evidence.');
switch_contract_assert(
    !str_contains($alreadyBlock, 'Write-DirectRootEvidenceDurable') &&
    !str_contains($alreadyBlock, 'Commit-AppPoolRootState') &&
    !str_contains($alreadyBlock, 'Recycle-AppPool'),
    'Already-applied replay must not rewrite evidence, commit, or recycle.'
);

switch_contract_assert(
    !str_contains($source, 'PORTAL_DB_PASSWORD') && !str_contains($source, 'PORTAL_RATE_KEY'),
    'Switch script must not read or emit unrelated secrets.'
);

$transactionPath = __DIR__ . '/direct_root_switch_transaction.ps1';
$transactionSource = is_file($transactionPath) ? file_get_contents($transactionPath) : false;
switch_contract_assert(is_string($transactionSource), 'Switch transaction helper is missing or unreadable.');
foreach (['DIRECT_ROOT_POST_COMMIT_FAILED_ROLLED_BACK', 'DIRECT_ROOT_ROLLBACK_FAILED', 'VerifyRestore', 'RemovePreparedEvidence', 'cleanup_pending'] as $token) {
    switch_contract_assert(str_contains($transactionSource, $token), 'Switch transaction contract missing: ' . $token);
}
$invokeStart = position_or_fail($transactionSource, 'function Invoke-DirectRootSwitchTransaction', 'Outer transaction function missing');
$invokeSource = substr($transactionSource, $invokeStart);
$writePos = position_or_fail($invokeSource, '& $WriteEvidence', 'Evidence publication call missing');
$cleanupPos = position_or_fail($invokeSource, '& $RemovePreparedEvidence', 'Prepared cleanup call missing');
switch_contract_assert($writePos < $cleanupPos, 'Prepared cleanup must run only after success evidence publication.');

// Execute the real default mode only; it is contractually non-mutating.
$command = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($path) . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
switch_contract_assert($exitCode === 0, 'Default plan execution failed: ' . implode("\n", $output));
$plan = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
switch_contract_assert(($plan['mode'] ?? null) === 'plan', 'Default execution did not return plan mode.');
switch_contract_assert(($plan['apply'] ?? null) === false, 'Default execution was not non-mutating.');

foreach ([
    ['-Apply -Rollback', 'DIRECT_ROOT_MODE_CONFLICT'],
    ["-AppPoolName OtherPool", 'DIRECT_ROOT_APP_POOL_IDENTITY_MISMATCH'],
    ["-BackupRoot C:\\TWWATER\\maintenance-backups\\wrong", 'DIRECT_ROOT_BACKUP_ROOT_IDENTITY_MISMATCH'],
    ["-DocumentRoot C:\\definitely-not-approved-direct-root", 'DIRECT_ROOT_PATH_NOT_ALLOWLISTED'],
] as [$arguments, $expectedFailure]) {
    $negativeOutput = [];
    $negativeExit = 0;
    exec($command . ' ' . $arguments, $negativeOutput, $negativeExit);
    switch_contract_assert($negativeExit !== 0, "Unsafe arguments unexpectedly succeeded: {$arguments}");
    switch_contract_assert(str_contains(implode("\n", $negativeOutput), $expectedFailure), "Unsafe arguments did not fail with {$expectedFailure}: {$arguments}");
}

switch_contract_assert(str_contains($source, 'Invoke-DirectRootEvidenceOnlyOperation'), 'Evidence-only mode is not wired through the behaviorally tested no-IIS-mutation helper.');

echo "[OK] Direct document root strict evidence integration and non-mutating plan contract passed.\n";
