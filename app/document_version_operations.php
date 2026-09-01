<?php
declare(strict_types=1);

function portal_document_version_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function portal_document_version_operation_schema_available(PDO $database): bool
{
    $statement = $database->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.document_version_operations', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.document_version_operation_events', N'U') IS NOT NULL
                     THEN 1 ELSE 0 END"
    );
    return (int) $statement->fetchColumn() === 1;
}

function portal_get_active_document_version_operation(PDO $database, int $documentId): ?array
{
    if ($documentId <= 0 || !portal_document_version_operation_schema_available($database)) {
        return null;
    }
    $statement = $database->prepare(
        "SELECT TOP (1) CONVERT(varchar(36), operation_id) AS operation_id, status_code,
                expected_current_hash, target_content_hash, drive_sync_status, requested_at, updated_at
         FROM dbo.document_version_operations
         WHERE document_id = ? AND status_code IN ('queued','running','local-restored','indexed')
         ORDER BY requested_at DESC"
    );
    $statement->execute([$documentId]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function portal_insert_document_version_operation_event(
    PDO $database,
    string $operationId,
    string $status,
    string $eventCode,
    string $source,
    ?string $detail = null,
    ?int $actorUserId = null
): void {
    $statement = $database->prepare(
        'INSERT INTO dbo.document_version_operation_events
            (operation_id, status_code, event_code, event_source, detail_message, actor_user_id)
         SELECT ?, ?, ?, ?, ?, ?
         WHERE NOT EXISTS
         (
             SELECT 1 FROM dbo.document_version_operation_events
             WHERE operation_id = ? AND event_code = ?
         )'
    );
    $statement->execute([
        $operationId,
        $status,
        portal_trim_text($eventCode, 64),
        $source,
        $detail === null ? null : portal_trim_text($detail, 1000),
        $actorUserId,
        $operationId,
        portal_trim_text($eventCode, 64),
    ]);
}

function portal_pending_document_version_request_path(array $config, string $operationId): string
{
    $operationId = portal_valid_public_id($operationId);
    if ($operationId === '') {
        return '';
    }
    try {
        return portal_document_version_request_directory($config) . DIRECTORY_SEPARATOR . $operationId . '.json';
    } catch (Throwable) {
        return '';
    }
}

function portal_create_document_version_restore_operation(
    PDO $database,
    array $config,
    array $document,
    string $targetHash,
    array $actor
): array {
    if (!portal_document_version_operation_schema_available($database)) {
        throw new RuntimeException('版本操作資料庫尚未安裝。');
    }
    $targetHash = portal_document_version_hash($targetHash);
    $currentHash = portal_document_version_hash((string) ($document['content_hash'] ?? ''));
    $documentId = (int) ($document['document_id'] ?? 0);
    $actorId = (int) ($actor['user_id'] ?? 0);
    if ($targetHash === '' || $currentHash === '' || $targetHash === $currentHash || $documentId <= 0 || $actorId <= 0) {
        throw new RuntimeException('版本還原操作資料無效。');
    }
    if (portal_read_document_version_manifest($config, $document, $targetHash) === null) {
        throw new RuntimeException('目標封存版本不存在或完整性驗證失敗。');
    }

    $operationId = portal_document_version_uuid();
    try {
        $database->beginTransaction();
        $lock = $database->prepare(
            "SELECT TOP (1) CONVERT(varchar(36), operation_id) AS operation_id, status_code
             FROM dbo.document_version_operations WITH (UPDLOCK, HOLDLOCK)
             WHERE document_id = ? AND status_code IN ('queued','running','local-restored','indexed')
             ORDER BY requested_at DESC"
        );
        $lock->execute([$documentId]);
        $active = $lock->fetch();
        if ($active !== false) {
            $database->commit();
            return ['operation_id' => (string) $active['operation_id'], 'status_code' => (string) $active['status_code'], 'duplicate' => true];
        }
        $insert = $database->prepare(
            'INSERT INTO dbo.document_version_operations
                (operation_id, document_id, operation_type, status_code, source_status_code,
                 expected_current_hash, target_content_hash, drive_sync_status, requested_by_user_id,
                 request_client_ip, request_user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $operationId,
            $documentId,
            'restore',
            'queued',
            (string) ($document['status_code'] ?? 'draft'),
            $currentHash,
            $targetHash,
            'pending-confirmation',
            $actorId,
            portal_client_ip() ?: null,
            portal_user_agent() ?: null,
        ]);
        portal_insert_document_version_operation_event(
            $database,
            $operationId,
            'queued',
            'REQUEST_RESERVED',
            'portal',
            'Version restore request durably reserved.',
            $actorId
        );
        $database->commit();
        portal_queue_document_version_restore($config, $document, $targetHash, $actor, $operationId);
        portal_insert_document_version_operation_event(
            $database,
            $operationId,
            'queued',
            'REQUEST_QUEUED',
            'portal',
            'Signed restore request published to the bridge outbox.',
            $actorId
        );
        return ['operation_id' => $operationId, 'status_code' => 'queued', 'duplicate' => false];
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        if (str_contains(strtolower($exception->getMessage()), 'unique')) {
            $active = portal_get_active_document_version_operation($database, $documentId);
            if ($active !== null) {
                return ['operation_id' => (string) $active['operation_id'], 'status_code' => (string) $active['status_code'], 'duplicate' => true];
            }
        }
        throw $exception;
    }
}

function portal_get_document_version_operation(PDO $database, string $operationId): ?array
{
    $operationId = portal_valid_public_id($operationId);
    if ($operationId === '' || !portal_document_version_operation_schema_available($database)) {
        return null;
    }
    $statement = $database->prepare(
        'SELECT CONVERT(varchar(36), o.operation_id) AS operation_id, o.document_id,
                CONVERT(varchar(36), d.public_id) AS document_public_id,
                o.operation_type, o.status_code,
                o.source_status_code, o.expected_current_hash, o.target_content_hash, o.previous_content_hash,
                o.actual_content_hash, o.drive_sync_status, o.requested_by_user_id, o.requested_at, o.started_at,
                o.local_restored_at, o.indexed_at, o.preview_ready_at, o.completed_at, o.error_code, o.error_message, o.updated_at
         FROM dbo.document_version_operations o
         INNER JOIN dbo.documents d ON d.document_id = o.document_id
         WHERE o.operation_id = ?'
    );
    $statement->execute([$operationId]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function portal_get_document_version_operation_events(PDO $database, string $operationId): array
{
    $operationId = portal_valid_public_id($operationId);
    if ($operationId === '' || !portal_document_version_operation_schema_available($database)) {
        return [];
    }
    $statement = $database->prepare(
        'SELECT status_code, event_code, event_source, detail_message, actor_user_id, occurred_at
         FROM dbo.document_version_operation_events WHERE operation_id = ? ORDER BY occurred_at, event_id'
    );
    $statement->execute([$operationId]);
    return $statement->fetchAll();
}

function portal_list_document_version_operations(PDO $database, int $documentId, int $limit = 20): array
{
    if ($documentId <= 0 || !portal_document_version_operation_schema_available($database)) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $statement = $database->prepare(
        'SELECT TOP (' . $limit . ') CONVERT(varchar(36), operation_id) AS operation_id, status_code,
                target_content_hash, drive_sync_status, requested_at, completed_at, error_code
         FROM dbo.document_version_operations WHERE document_id = ? ORDER BY requested_at DESC'
    );
    $statement->execute([$documentId]);
    return $statement->fetchAll();
}

function portal_read_document_version_result_file(string $path, string $operationId): ?array
{
    if (!is_file($path) || is_link($path)) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size < 20 || $size > PORTAL_DOCUMENT_VERSION_MANIFEST_MAX_BYTES) {
        return null;
    }
    try {
        $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    return is_array($result)
        && (int) ($result['schema_version'] ?? 0) === 1
        && portal_valid_public_id((string) ($result['operation_id'] ?? '')) === $operationId
        ? $result : null;
}

function portal_reconcile_document_version_operation_outbox(PDO $database, array $config, ?string $onlyOperationId = null): void
{
    if (!portal_document_version_operation_schema_available($database)) {
        return;
    }
    $parameters = [];
    $where = "o.status_code = 'queued'";
    if ($onlyOperationId !== null) {
        $onlyOperationId = portal_valid_public_id($onlyOperationId);
        if ($onlyOperationId === '') { return; }
        $where .= ' AND o.operation_id = ?';
        $parameters[] = $onlyOperationId;
    }
    $statement = $database->prepare(
        "SELECT CONVERT(varchar(36),o.operation_id) AS operation_id,o.expected_current_hash,o.target_content_hash,
                o.requested_by_user_id,d.document_id,CONVERT(varchar(36),d.public_id) AS public_id,d.relative_path,
                d.file_name,d.extension,d.status_code,d.content_hash,d.file_size_bytes,d.source_modified_at
         FROM dbo.document_version_operations o
         INNER JOIN dbo.documents d ON d.document_id=o.document_id WHERE $where"
    );
    $statement->execute($parameters);
    $requestRoot = rtrim(portal_document_version_request_directory($config), DIRECTORY_SEPARATOR);
    foreach ($statement->fetchAll() as $row) {
        $operationId = portal_valid_public_id((string) $row['operation_id']);
        if ($operationId === '') { continue; }
        $present = false;
        foreach (['pending','processing','completed','failed'] as $state) {
            $path = dirname($requestRoot) . DIRECTORY_SEPARATOR . $state . DIRECTORY_SEPARATOR . $operationId . '.json';
            if (is_file($path) && !is_link($path)) { $present = true; break; }
        }
        if ($present) { continue; }
        $expected = portal_document_version_hash((string) $row['expected_current_hash']);
        $catalogHash = portal_document_version_hash((string) $row['content_hash']);
        if ($expected === '' || $catalogHash !== $expected) {
            $update = $database->prepare("UPDATE dbo.document_version_operations SET status_code='conflict',error_code='CURRENT_VERSION_CONFLICT',completed_at=COALESCE(completed_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE operation_id=? AND status_code='queued'");
            $update->execute([$operationId]);
            portal_insert_document_version_operation_event($database,$operationId,'conflict','CURRENT_VERSION_CONFLICT','portal','Outbox reconciliation found a changed catalog hash.');
            continue;
        }
        $document = $row;
        $document['content_hash'] = $expected;
        $scopedConfig = portal_document_version_config_for_document($config, $document);
        portal_queue_document_version_restore($scopedConfig,$document,(string)$row['target_content_hash'],['user_id'=>(int)$row['requested_by_user_id']],$operationId);
        portal_insert_document_version_operation_event($database,$operationId,'queued','REQUEST_QUEUED','portal','Signed restore request published by durable outbox reconciliation.',(int)$row['requested_by_user_id']);
    }
}

function portal_sync_document_version_operation_results(PDO $database, array $config, ?string $onlyOperationId = null): void
{
    if (!portal_document_version_operation_schema_available($database)) {
        return;
    }
    portal_reconcile_document_version_operation_outbox($database,$config,$onlyOperationId);
    $sessionRoot = realpath((string) ($config['session_save_path'] ?? ''));
    if ($sessionRoot === false || !is_dir($sessionRoot) || is_link($sessionRoot)) {
        return;
    }
    $requestRoot = $sessionRoot . DIRECTORY_SEPARATOR . 'document-version-requests';
    $operations = [];
    if ($onlyOperationId !== null) {
        $operation = portal_get_document_version_operation($database, $onlyOperationId);
        if ($operation !== null) {
            $operations[] = $operation;
        }
    } else {
        $operations = $database->query(
            "SELECT CONVERT(varchar(36), operation_id) AS operation_id, operation_type, status_code,
                    expected_current_hash, target_content_hash
             FROM dbo.document_version_operations
             WHERE status_code IN ('queued','running','local-restored','indexed')"
        )->fetchAll();
    }
    foreach ($operations as $operation) {
        $operationId = portal_valid_public_id((string) $operation['operation_id']);
        if ($operationId === '') {
            continue;
        }
        $processing = $requestRoot . DIRECTORY_SEPARATOR . 'processing' . DIRECTORY_SEPARATOR . $operationId . '.json';
        $completed = $requestRoot . DIRECTORY_SEPARATOR . 'completed' . DIRECTORY_SEPARATOR . $operationId . '.json';
        $failed = $requestRoot . DIRECTORY_SEPARATOR . 'failed' . DIRECTORY_SEPARATOR . $operationId . '.json';
        if (is_file($processing) && in_array((string) $operation['status_code'], ['queued','running'], true)) {
            $update = $database->prepare(
                "UPDATE dbo.document_version_operations SET status_code = 'running', started_at = COALESCE(started_at, SYSUTCDATETIME()),
                        updated_at = SYSUTCDATETIME() WHERE operation_id = ? AND status_code = 'queued'"
            );
            $update->execute([$operationId]);
            portal_insert_document_version_operation_event($database, $operationId, 'running', 'BRIDGE_CLAIMED', 'bridge');
        }
        $result = portal_read_document_version_result_file($completed, $operationId);
        if ($result !== null && (string) ($result['status'] ?? '') === 'local-restored') {
            $previousHash = portal_document_version_hash((string) ($result['previous_hash'] ?? ''));
            $currentHash = portal_document_version_hash((string) ($result['current_hash'] ?? ''));
            if ($previousHash !== '' && $currentHash !== ''
                && hash_equals(portal_document_version_hash((string) $operation['expected_current_hash']), $previousHash)
                && hash_equals(portal_document_version_hash((string) $operation['target_content_hash']), $currentHash)) {
                $update = $database->prepare(
                    "UPDATE dbo.document_version_operations
                     SET status_code = 'local-restored', started_at = COALESCE(started_at, SYSUTCDATETIME()),
                         previous_content_hash = ?, actual_content_hash = ?, local_restored_at = COALESCE(local_restored_at, SYSUTCDATETIME()),
                         updated_at = SYSUTCDATETIME()
                     WHERE operation_id = ? AND status_code IN ('queued','running','local-restored')"
                );
                $update->execute([$previousHash, $currentHash, $operationId]);
                portal_insert_document_version_operation_event($database, $operationId, 'local-restored', 'LOCAL_SOURCE_RESTORED', 'bridge');
            }
        }
        $failure = portal_read_document_version_result_file($failed, $operationId);
        if ($failure !== null) {
            $errorCode = portal_trim_text((string) ($failure['error_code'] ?? 'RESTORE_FAILED'), 64);
            $status = $errorCode === 'CURRENT_VERSION_CONFLICT' ? 'conflict' : 'failed';
            $update = $database->prepare(
                "UPDATE dbo.document_version_operations
                 SET status_code = ?, error_code = ?, error_message = ?, completed_at = COALESCE(completed_at, SYSUTCDATETIME()),
                     updated_at = SYSUTCDATETIME()
                 WHERE operation_id = ? AND status_code IN ('queued','running')"
            );
            $update->execute([$status, $errorCode, portal_trim_text((string) ($failure['error_message'] ?? ''), 1000) ?: null, $operationId]);
            portal_insert_document_version_operation_event($database, $operationId, $status, $status === 'conflict' ? 'CURRENT_VERSION_CONFLICT' : 'RESTORE_FAILED', 'bridge', $errorCode);
        }
    }
}

function portal_mark_document_version_operation_indexed(PDO $database, array $approval, string $sourceHash): void
{
    $operationId = portal_valid_public_id((string) ($approval['operation_id'] ?? ''));
    $sourceHash = portal_document_version_hash($sourceHash);
    if ($operationId === '' || $sourceHash === '' || !portal_document_version_operation_schema_available($database)) {
        return;
    }
    $update = $database->prepare(
        "UPDATE dbo.document_version_operations
         SET status_code = 'indexed', actual_content_hash = ?, indexed_at = COALESCE(indexed_at, SYSUTCDATETIME()),
             updated_at = SYSUTCDATETIME()
         WHERE operation_id = ? AND target_content_hash = ?
           AND status_code IN ('queued','running','local-restored','indexed')"
    );
    $update->execute([$sourceHash, $operationId, $sourceHash]);
    if ($update->rowCount() > 0) {
        portal_insert_document_version_operation_event($database, $operationId, 'indexed', 'PORTAL_INDEXED', 'sync');
    }
}

function portal_advance_document_version_operation(PDO $database, array $operation): array
{
    $status = (string) ($operation['status_code'] ?? '');
    if (!in_array($status, ['local-restored','indexed'], true)) {
        return $operation;
    }
    $statement = $database->prepare('SELECT extension, content_hash FROM dbo.documents WHERE document_id = ?');
    $statement->execute([(int) $operation['document_id']]);
    $document = $statement->fetch();
    if ($document === false || !hash_equals((string) $operation['target_content_hash'], strtolower((string) ($document['content_hash'] ?? '')))) {
        return $operation;
    }
    $operationId = (string) $operation['operation_id'];
    if ($status === 'local-restored') {
        $update = $database->prepare(
            "UPDATE dbo.document_version_operations SET status_code = 'indexed', actual_content_hash = target_content_hash,
                    indexed_at = COALESCE(indexed_at, SYSUTCDATETIME()), updated_at = SYSUTCDATETIME()
             WHERE operation_id = ? AND status_code = 'local-restored'"
        );
        $update->execute([$operationId]);
        portal_insert_document_version_operation_event($database, $operationId, 'indexed', 'PORTAL_INDEXED', 'sync');
        $status = 'indexed';
    }
    if ($status === 'indexed') {
        $extension = strtolower((string) $document['extension']);
        $finalStatus = 'completed';
        $eventCode = 'OPERATION_COMPLETED';
        $eventSource = 'sync';
        $timeColumn = 'completed_at';
        if ($extension === 'pptx') {
            $ready = $database->prepare(
                "SELECT TOP (1) 1
                 FROM dbo.document_versions dv
                 INNER JOIN dbo.presentation_renditions r ON r.document_version_id = dv.version_id
                 WHERE dv.document_id = ? AND dv.source_content_hash = ?
                   AND r.status_code = 'ready' AND r.is_current = 1"
            );
            $ready->execute([(int) $operation['document_id'], (string) $operation['target_content_hash']]);
            if ($ready->fetchColumn() === false) {
                return portal_get_document_version_operation($database, $operationId) ?? $operation;
            }
            $finalStatus = 'preview-ready';
            $eventCode = 'PREVIEW_READY';
            $eventSource = 'preview';
            $timeColumn = 'preview_ready_at';
        }
        $update = $database->prepare(
            "UPDATE dbo.document_version_operations SET status_code = ?, {$timeColumn} = COALESCE({$timeColumn}, SYSUTCDATETIME()),
                    completed_at = COALESCE(completed_at, SYSUTCDATETIME()), updated_at = SYSUTCDATETIME()
             WHERE operation_id = ? AND status_code = 'indexed'"
        );
        $update->execute([$finalStatus, $operationId]);
        portal_insert_document_version_operation_event($database, $operationId, $finalStatus, $eventCode, $eventSource);
    }
    return portal_get_document_version_operation($database, $operationId) ?? $operation;
}

function portal_refresh_document_version_operation(PDO $database, array $config, string $operationId): ?array
{
    $operationId = portal_valid_public_id($operationId);
    if ($operationId === '') {
        return null;
    }
    portal_sync_document_version_operation_results($database, $config, $operationId);
    $operation = portal_get_document_version_operation($database, $operationId);
    return $operation === null ? null : portal_advance_document_version_operation($database, $operation);
}
