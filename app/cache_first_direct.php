<?php
declare(strict_types=1);

function portal_cache_first_direct_hash(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('CACHE_FIRST_HASH_INVALID');
    }
    $hash = strtolower($value);
    if (preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) {
        throw new InvalidArgumentException('CACHE_FIRST_HASH_INVALID');
    }
    return $hash;
}

function portal_cache_first_direct_generation(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('CACHE_FIRST_GENERATION_INVALID');
    }
    $generation = strtolower($value);
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $generation) !== 1) {
        throw new InvalidArgumentException('CACHE_FIRST_GENERATION_INVALID');
    }
    return $generation;
}

/**
 * Pure transition for a completed, verified cache artifact.
 * Filesystem and SQL adapters apply the returned intent separately.
 *
 * @param array<string,mixed> $state
 * @return array{state:array<string,mixed>,effects:array{write_cache:bool,write_source:bool,protect_from_reconcile:bool}}
 */
function portal_cache_first_apply_local_edit(
    array $state,
    mixed $expectedWorkingHash,
    mixed $candidateHash,
    mixed $generation,
    DateTimeImmutable $now,
    int $idleMinutes
): array {
    $status = $state['writeback_status'] ?? null;
    if (!is_string($status) || !in_array($status, ['synced', 'pending', 'failed', 'conflict'], true)) {
        throw new RuntimeException('CACHE_FIRST_STATE_INVALID');
    }
    if ($idleMinutes < 5 || $idleMinutes > 1440) {
        throw new InvalidArgumentException('CACHE_FIRST_IDLE_INTERVAL_INVALID');
    }

    $sourceHash = portal_cache_first_direct_hash($state['source_content_hash'] ?? null);
    $currentHash = portal_cache_first_direct_hash($state['content_hash'] ?? null);
    $expectedHash = portal_cache_first_direct_hash($expectedWorkingHash);
    $nextHash = portal_cache_first_direct_hash($candidateHash);
    $nextGeneration = portal_cache_first_direct_generation($generation);
    if (!hash_equals($currentHash, $expectedHash)) {
        throw new RuntimeException('CACHE_FIRST_WORKING_CONFLICT');
    }
    if ($status === 'synced' && !hash_equals($sourceHash, $currentHash)) {
        throw new RuntimeException('CACHE_FIRST_SYNCED_INVARIANT_INVALID');
    }
    if ($status === 'failed') {
        $errorCode = $state['writeback_error_code'] ?? null;
        if (!is_string($errorCode) || preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $errorCode) !== 1) {
            throw new RuntimeException('CACHE_FIRST_FAILED_INVARIANT_INVALID');
        }
    }
    if ($status === 'conflict') {
        $errorCode = $state['writeback_error_code'] ?? null;
        if ($errorCode !== 'SOURCE_CHANGED') {
            throw new RuntimeException('CACHE_FIRST_CONFLICT_INVARIANT_INVALID');
        }
        portal_cache_first_direct_hash($state['writeback_observed_source_hash'] ?? null);
    }

    $next = $state;
    $next['source_content_hash'] = $sourceHash;
    $next['content_hash'] = $nextHash;
    $next['writeback_generation'] = $nextGeneration;
    if ($status === 'conflict') {
        $next['writeback_status'] = 'conflict';
        $next['writeback_due_at'] = null;
    } else {
        $next['writeback_status'] = 'pending';
        $next['writeback_due_at'] = $now->modify('+' . $idleMinutes . ' minutes')->format(DATE_ATOM);
        $next['writeback_error_code'] = null;
        unset($next['writeback_observed_source_hash']);
    }

    return [
        'state' => $next,
        'effects' => [
            'write_cache' => true,
            'write_source' => false,
            'protect_from_reconcile' => true,
        ],
    ];
}

function portal_cache_first_direct_absolute_time(mixed $value): DateTimeImmutable
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('CACHE_FIRST_TIME_INVALID');
    }
    $time = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($time === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        || $time->format(DATE_ATOM) !== $value) {
        throw new InvalidArgumentException('CACHE_FIRST_TIME_INVALID');
    }
    return $time;
}

function portal_cache_first_direct_utc(DateTimeImmutable $time): string
{
    return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
}

/**
 * @param array<string,mixed> $state
 * @return array<string,int|string|null>
 */
