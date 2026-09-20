<?php
declare(strict_types=1);

function portal_sync_binding_path(): string
{
    return __DIR__ . '/settings/sync-service-binding.json';
}

function portal_sync_runtime_root(): string
{
    $raw = (string) file_get_contents(portal_sync_binding_path());
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_keys($data) !== ['schema_version', 'runtime_root'] || (int) $data['schema_version'] !== 1) {
        throw new RuntimeException('同步服務設定不正確。');
    }
    $root = (string) $data['runtime_root'];
    if ($root !== 'C:\\TWWATER\\runtime\\sync-service' || portal_path_has_reparse_component($root)) {
        throw new RuntimeException('同步服務路徑未獲授權。');
    }
    return $root;
}

function portal_sync_runtime_paths(): array
{
    $root = portal_sync_runtime_root();
    return [
        'root' => $root,
        'requests' => $root . '\\requests',
        'results' => $root . '\\results',
        'history' => $root . '\\history',
        'cancel' => $root . '\\cancel',
        'service_status' => $root . '\\results\\service-status.json',
        'execute_lock' => $root . '\\requests\\.execute-confirm.lock',
    ];
}

function portal_sync_direction(string $value): string
{
    if (!in_array($value, ['drive_to_library', 'library_to_drive', 'two_way'], true)) {
        throw new RuntimeException('同步方向不正確。');
    }
    return $value;
}

function portal_sync_direction_label(string $value): string
{
    return match (portal_sync_direction($value)) {
        'drive_to_library' => 'Google Drive → 網站 document-library',
        'library_to_drive' => '網站 document-library → Google Drive',
        'two_way' => '雙向同步',
    };
}

function portal_sync_service_ready(array $paths): bool
{
    $path = (string) $paths['service_status'];
    if (!is_file($path) || portal_path_has_reparse_component($path)) {
        return false;
    }
    $row = json_decode((string) file_get_contents($path), true);
    if (!is_array($row) || (int) ($row['schema_version'] ?? 0) !== 1 || !in_array((string) ($row['status'] ?? ''), ['ready', 'running'], true)) {
        return false;
    }
    $updated = strtotime((string) ($row['updated_at'] ?? ''));
    return $updated !== false && abs(time() - $updated) <= 30;
}

function portal_sync_execute_lock(array $paths)
{
    $path = (string) $paths['execute_lock'];
    if (file_exists($path) && portal_path_has_reparse_component($path)) {
        throw new RuntimeException('同步確認鎖定檔不安全。');
    }
    $handle = fopen($path, 'c+b');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('同步確認目前無法鎖定。');
    }
    return $handle;
}

function portal_sync_read_json_file(string $path): ?array
{
    if (!is_file($path) || portal_path_has_reparse_component($path)) {
        return null;
    }
    $row = json_decode((string) file_get_contents($path), true);
    return is_array($row) ? $row : null;
}

function portal_sync_metrics_valid(array $row): bool
{
    foreach (['add_count', 'update_count', 'delete_count', 'conflict_count', 'access_denied_count'] as $field) {
        if (!array_key_exists($field, $row) || !is_int($row[$field]) || $row[$field] < 0) {
            return false;
        }
    }
    return true;
}

function portal_sync_confirmable_json_rows(string $directory): array
{
    if (!is_dir($directory) || portal_path_has_reparse_component($directory)) {
        throw new RuntimeException('同步狀態無法安全讀取。');
    }
    $paths = glob($directory . '\\*.json');
    if ($paths === false) {
        throw new RuntimeException('同步狀態無法安全讀取。');
    }
    $rows = [];
    foreach ($paths as $path) {
        if (!is_file($path) || portal_path_has_reparse_component($path)) {
            throw new RuntimeException('同步狀態無法安全讀取。');
        }
        $json = @file_get_contents($path);
        $row = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($row)) {
            throw new RuntimeException('同步狀態無法安全讀取。');
        }
        $rows[] = $row;
    }
    return $rows;
}

function portal_sync_valid_preview(array $paths, string $previewId, string $direction, int $requestedBy): bool
{
    $preview = null;
    $resultRows = portal_sync_confirmable_json_rows((string) $paths['results']);
    foreach ($resultRows as $row) {
        if ((int) ($row['schema_version'] ?? 0) === 1
            && (string) ($row['kind'] ?? '') === 'preview'
            && (string) ($row['status'] ?? '') === 'preview_ready'
            && (string) ($row['preview_id'] ?? '') === $previewId
            && (string) ($row['direction'] ?? '') === $direction
            && (int) ($row['requested_by'] ?? 0) === $requestedBy
            && ($row['metrics_recorded'] ?? false) === true
            && portal_sync_metrics_valid($row)) {
            $updated = strtotime((string) ($row['updated_at'] ?? ''));
            if ($updated !== false && time() - $updated >= 0 && time() - $updated <= 1800) {
                $preview = $row;
            }
        }
    }
    if ($preview === null) {
        return false;
    }

    $preview_consumed = false;
    foreach (array_merge(portal_sync_confirmable_json_rows((string) $paths['requests']), $resultRows) as $row) {
        if ((string) ($row['kind'] ?? '') === 'execute'
            && (string) ($row['preview_id'] ?? '') === $previewId
            && (string) ($row['direction'] ?? '') === $direction) {
            $preview_consumed = true;
            break;
        }
    }
    return !$preview_consumed;
}

