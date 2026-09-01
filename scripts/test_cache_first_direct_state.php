<?php
declare(strict_types=1);

function direct_state_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$module = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'cache_first_direct.php';
if (!is_file($module)) {
    throw new RuntimeException('Direct cache-first state module is missing.');
}
require $module;

$baseHash = hash('sha256', 'drive-source-v1');
$firstWorkingHash = hash('sha256', 'website-working-v2');
$secondWorkingHash = hash('sha256', 'website-working-v3');
$state = [
    'writeback_status' => 'pending',
    'source_content_hash' => $baseHash,
    'content_hash' => $firstWorkingHash,
    'writeback_generation' => '11111111-1111-4111-8111-111111111111',
    'writeback_due_at' => '2026-08-30T02:30:00+08:00',
    'writeback_error_code' => null,
];

$result = portal_cache_first_apply_local_edit(
    $state,
    $firstWorkingHash,
    $secondWorkingHash,
    '22222222-2222-4222-8222-222222222222',
    new DateTimeImmutable('2026-08-30T02:00:00+08:00'),
    60
);

$next = $result['state'];
direct_state_assert($next['writeback_status'] === 'pending', 'A newer local save must remain pending.');
direct_state_assert(hash_equals($baseHash, $next['source_content_hash']), 'A newer local save must preserve the Drive comparison base.');
direct_state_assert(hash_equals($secondWorkingHash, $next['content_hash']), 'A newer local save must advance the website working hash.');
direct_state_assert($next['writeback_generation'] === '22222222-2222-4222-8222-222222222222', 'A newer local save must replace the write-back generation.');
direct_state_assert($next['writeback_due_at'] === '2026-08-30T03:00:00+08:00', 'Idle write-back must restart from the latest save.');
direct_state_assert($next['writeback_error_code'] === null, 'A valid newer save must clear a previous write-back error.');
direct_state_assert($result['effects'] === [
    'write_cache' => true,
    'write_source' => false,
    'protect_from_reconcile' => true,
], 'A local save must update only the protected website cache.');

$syncedState = [
    'writeback_status' => 'synced',
    'source_content_hash' => $baseHash,
    'content_hash' => $baseHash,
    'writeback_generation' => null,
    'writeback_due_at' => null,
    'writeback_error_code' => null,
];
$firstEdit = portal_cache_first_apply_local_edit(
    $syncedState,
    $baseHash,
    $firstWorkingHash,
    '33333333-3333-4333-8333-333333333333',
    new DateTimeImmutable('2026-08-30T04:00:00+08:00'),
    60
);
direct_state_assert($firstEdit['state']['writeback_status'] === 'pending', 'The first website edit must enter pending.');
direct_state_assert(hash_equals($baseHash, $firstEdit['state']['source_content_hash']), 'The first edit must retain the synced source base.');
direct_state_assert(hash_equals($firstWorkingHash, $firstEdit['state']['content_hash']), 'The first edit must expose the new working hash.');
direct_state_assert($firstEdit['state']['writeback_due_at'] === '2026-08-30T05:00:00+08:00', 'The first edit must schedule the configured idle boundary.');
direct_state_assert($firstEdit['effects'] === [
    'write_cache' => true,
    'write_source' => false,
    'protect_from_reconcile' => true,
], 'The first website edit must not write Drive.');

$failedState = $state;
$failedState['writeback_status'] = 'failed';
$failedState['writeback_due_at'] = null;
$failedState['writeback_error_code'] = 'SOURCE_LOCKED';
$editAfterFailure = portal_cache_first_apply_local_edit(
    $failedState,
    $firstWorkingHash,
    $secondWorkingHash,
    '44444444-4444-4444-8444-444444444444',
    new DateTimeImmutable('2026-08-30T06:00:00+08:00'),
    60
);
direct_state_assert($editAfterFailure['state']['writeback_status'] === 'pending', 'A newer edit after write-back failure must return to pending.');
direct_state_assert($editAfterFailure['state']['writeback_error_code'] === null, 'A newer edit must clear the obsolete write-back error.');
direct_state_assert(hash_equals($baseHash, $editAfterFailure['state']['source_content_hash']), 'A newer edit after failure must preserve the Drive base.');
direct_state_assert(hash_equals($secondWorkingHash, $editAfterFailure['state']['content_hash']), 'A newer edit after failure must expose the latest working hash.');
direct_state_assert($editAfterFailure['state']['writeback_due_at'] === '2026-08-30T07:00:00+08:00', 'A newer edit after failure must restart idle timing.');