function portal_cache_first_build_dirty_marker(
    mixed $documentId,
    mixed $relativePath,
    array $state,
    DateTimeImmutable $updatedAt
): array {
    $documentId = portal_cache_first_direct_generation($documentId);
    if (!is_string($relativePath) || strlen($relativePath) < 4 || strlen($relativePath) > 512
        || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')
        || !str_starts_with($relativePath, '網站文件/')) {
        throw new InvalidArgumentException('CACHE_FIRST_PATH_INVALID');
    }
    $segments = explode('/', $relativePath);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
            throw new InvalidArgumentException('CACHE_FIRST_PATH_INVALID');
        }
    }
    $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['md','txt','pdf','docx','xlsx','pptx','png','jpg','jpeg','webp','gif'], true)) {
        throw new InvalidArgumentException('CACHE_FIRST_PATH_INVALID');
    }

    $status = $state['writeback_status'] ?? null;
    if (!is_string($status) || !in_array($status, ['pending', 'conflict', 'failed'], true)) {
        throw new RuntimeException('CACHE_FIRST_MARKER_STATE_INVALID');
    }
    $generation = portal_cache_first_direct_generation($state['writeback_generation'] ?? null);
    $baseHash = portal_cache_first_direct_hash($state['source_content_hash'] ?? null);
    $workingHash = portal_cache_first_direct_hash($state['content_hash'] ?? null);
    $notBefore = null;
    $observedHash = null;
    $errorCode = null;
    if ($status === 'pending') {
        if (($state['writeback_observed_source_hash'] ?? null) !== null || ($state['writeback_error_code'] ?? null) !== null) {
            throw new RuntimeException('CACHE_FIRST_PENDING_MARKER_INVALID');
        }
        $notBefore = portal_cache_first_direct_utc(
            portal_cache_first_direct_absolute_time($state['writeback_due_at'] ?? null)
        );
    } elseif ($status === 'failed') {
        $errorCode = $state['writeback_error_code'] ?? null;
        if (!is_string($errorCode) || preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $errorCode) !== 1
            || ($state['writeback_observed_source_hash'] ?? null) !== null) {
            throw new RuntimeException('CACHE_FIRST_FAILED_MARKER_INVALID');
        }
    } else {
        $errorCode = $state['writeback_error_code'] ?? null;
        if ($errorCode !== 'SOURCE_CHANGED') {
            throw new RuntimeException('CACHE_FIRST_CONFLICT_MARKER_INVALID');
        }
        $observedHash = portal_cache_first_direct_hash($state['writeback_observed_source_hash'] ?? null);
    }

    return [
        'schema_version' => 1,
        'document_id' => $documentId,
        'relative_path' => $relativePath,
        'generation' => $generation,
        'status' => $status,
        'base_source_sha256' => $baseHash,
        'working_sha256' => $workingHash,
        'observed_source_sha256' => $observedHash,
        'error_code' => $errorCode,
        'not_before_utc' => $notBefore,
        'updated_utc' => portal_cache_first_direct_utc($updatedAt),
    ];
}

function portal_cache_first_request_writeback(
    array $state,
    mixed $newGeneration,
    mixed $requestedAt
): array {
    $status = $state['writeback_status'] ?? null;
    if (!is_string($status) || !in_array($status, ['pending', 'failed'], true)) {
        throw new RuntimeException('CACHE_FIRST_WRITEBACK_REQUEST_STATE_INVALID');
    }
    if ($status === 'failed') {
        $errorCode = $state['writeback_error_code'] ?? null;
        if (!is_string($errorCode) || preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $errorCode) !== 1) {
            throw new RuntimeException('CACHE_FIRST_FAILED_STATE_INVALID');
        }
    }
    portal_cache_first_direct_hash($state['source_content_hash'] ?? null);
    portal_cache_first_direct_hash($state['content_hash'] ?? null);
    $currentGeneration = portal_cache_first_direct_generation($state['writeback_generation'] ?? null);
    $newGeneration = portal_cache_first_direct_generation($newGeneration);
    if (hash_equals($currentGeneration, $newGeneration)) {
        throw new InvalidArgumentException('CACHE_FIRST_GENERATION_REUSE');
    }
    $requestedAt = portal_cache_first_direct_absolute_time($requestedAt);
    $next = $state;
    $next['writeback_status'] = 'pending';
    $next['writeback_generation'] = $newGeneration;
    $next['writeback_due_at'] = $requestedAt->format(DATE_ATOM);
    $next['writeback_error_code'] = null;
    $next['writeback_observed_source_hash'] = null;
    $next['writeback_updated_at'] = $requestedAt->format(DATE_ATOM);
    return $next;
}

/**
 * @param array<string,mixed> $state
 * @param array<string,mixed> $wireResult
 * @return array{state:array<string,mixed>,applied:bool}
 */