function portal_sync_preview_confirmable(array $item, int $viewerId): bool
{
    if ($viewerId <= 0
        || (int) ($item['requested_by'] ?? 0) !== $viewerId
        || ($item['metrics_recorded'] ?? false) !== true
        || (string) ($item['status'] ?? '') !== 'preview_ready'
        || !preg_match('/^[a-f0-9]{32}$/', (string) ($item['preview_id'] ?? ''))) {
        return false;
    }
    try {
        return portal_sync_valid_preview(
            portal_sync_runtime_paths(),
            (string) $item['preview_id'],
            (string) ($item['direction'] ?? ''),
            $viewerId
        );
    } catch (Throwable) {
        return false;
    }
}

function portal_sync_enqueue_request(PDO $database, array $admin, array $paths, string $direction, string $kind, string $previewId, int $requestedBy): string
{
    $id = bin2hex(random_bytes(16));
    $record = [
        'schema_version' => 1,
        'request_id' => $id,
        'kind' => $kind,
        'direction' => $direction,
        'preview_id' => $previewId,
        'requested_at' => gmdate('c'),
        'requested_by' => $requestedBy,
    ];
    $final = $paths['requests'] . '\\' . $id . '.json';
    $staging = $paths['requests'] . '\\.' . $id . '.pending';
    $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($staging, $encoded, LOCK_EX) !== strlen($encoded)) {
        @unlink($staging);
        throw new RuntimeException('同步請求無法送出。');
    }
    try {
        portal_state_append_audit_event($database, $requestedBy, 'sync_' . $kind . '_requested', null, ['request_id' => $id, 'direction' => $direction]);
    } catch (Throwable $error) {
        @unlink($staging);
        throw $error;
    }
    if (!rename($staging, $final)) {
        @unlink($staging);
        try {
            portal_state_append_audit_event($database, $requestedBy, 'sync_request_publish_failed', null, ['request_id' => $id, 'direction' => $direction]);
        } catch (Throwable) { }
        throw new RuntimeException('同步請求無法送出。');
    }
    return $id;
}

function portal_sync_request_create(PDO $database, array $admin, string $direction, string $kind, string $previewId = ''): string
{
    $direction = portal_sync_direction($direction);
    if (!in_array($kind, ['preview', 'execute'], true)) {
        throw new RuntimeException('同步請求類型不正確。');
    }
    $paths = portal_sync_runtime_paths();
    if (!is_dir($paths['requests']) || portal_path_has_reparse_component($paths['requests']) || !portal_sync_service_ready($paths)) {
        throw new RuntimeException('同步服務尚未就緒。');
    }
    $requestedBy = (int) ($admin['user_id'] ?? 0);
    if ($requestedBy <= 0) {
        throw new RuntimeException('同步請求身分不正確。');
    }
    if ($kind !== 'execute') {
        return portal_sync_enqueue_request($database, $admin, $paths, $direction, $kind, '', $requestedBy);
    }

    $lock = portal_sync_execute_lock($paths);
    try {
        if (!preg_match('/^[a-f0-9]{32}$/', $previewId) || !portal_sync_valid_preview($paths, $previewId, $direction, $requestedBy)) {
            throw new RuntimeException('同步預覽已失效、已執行，或不屬於目前管理員。');
        }
        return portal_sync_enqueue_request($database, $admin, $paths, $direction, $kind, $previewId, $requestedBy);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function portal_sync_active_owned_request(array $paths, string $requestId, int $requestedBy): bool
{
    $request = portal_sync_read_json_file($paths['requests'] . '\\' . $requestId . '.json');
    if (!is_array($request)
        || (int) ($request['schema_version'] ?? 0) !== 1
        || (string) ($request['request_id'] ?? '') !== $requestId
        || (int) ($request['requested_by'] ?? 0) !== $requestedBy) {
        return false;
    }
    $result = portal_sync_read_json_file($paths['results'] . '\\' . $requestId . '.json');
    return !is_array($result) || (string) ($result['status'] ?? '') === 'running';
}

function portal_sync_request_cancel(PDO $database, array $admin, string $requestId): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
        throw new RuntimeException('同步請求識別碼不正確。');
    }
    $paths = portal_sync_runtime_paths();
    if (!is_dir($paths['cancel']) || portal_path_has_reparse_component($paths['cancel'])) {
        throw new RuntimeException('取消通道尚未就緒。');
    }
    $cancel_requested_by = (int) ($admin['user_id'] ?? 0);
    if ($cancel_requested_by <= 0 || !portal_sync_active_owned_request($paths, $requestId, $cancel_requested_by)) {
        throw new RuntimeException('同步請求已不在可取消狀態。');
    }
    $path = $paths['cancel'] . '\\' . $requestId . '.cancel';
    $value = gmdate('c');
    portal_state_append_audit_event($database, $cancel_requested_by, 'sync_cancel_requested', null, ['request_id' => $requestId]);
    if (file_put_contents($path, $value, LOCK_EX) !== strlen($value)) {
        try {
            portal_state_append_audit_event($database, $cancel_requested_by, 'sync_cancel_publish_failed', null, ['request_id' => $requestId]);
        } catch (Throwable) {
        }
        throw new RuntimeException('取消要求無法送出。');
    }
}