$observedConflictHash = hash('sha256', 'drive-source-changed-elsewhere');
$conflictState = $state;
$conflictState['writeback_status'] = 'conflict';
$conflictState['writeback_due_at'] = null;
$conflictState['writeback_error_code'] = 'SOURCE_CHANGED';
$conflictState['writeback_observed_source_hash'] = $observedConflictHash;
$editDuringConflict = portal_cache_first_apply_local_edit(
    $conflictState,
    $firstWorkingHash,
    $secondWorkingHash,
    '55555555-5555-4555-8555-555555555555',
    new DateTimeImmutable('2026-08-30T08:00:00+08:00'),
    60
);
direct_state_assert($editDuringConflict['state']['writeback_status'] === 'conflict', 'A local edit must not hide an unresolved Drive conflict.');
direct_state_assert($editDuringConflict['state']['writeback_due_at'] === null, 'A conflicted document must not become eligible for automatic write-back.');
direct_state_assert($editDuringConflict['state']['writeback_error_code'] === 'SOURCE_CHANGED', 'A newer local edit must retain the conflict code.');
direct_state_assert(hash_equals($observedConflictHash, $editDuringConflict['state']['writeback_observed_source_hash']), 'A newer local edit must retain the observed Drive conflict hash.');
direct_state_assert(hash_equals($baseHash, $editDuringConflict['state']['source_content_hash']), 'A newer local edit must retain the original comparison base.');
direct_state_assert(hash_equals($secondWorkingHash, $editDuringConflict['state']['content_hash']), 'A conflicted document must still expose its newest website working version.');
direct_state_assert($editDuringConflict['state']['writeback_generation'] === '55555555-5555-4555-8555-555555555555', 'A conflicted local edit must advance the working generation.');

$marker = portal_cache_first_build_dirty_marker(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    '網站文件/監測說明.txt',
    $result['state'],
    new DateTimeImmutable('2026-08-30T02:00:00+08:00')
);
direct_state_assert($marker === [
    'schema_version' => 1,
    'document_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'relative_path' => '網站文件/監測說明.txt',
    'generation' => '22222222-2222-4222-8222-222222222222',
    'status' => 'pending',
    'base_source_sha256' => $baseHash,
    'working_sha256' => $secondWorkingHash,
    'observed_source_sha256' => null,
    'error_code' => null,
    'not_before_utc' => '2026-08-29T19:00:00Z',
    'updated_utc' => '2026-08-29T18:00:00Z',
], 'Dirty marker must contain only the bounded Bridge contract.');

$syncedWireResult = [
    'schema_version' => 1,
    'document_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'relative_path' => '網站文件/監測說明.txt',
    'generation' => '22222222-2222-4222-8222-222222222222',
    'status' => 'synced',
    'base_source_sha256' => $baseHash,
    'working_sha256' => $secondWorkingHash,
    'observed_source_sha256' => $secondWorkingHash,
    'error_code' => null,
    'not_before_utc' => null,
    'updated_utc' => '2026-08-29T19:01:00Z',
];
$syncedProjection = portal_cache_first_apply_writeback_result(
    $result['state'],
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    '網站文件/監測說明.txt',
    $syncedWireResult
);
direct_state_assert($syncedProjection['applied'] === true, 'Matching write-back result must be applied.');
direct_state_assert($syncedProjection['state']['writeback_status'] === 'synced', 'Successful result must project synced.');
direct_state_assert(hash_equals($secondWorkingHash, $syncedProjection['state']['source_content_hash']), 'Successful result must advance the Drive baseline.');
direct_state_assert($syncedProjection['state']['writeback_generation'] === null, 'Successful result must clear the active generation.');
direct_state_assert($syncedProjection['state']['writeback_due_at'] === null, 'Successful result must clear idle due time.');
direct_state_assert($syncedProjection['state']['writeback_error_code'] === null, 'Successful result must clear error state.');