function portal_cache_first_apply_writeback_result(
    array $state,
    mixed $documentId,
    mixed $relativePath,
    array $wireResult
): array {
    $documentId = portal_cache_first_direct_generation($documentId);
    $currentGeneration = portal_cache_first_direct_generation($state['writeback_generation'] ?? null);
    $wireGeneration = portal_cache_first_direct_generation($wireResult['generation'] ?? null);
    if (!hash_equals($currentGeneration, $wireGeneration)) {
        return ['state' => $state, 'applied' => false];
    }
    if (($state['writeback_status'] ?? null) !== 'pending') {
        throw new RuntimeException('CACHE_FIRST_RESULT_STATE_INVALID');
    }
    $expectedKeys = [
        'schema_version','document_id','relative_path','generation','status',
        'base_source_sha256','working_sha256','observed_source_sha256','error_code',
        'not_before_utc','updated_utc',
    ];
    $actualKeys = array_keys($wireResult);
    sort($expectedKeys);
    sort($actualKeys);
    if ($actualKeys !== $expectedKeys || ($wireResult['schema_version'] ?? null) !== 1) {
        throw new RuntimeException('CACHE_FIRST_RESULT_SHAPE_INVALID');
    }
    if (!is_string($wireResult['document_id'])
        || !hash_equals($documentId, portal_cache_first_direct_generation($wireResult['document_id']))) {
        throw new RuntimeException('CACHE_FIRST_RESULT_DOCUMENT_INVALID');
    }
    if (!is_string($relativePath) || !is_string($wireResult['relative_path'])
        || !hash_equals($relativePath, $wireResult['relative_path'])) {
        throw new RuntimeException('CACHE_FIRST_RESULT_PATH_INVALID');
    }
    $baseHash = portal_cache_first_direct_hash($state['source_content_hash'] ?? null);
    $workingHash = portal_cache_first_direct_hash($state['content_hash'] ?? null);
    if (!hash_equals($baseHash, portal_cache_first_direct_hash($wireResult['base_source_sha256'] ?? null))
        || !hash_equals($workingHash, portal_cache_first_direct_hash($wireResult['working_sha256'] ?? null))) {
        throw new RuntimeException('CACHE_FIRST_RESULT_HASH_BINDING_INVALID');
    }
    if (($wireResult['not_before_utc'] ?? null) !== null || !is_string($wireResult['updated_utc'] ?? null)) {
        throw new RuntimeException('CACHE_FIRST_RESULT_TIME_INVALID');
    }
    $updated = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $wireResult['updated_utc'], new DateTimeZone('UTC'));
    $timeErrors = DateTimeImmutable::getLastErrors();
    if ($updated === false || ($timeErrors !== false && ($timeErrors['warning_count'] !== 0 || $timeErrors['error_count'] !== 0))
        || $updated->format('Y-m-d\\TH:i:s\\Z') !== $wireResult['updated_utc']) {
        throw new RuntimeException('CACHE_FIRST_RESULT_TIME_INVALID');
    }
    $status = $wireResult['status'] ?? null;
    $next = $state;
    $next['writeback_due_at'] = null;
    $next['writeback_updated_at'] = $wireResult['updated_utc'];
    if ($status === 'synced') {
        if (($wireResult['error_code'] ?? null) !== null
            || !hash_equals($workingHash, portal_cache_first_direct_hash($wireResult['observed_source_sha256'] ?? null))) {
            throw new RuntimeException('CACHE_FIRST_SYNCED_RESULT_INVALID');
        }
        $next['writeback_status'] = 'synced';
        $next['source_content_hash'] = $workingHash;
        $next['writeback_generation'] = null;
        $next['writeback_error_code'] = null;
        $next['writeback_observed_source_hash'] = null;
    } elseif ($status === 'conflict') {
        $observedHash = portal_cache_first_direct_hash($wireResult['observed_source_sha256'] ?? null);
        if (($wireResult['error_code'] ?? null) !== 'SOURCE_CHANGED' || hash_equals($observedHash, $baseHash)) {
            throw new RuntimeException('CACHE_FIRST_CONFLICT_RESULT_INVALID');
        }
        $next['writeback_status'] = 'conflict';
        $next['writeback_error_code'] = 'SOURCE_CHANGED';
        $next['writeback_observed_source_hash'] = $observedHash;
    } elseif ($status === 'failed') {
        $errorCode = $wireResult['error_code'] ?? null;
        if (($wireResult['observed_source_sha256'] ?? null) !== null
            || !is_string($errorCode) || preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $errorCode) !== 1) {
            throw new RuntimeException('CACHE_FIRST_FAILED_RESULT_INVALID');
        }
        $next['writeback_status'] = 'failed';
        $next['writeback_error_code'] = $errorCode;
        $next['writeback_observed_source_hash'] = null;
    } else {
        throw new RuntimeException('CACHE_FIRST_RESULT_STATUS_INVALID');
    }
    return ['state' => $next, 'applied' => true];
}