function portal_sync_normalize_result(array $row): ?array
{
    if ((int) ($row['schema_version'] ?? 0) !== 1 || !preg_match('/^[a-f0-9]{32}$/', (string) ($row['request_id'] ?? ''))) {
        return null;
    }
    $kind = (string) ($row['kind'] ?? '');
    $direction = (string) ($row['direction'] ?? '');
    $state = (string) ($row['status'] ?? '');
    $preview = $row['preview_id'] ?? null;
    if (!in_array($kind, ['preview', 'execute'], true)
        || !in_array($direction, ['drive_to_library', 'library_to_drive', 'two_way'], true)
        || !in_array($state, ['running', 'preview_ready', 'success', 'completed_with_attention', 'failed', 'aborted_unverified'], true)
        || ($preview !== null && (!is_string($preview) || !preg_match('/^[a-f0-9]{32}$/', $preview)))) {
        return null;
    }
    $metrics = ($row['metrics_recorded'] ?? false) === true;
    if ($metrics && !portal_sync_metrics_valid($row)) {
        return null;
    }
    return [
        'request_id' => (string) $row['request_id'],
        'kind' => $kind,
        'direction' => $direction,
        'status' => $state,
        'preview_id' => $preview,
        'requested_by' => max(0, (int) ($row['requested_by'] ?? 0)),
        'metrics_recorded' => $metrics,
        'add_count' => $metrics ? max(0, (int) ($row['add_count'] ?? 0)) : 0,
        'update_count' => $metrics ? max(0, (int) ($row['update_count'] ?? 0)) : 0,
        'delete_count' => $metrics ? max(0, (int) ($row['delete_count'] ?? 0)) : 0,
        'conflict_count' => $metrics ? max(0, (int) ($row['conflict_count'] ?? 0)) : 0,
        'access_denied_count' => $metrics ? max(0, (int) ($row['access_denied_count'] ?? 0)) : 0,
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function portal_sync_sort_items_desc(array $items): array
{
    usort($items, static function (array $left, array $right): int {
        $time = strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        return $time !== 0 ? $time : strcmp((string) ($right['request_id'] ?? ''), (string) ($left['request_id'] ?? ''));
    });
    return $items;
}

function portal_sync_operation_candidate(array $items, int $viewerId, ?callable $confirmable = null): ?array
{
    $items = portal_sync_sort_items_desc($items);
    foreach ($items as $item) {
        if (in_array((string) ($item['status'] ?? ''), ['queued', 'running'], true)) {
            return $item;
        }
    }
    $confirmable ??= static fn(array $item): bool => portal_sync_preview_confirmable($item, $viewerId);
    foreach ($items as $item) {
        if ($confirmable($item)) {
            return $item;
        }
    }
    return null;
}

function portal_sync_history_query(array $query): array
{
    $status = (string) ($query['status'] ?? '');
    $direction = (string) ($query['direction'] ?? '');
    $start = (string) ($query['start_date'] ?? '');
    $end = (string) ($query['end_date'] ?? '');
    $prefix = (string) ($query['request_id_prefix'] ?? '');
    $allowedStates = ['preview_ready', 'queued', 'running', 'success', 'completed_with_attention', 'failed', 'aborted_unverified'];
    if (!in_array($status, $allowedStates, true)) {
        $status = '';
    }
    if (!in_array($direction, ['drive_to_library', 'library_to_drive', 'two_way'], true)) {
        $direction = '';
    }
    $timezone = new DateTimeZone('Asia/Taipei');
    $parseDate = static function (string $value) use ($timezone): ?DateTimeImmutable {
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        return $date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value ? $date : null;
    };
    $startDate = $parseDate($start);
    $endDate = $parseDate($end);
    if (($start !== '' && $startDate === null) || ($end !== '' && $endDate === null) || ($startDate !== null && $endDate !== null && $endDate < $startDate)) {
        $start = '';
        $end = '';
    }
    if ($prefix !== '' && !preg_match('/^[a-f0-9]{1,32}$/', $prefix)) {
        $prefix = '';
    }
    return ['status' => $status, 'direction' => $direction, 'start_date' => $start, 'end_date' => $end, 'request_id_prefix' => $prefix];
}

function portal_sync_history_page(array $items, array $filters, ?array $cursor = null, int $pageSize = 10): array
{
    $timezone = new DateTimeZone('Asia/Taipei');
    $filtered = array_values(array_filter($items, static function (array $item) use ($filters, $timezone): bool {
        if ($filters['status'] !== '' && (string) ($item['status'] ?? '') !== $filters['status']) {
            return false;
        }
        if ($filters['direction'] !== '' && (string) ($item['direction'] ?? '') !== $filters['direction']) {
            return false;
        }
        if ($filters['request_id_prefix'] !== '' && !str_starts_with((string) ($item['request_id'] ?? ''), $filters['request_id_prefix'])) {
            return false;
        }
        if ($filters['start_date'] === '' && $filters['end_date'] === '') {
            return true;
        }
        try {
            $date = (new DateTimeImmutable((string) ($item['updated_at'] ?? '')))->setTimezone($timezone)->format('Y-m-d');
        } catch (Throwable) {
            return false;
        }
        return ($filters['start_date'] === '' || $date >= $filters['start_date']) && ($filters['end_date'] === '' || $date <= $filters['end_date']);
    }));
    $filtered = portal_sync_sort_items_desc($filtered);
    $total = count($filtered);
    if (is_array($cursor) && preg_match('/^[a-f0-9]{32}$/', (string) ($cursor['request_id'] ?? '')) && is_string($cursor['updated_at'] ?? null)) {
        $filtered = array_values(array_filter($filtered, static fn(array $item): bool => strcmp((string) $item['updated_at'], $cursor['updated_at']) < 0 || (strcmp((string) $item['updated_at'], $cursor['updated_at']) === 0 && strcmp((string) $item['request_id'], (string) $cursor['request_id']) < 0)));
    }
    $visible = array_slice($filtered, 0, max(1, min(10, $pageSize)));
    $last = $visible === [] ? null : $visible[count($visible) - 1];
    return ['items' => $visible, 'total_count' => $total, 'has_more' => count($filtered) > count($visible), 'next_cursor' => $last === null ? null : ['updated_at' => (string) $last['updated_at'], 'request_id' => (string) $last['request_id']]];
}

function portal_sync_status(): array
{
    try {
        $paths = portal_sync_runtime_paths();
        if (!is_dir($paths['results']) || !is_dir($paths['requests']) || portal_path_has_reparse_component($paths['results']) || portal_path_has_reparse_component($paths['requests'])) {
            return ['status' => 'not_ready', 'items' => []];
        }
        $items = [];
        $resultIds = [];
        foreach (glob($paths['results'] . '\\*.json') ?: [] as $path) {
            $row = portal_sync_read_json_file($path);
            $item = is_array($row) ? portal_sync_normalize_result($row) : null;
            if ($item === null) {
                continue;
            }
            $resultIds[$item['request_id']] = true;
            $items[] = $item;
        }
        foreach (glob($paths['requests'] . '\\*.json') ?: [] as $path) {
            $row = portal_sync_read_json_file($path);
            if (!is_array($row) || (int) ($row['schema_version'] ?? 0) !== 1 || !preg_match('/^[a-f0-9]{32}$/', (string) ($row['request_id'] ?? '')) || isset($resultIds[(string) $row['request_id']])) {
                continue;
            }
            $direction = (string) ($row['direction'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            if (!in_array($direction, ['drive_to_library', 'library_to_drive', 'two_way'], true) || !in_array($kind, ['preview', 'execute'], true)) {
                continue;
            }
            $items[] = [
                'request_id' => (string) $row['request_id'],
                'kind' => $kind,
                'direction' => $direction,
                'status' => 'queued',
                'preview_id' => $row['preview_id'] ?: null,
                'requested_by' => max(0, (int) ($row['requested_by'] ?? 0)),
                'metrics_recorded' => false,
                'add_count' => 0,
                'update_count' => 0,
                'delete_count' => 0,
                'conflict_count' => 0,
                'access_denied_count' => 0,
                'updated_at' => (string) ($row['requested_at'] ?? ''),
            ];
        }
        return ['status' => portal_sync_service_ready($paths) ? 'ready' : 'not_ready', 'items' => portal_sync_sort_items_desc($items)];
    } catch (Throwable) {
        return ['status' => 'not_ready', 'items' => []];
    }
}