$conflictWireResult = $syncedWireResult;
$conflictWireResult['status'] = 'conflict';
$conflictWireResult['observed_source_sha256'] = $observedConflictHash;
$conflictWireResult['error_code'] = 'SOURCE_CHANGED';
$conflictProjection = portal_cache_first_apply_writeback_result(
    $result['state'],
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    '網站文件/監測說明.txt',
    $conflictWireResult
);
direct_state_assert($conflictProjection['applied'] === true, 'Matching conflict result must be applied.');
direct_state_assert($conflictProjection['state']['writeback_status'] === 'conflict', 'Conflict result must project conflict.');
direct_state_assert(hash_equals($baseHash, $conflictProjection['state']['source_content_hash']), 'Conflict must preserve the original Drive base.');
direct_state_assert(hash_equals($secondWorkingHash, $conflictProjection['state']['content_hash']), 'Conflict must preserve the website working version.');
direct_state_assert($conflictProjection['state']['writeback_generation'] === '22222222-2222-4222-8222-222222222222', 'Conflict must retain the fenced generation.');
direct_state_assert($conflictProjection['state']['writeback_due_at'] === null, 'Conflict must disable automatic retry.');
direct_state_assert($conflictProjection['state']['writeback_error_code'] === 'SOURCE_CHANGED', 'Conflict must retain the stable error code.');
direct_state_assert(hash_equals($observedConflictHash, $conflictProjection['state']['writeback_observed_source_hash']), 'Conflict must retain the observed Drive hash.');

$failedWireResult = $syncedWireResult;
$failedWireResult['status'] = 'failed';
$failedWireResult['observed_source_sha256'] = null;
$failedWireResult['error_code'] = 'SOURCE_MISSING';
$failedProjection = portal_cache_first_apply_writeback_result(
    $result['state'],
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    '網站文件/監測說明.txt',
    $failedWireResult
);
direct_state_assert($failedProjection['applied'] === true, 'Matching failed result must be applied.');
direct_state_assert($failedProjection['state']['writeback_status'] === 'failed', 'Failed result must project failed.');
direct_state_assert(hash_equals($baseHash, $failedProjection['state']['source_content_hash']), 'Failed result must preserve the Drive base.');
direct_state_assert(hash_equals($secondWorkingHash, $failedProjection['state']['content_hash']), 'Failed result must preserve working content.');
direct_state_assert($failedProjection['state']['writeback_generation'] === '22222222-2222-4222-8222-222222222222', 'Failed result must retain its generation for explicit retry.');
direct_state_assert($failedProjection['state']['writeback_due_at'] === null, 'Failed result must disable automatic retry.');
direct_state_assert($failedProjection['state']['writeback_error_code'] === 'SOURCE_MISSING', 'Failed result must retain its stable error code.');
direct_state_assert($failedProjection['state']['writeback_observed_source_hash'] === null, 'Failed result without a source must not invent an observed hash.');

$manualProjection = portal_cache_first_request_writeback(
    $result['state'],
    '66666666-6666-4666-8666-666666666666',
    '2026-08-30T08:00:00+08:00'
);
direct_state_assert($manualProjection['writeback_status'] === 'pending', 'Immediate write-back must remain pending until Bridge result.');
direct_state_assert(hash_equals($baseHash, $manualProjection['source_content_hash']), 'Immediate write-back must preserve the source base.');
direct_state_assert(hash_equals($secondWorkingHash, $manualProjection['content_hash']), 'Immediate write-back must preserve working bytes.');
direct_state_assert($manualProjection['writeback_generation'] === '66666666-6666-4666-8666-666666666666', 'Immediate write-back must fence older results with a new generation.');
direct_state_assert($manualProjection['writeback_due_at'] === '2026-08-30T08:00:00+08:00', 'Immediate write-back must be due now, not after idle delay.');

$retryProjection = portal_cache_first_request_writeback(
    $failedProjection['state'],
    '77777777-7777-4777-8777-777777777777',
    '2026-08-30T08:05:00+08:00'
);
direct_state_assert($retryProjection['writeback_status'] === 'pending', 'Explicit failed retry must return to pending.');
direct_state_assert($retryProjection['writeback_generation'] === '77777777-7777-4777-8777-777777777777', 'Explicit failed retry must use a new generation.');
direct_state_assert($retryProjection['writeback_due_at'] === '2026-08-30T08:05:00+08:00', 'Explicit failed retry must be due immediately.');
direct_state_assert($retryProjection['writeback_error_code'] === null, 'Explicit failed retry must clear the previous error.');

$idleTooShortRejected = false;
try {
    portal_cache_first_apply_local_edit(
        $syncedState,
        $baseHash,
        hash('sha256', 'invalid idle candidate'),
        '88888888-8888-4888-8888-888888888888',
        new DateTimeImmutable('2026-08-30T08:10:00+08:00'),
        4
    );
} catch (InvalidArgumentException $exception) {
    $idleTooShortRejected = $exception->getMessage() === 'CACHE_FIRST_IDLE_INTERVAL_INVALID';
}
direct_state_assert($idleTooShortRejected, 'Local save must reject idle write-back intervals below the configured 5-minute minimum.');

echo "[OK] Direct cache-first state, manual/retry write-back, and fenced result contracts passed.\n";
