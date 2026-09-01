<?php
declare(strict_types=1);

function portal_image_binding_hmac(array $binding, string $key): string
{
    if (strlen($key) !== 32) {
        throw new RuntimeException('IMAGE_COMMAND_KEY_INVALID');
    }
    $values = [
        'binding_id' => strtolower((string) ($binding['binding_id'] ?? '')),
        'issued_utc' => (string) ($binding['issued_utc'] ?? ''),
        'expires_utc' => (string) ($binding['expires_utc'] ?? ''),
        'public_id' => strtolower((string) ($binding['public_id'] ?? '')),
        'relative_path' => str_replace('\\', '/', (string) ($binding['relative_path'] ?? '')),
        'source_hash' => strtolower((string) ($binding['source_hash'] ?? '')),
        'extension' => strtolower((string) ($binding['extension'] ?? '')),
        'source_width' => (string) ((int) ($binding['source_width'] ?? 0)),
        'source_height' => (string) ((int) ($binding['source_height'] ?? 0)),
        'regions' => (string) ($binding['regions'] ?? ''),
    ];
    foreach ($values as $value) {
        if ($value === '' || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('IMAGE_BINDING_INVALID');
        }
    }
    $message = "twwater-image-binding-v2\nbinding_id={$values['binding_id']}\nissued_utc={$values['issued_utc']}\nexpires_utc={$values['expires_utc']}\npublic_id={$values['public_id']}\nrelative_path={$values['relative_path']}\nsource_hash={$values['source_hash']}\nextension={$values['extension']}\nsource_width={$values['source_width']}\nsource_height={$values['source_height']}\nregions={$values['regions']}";
    return hash_hmac('sha256', $message, $key);
}

function portal_image_restore_hmac(array $request, string $key): string
{
    if (strlen($key) !== 32) {
        throw new RuntimeException('IMAGE_COMMAND_KEY_INVALID');
    }
    $values = [
        'schema_version' => (string) ((int) ($request['schema_version'] ?? 0)),
        'operation' => (string) ($request['operation'] ?? ''),
        'archive_scope' => (string) ($request['archive_scope'] ?? ''),
        'operation_id' => strtolower((string) ($request['operation_id'] ?? '')),
        'document_public_id' => strtolower((string) ($request['document_public_id'] ?? '')),
        'document_relative_path' => str_replace('\\', '/', (string) ($request['document_relative_path'] ?? '')),
        'extension' => strtolower((string) ($request['extension'] ?? '')),
        'target_content_hash' => strtolower((string) ($request['target_content_hash'] ?? '')),
        'expected_current_hash' => strtolower((string) ($request['expected_current_hash'] ?? '')),
        'requested_by_user_id' => (string) ((int) ($request['requested_by_user_id'] ?? 0)),
        'requested_utc' => (string) ($request['requested_utc'] ?? ''),
    ];
    foreach ($values as $value) {
        if ($value === '' || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('IMAGE_RESTORE_REQUEST_INVALID');
        }
    }
    $message = "twwater-image-restore-v1\nschema_version={$values['schema_version']}\noperation={$values['operation']}\narchive_scope={$values['archive_scope']}\noperation_id={$values['operation_id']}\ndocument_public_id={$values['document_public_id']}\ndocument_relative_path={$values['document_relative_path']}\nextension={$values['extension']}\ntarget_content_hash={$values['target_content_hash']}\nexpected_current_hash={$values['expected_current_hash']}\nrequested_by_user_id={$values['requested_by_user_id']}\nrequested_utc={$values['requested_utc']}";
    return hash_hmac('sha256', $message, $key);
}

function portal_image_command_key(array $config): string
{
    $archiveRoot = realpath((string) ($config['image_source_archive_root'] ?? ''));
    $keyPath = realpath((string) ($config['image_command_key_path'] ?? ''));
    if ($archiveRoot === false || $keyPath === false || !is_dir($archiveRoot) || !is_file($keyPath) || is_link($keyPath)) {
        throw new RuntimeException('IMAGE_COMMAND_KEY_INVALID');
    }
    $prefix = strtolower(rtrim($archiveRoot, '\\/') . DIRECTORY_SEPARATOR);
    if (!str_starts_with(strtolower($keyPath), $prefix)) {
        throw new RuntimeException('IMAGE_COMMAND_KEY_INVALID');
    }
    $key = file_get_contents($keyPath);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('IMAGE_COMMAND_KEY_INVALID');
    }
    return $key;
}

function portal_image_build_signed_binding(array $config, array $document, string $sourceHash, array $annotations, string $imagePath): array
{
    $publicId = portal_valid_public_id((string) ($document['public_id'] ?? ''));
    $relativePath = str_replace('\\', '/', (string) ($document['relative_path'] ?? ''));
    $extension = strtolower((string) ($document['extension'] ?? ''));
    $imageSize = @getimagesize($imagePath);
    if ($publicId === '' || $relativePath === '' || preg_match('/\A[0-9a-f]{64}\z/', $sourceHash) !== 1 || !in_array($extension, ['png','jpg','jpeg','webp'], true) || !is_array($imageSize)) {
        throw new RuntimeException('IMAGE_BINDING_INVALID');
    }
    $width = (int) ($imageSize[0] ?? 0);
    $height = (int) ($imageSize[1] ?? 0);
    $regions = [];
    foreach ($annotations as $annotation) {
        $x1 = max(0, min($width - 1, (int) floor((float) ($annotation['x_norm'] ?? -1) * $width)));
        $y1 = max(0, min($height - 1, (int) floor((float) ($annotation['y_norm'] ?? -1) * $height)));
        $x2 = max(1, min($width, (int) ceil(((float) ($annotation['x_norm'] ?? -1) + (float) ($annotation['width_norm'] ?? 0)) * $width)));
        $y2 = max(1, min($height, (int) ceil(((float) ($annotation['y_norm'] ?? -1) + (float) ($annotation['height_norm'] ?? 0)) * $height)));
        if ($x2 <= $x1 || $y2 <= $y1) {
            throw new RuntimeException('IMAGE_BINDING_INVALID');
        }
        $regions[] = "$x1,$y1,$x2,$y2";
    }
    if ($width <= 0 || $height <= 0 || $regions === []) {
        throw new RuntimeException('IMAGE_BINDING_INVALID');
    }
    sort($regions, SORT_STRING);
    $issuedAt = time();
    $binding = [
        'binding_id' => portal_document_version_uuid(),
        'issued_utc' => gmdate('Y-m-d\TH:i:s\Z', $issuedAt),
        'expires_utc' => gmdate('Y-m-d\TH:i:s\Z', $issuedAt + 86400),
        'public_id' => $publicId,
        'relative_path' => $relativePath,
        'source_hash' => strtolower($sourceHash),
        'extension' => $extension,
        'source_width' => $width,
        'source_height' => $height,
        'regions' => implode(';', array_values(array_unique($regions))),
    ];
    $binding['binding_hmac'] = portal_image_binding_hmac($binding, portal_image_command_key($config));
    return $binding;
}

const PORTAL_DOCUMENT_VERSION_MANIFEST_MAX_BYTES = 16384;
const PORTAL_DOCUMENT_VERSION_LIST_LIMIT = 100;

function portal_document_version_operation_status_catalog(): array
{
    return [
        'queued' => ['label' => 'queued', 'description' => '要求已排入佇列，等待文件橋接器處理。', 'terminal' => false],
        'running' => ['label' => 'running', 'description' => '文件橋接器正在驗證、封存目前版本並執行還原。', 'terminal' => false],
        'local-restored' => ['label' => 'local-restored', 'description' => '本機 Google Drive 來源已替換，等待網站快取與資料庫索引。', 'terminal' => false],
        'indexed' => ['label' => 'indexed', 'description' => '網站已索引目標內容，等待線上預覽完成。', 'terminal' => false],
        'preview-ready' => ['label' => 'preview-ready', 'description' => '線上預覽已完成。', 'terminal' => true],
        'completed' => ['label' => 'completed', 'description' => '版本操作已完成。', 'terminal' => true],
        'conflict' => ['label' => 'conflict', 'description' => '目前來源在排隊後已改變，系統未覆寫較新的內容。', 'terminal' => true],
        'failed' => ['label' => 'failed', 'description' => '版本操作失敗，來源未完成切換。', 'terminal' => true],
        'cancelled' => ['label' => 'cancelled', 'description' => '版本操作已取消。', 'terminal' => true],
    ];
}

function portal_version_root_is_separate_from_documents(string $versionRoot, string $documentRoot): bool
{
    $version = realpath($versionRoot);
    if ($version === false || !is_dir($version) || is_link($version)) {
        return false;
    }
    if ($documentRoot === '') {
        return true;
    }
    $documents = realpath($documentRoot);
    if ($documents === false || !is_dir($documents)) {
        return false;
    }
    $versionNormalized = strtolower(rtrim($version, '\\/'));
    $documentsNormalized = strtolower(rtrim($documents, '\\/'));
    return $versionNormalized !== $documentsNormalized
        && !str_starts_with($versionNormalized . DIRECTORY_SEPARATOR, $documentsNormalized . DIRECTORY_SEPARATOR)
        && !str_starts_with($documentsNormalized . DIRECTORY_SEPARATOR, $versionNormalized . DIRECTORY_SEPARATOR);
}

function portal_document_version_config_for_document(array $config, array $document): array
{
    $extension = strtolower((string) ($document['extension'] ?? ''));
    $isImage = in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
    if ($isImage && ($config['image_source_archive_enabled'] ?? false) === true) {
        $config['document_version_root'] = (string) $config['image_source_archive_root'];
        $config['document_version_enabled'] = true;
        $config['document_restore_enabled'] = !in_array($extension, ['gif', 'webp'], true)
            && ($config['image_command_enabled'] ?? false) === true;
        $config['document_version_scope'] = 'image-source';
        return $config;
    }
    $config['document_version_scope'] = 'document';
    return $config;
}

function portal_document_version_hash(string $value): string
{
    $value = strtolower(trim($value));
    return preg_match('/\A[0-9a-f]{64}\z/', $value) === 1 ? $value : '';
}

function portal_document_version_root(array $config): string
{
    if (($config['document_version_enabled'] ?? false) !== true) {
        throw new RuntimeException('文件版本管理尚未啟用。');
    }
    $root = realpath((string) ($config['document_version_root'] ?? ''));
    if ($root === false || !is_dir($root) || is_link($root)) {
        throw new RuntimeException('文件版本儲存目錄無效。');
    }
    return rtrim($root, '\\/');
}

function portal_document_version_directory(array $config, string $documentPublicId, string $contentHash): string
{
    $publicId = portal_valid_public_id($documentPublicId);
    $hash = portal_document_version_hash($contentHash);
    if ($publicId === '' || $hash === '') {
        throw new RuntimeException('文件版本識別碼無效。');
    }
    return portal_document_version_root($config)
        . DIRECTORY_SEPARATOR . $publicId
        . DIRECTORY_SEPARATOR . $hash;
}

function portal_read_document_version_manifest(array $config, array $document, string $contentHash): ?array
{
    $hash = portal_document_version_hash($contentHash);
    if ($hash === '') {
        return null;
    }
    try {
        $directory = portal_document_version_directory($config, (string) ($document['public_id'] ?? ''), $hash);
    } catch (Throwable) {
        return null;
    }
    $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) {
        return null;
    }
    $size = filesize($manifestPath);
    if ($size === false || $size < 2 || $size > PORTAL_DOCUMENT_VERSION_MANIFEST_MAX_BYTES) {
        return null;
    }
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($manifest)
        || (int) ($manifest['schema_version'] ?? 0) !== 1
        || portal_valid_public_id((string) ($manifest['document_public_id'] ?? '')) !== portal_valid_public_id((string) ($document['public_id'] ?? ''))
        || portal_document_version_hash((string) ($manifest['content_hash'] ?? '')) !== $hash
        || (string) ($manifest['document_relative_path'] ?? '') !== (string) ($document['relative_path'] ?? '')
        || (string) ($manifest['original_file_name'] ?? '') !== (string) ($document['file_name'] ?? '')
        || strtolower((string) ($manifest['extension'] ?? '')) !== strtolower((string) ($document['extension'] ?? ''))
        || (string) ($manifest['artifact_file'] ?? '') !== 'source.' . strtolower((string) ($document['extension'] ?? ''))
    ) {
        return null;
    }
    $artifactPath = $directory . DIRECTORY_SEPARATOR . (string) $manifest['artifact_file'];
    if (!is_file($artifactPath) || is_link($artifactPath)) {
        return null;
    }
    $artifactReal = realpath($artifactPath);
    $root = portal_document_version_root($config);
    if ($artifactReal === false
        || !str_starts_with(strtolower($artifactReal), strtolower($root . DIRECTORY_SEPARATOR))
        || filesize($artifactReal) !== (int) ($manifest['file_size_bytes'] ?? -1)
        || !hash_equals($hash, strtolower((string) hash_file('sha256', $artifactReal)))
    ) {
        return null;
    }
    $manifest['content_hash'] = $hash;
    $manifest['artifact_path'] = $artifactReal;
    return $manifest;
}

/**
 * @param array<string,int> $knownVersionNumbers map of lowercase SHA-256 to version number
 */
function portal_list_document_versions(array $config, array $document, array $knownVersionNumbers = []): array
{
    $currentHash = portal_document_version_hash((string) ($document['content_hash'] ?? ''));
    $versions = [];
    if ($currentHash !== '') {
        $versions[] = [
            'is_current' => true,
            'content_hash' => $currentHash,
            'version_number' => (int) ($knownVersionNumbers[$currentHash] ?? 0),
            'file_size_bytes' => (int) ($document['file_size_bytes'] ?? 0),
            'source_modified_at' => (string) ($document['source_modified_at'] ?? ''),
            'archived_utc' => null,
            'archived_reason' => null,
            'artifact_path' => null,
        ];
    }

    $publicId = portal_valid_public_id((string) ($document['public_id'] ?? ''));
    if ($publicId === '' || ($config['document_version_enabled'] ?? false) !== true) {
        return $versions;
    }
    try {
        $documentDirectory = portal_document_version_root($config) . DIRECTORY_SEPARATOR . $publicId;
    } catch (Throwable) {
        return $versions;
    }
    if (!is_dir($documentDirectory) || is_link($documentDirectory)) {
        return $versions;
    }
    $entries = scandir($documentDirectory);
    if ($entries === false) {
        return $versions;
    }
    foreach ($entries as $entry) {
        $hash = portal_document_version_hash($entry);
        if ($hash === '' || $hash === $currentHash) {
            continue;
        }
        $manifest = portal_read_document_version_manifest($config, $document, $hash);
        if ($manifest === null) {
            continue;
        }
        $versions[] = [
            'is_current' => false,
            'content_hash' => $hash,
            'version_number' => (int) ($knownVersionNumbers[$hash] ?? 0),
            'file_size_bytes' => (int) $manifest['file_size_bytes'],
            'source_modified_at' => (string) ($manifest['source_modified_utc'] ?? ''),
            'archived_utc' => (string) ($manifest['archived_utc'] ?? ''),
            'archived_reason' => (string) ($manifest['archived_reason'] ?? ''),
            'artifact_path' => (string) $manifest['artifact_path'],
        ];
        if (count($versions) >= PORTAL_DOCUMENT_VERSION_LIST_LIMIT + 1) {
            break;
        }
    }
    if (count($versions) > 1) {
        $current = array_shift($versions);
        usort($versions, static fn(array $a, array $b): int => strcmp((string) $b['archived_utc'], (string) $a['archived_utc']));
        array_unshift($versions, $current);
    }
    return $versions;
}

function portal_document_version_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function portal_document_version_request_directory(array $config): string
{
    $sessionRoot = realpath((string) ($config['session_save_path'] ?? ''));
    if ($sessionRoot === false || !is_dir($sessionRoot) || is_link($sessionRoot)) {
        throw new RuntimeException('版本還原佇列目錄無效。');
    }
    $requestRoot = $sessionRoot . DIRECTORY_SEPARATOR . 'document-version-requests';
    $pendingRoot = $requestRoot . DIRECTORY_SEPARATOR . 'pending';
    foreach ([$requestRoot, $pendingRoot] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('無法建立版本還原佇列。');
        }
        if (is_link($directory)) {
            throw new RuntimeException('版本還原佇列不可使用連結目錄。');
        }
    }
    return $pendingRoot;
}

function portal_queue_document_version_restore(array $config, array $document, string $targetHash, array $actor, string $operationId = ''): string
{
    $targetHash = portal_document_version_hash($targetHash);
    $currentHash = portal_document_version_hash((string) ($document['content_hash'] ?? ''));
    if ($targetHash === '' || $currentHash === '') {
        throw new RuntimeException('文件版本雜湊無效。');
    }
    if (hash_equals($currentHash, $targetHash)) {
        throw new RuntimeException('選取的版本已是目前版本。');
    }
    if (portal_read_document_version_manifest($config, $document, $targetHash) === null) {
        throw new RuntimeException('找不到可還原的封存版本。');
    }
    $publicId = portal_valid_public_id((string) ($document['public_id'] ?? ''));
    $relativePath = (string) ($document['relative_path'] ?? '');
    $actorId = (int) ($actor['user_id'] ?? 0);
    if ($publicId === '' || $relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0") || $actorId <= 0) {
        throw new RuntimeException('版本還原要求無效。');
    }
    $operationId = $operationId === '' ? portal_document_version_uuid() : portal_valid_public_id($operationId);
    if ($operationId === '') {
        throw new RuntimeException('版本操作識別碼無效。');
    }
    $scope = (string) ($config['document_version_scope'] ?? 'document');
    $request = [
        'schema_version' => $scope === 'image-source' ? 2 : 1,
        'operation' => 'restore',
        'archive_scope' => $scope,
        'operation_id' => $operationId,
        'document_public_id' => $publicId,
        'document_relative_path' => $relativePath,
        'original_file_name' => (string) ($document['file_name'] ?? ''),
        'extension' => strtolower((string) ($document['extension'] ?? '')),
        'target_content_hash' => $targetHash,
        'expected_current_hash' => $currentHash,
        'requested_by_user_id' => $actorId,
        'requested_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
    ];
    if ($scope === 'image-source') {
        $request['request_hmac'] = portal_image_restore_hmac($request, portal_image_command_key($config));
    }
    $pendingRoot = portal_document_version_request_directory($config);
    $temporary = $pendingRoot . DIRECTORY_SEPARATOR . '.' . $operationId . '.tmp';
    $destination = $pendingRoot . DIRECTORY_SEPARATOR . $operationId . '.json';
    $json = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('無法送出版本還原要求。');
    }
    return $operationId;
}

function portal_read_document_version_approvals(array $config): array
{
    $sessionRoot = realpath((string) ($config['session_save_path'] ?? ''));
    if ($sessionRoot === false || !is_dir($sessionRoot) || is_link($sessionRoot)) {
        return [];
    }
    $approvalRoot = $sessionRoot . DIRECTORY_SEPARATOR . 'document-version-approved';
    if (!is_dir($approvalRoot) || is_link($approvalRoot)) {
        return [];
    }
    $approvals = [];
    $entries = scandir($approvalRoot);
    if ($entries === false) {
        return [];
    }
    foreach ($entries as $entry) {
        if (count($approvals) >= 100
            || preg_match('/\A([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})-([0-9a-f]{64})\.json\z/i', $entry, $matches) !== 1
        ) {
            continue;
        }
        $path = $approvalRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path) || is_link($path)) {
            continue;
        }
        $size = filesize($path);
        if ($size === false || $size < 32 || $size > PORTAL_DOCUMENT_VERSION_MANIFEST_MAX_BYTES) {
            continue;
        }
        try {
            $approval = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        $publicId = portal_valid_public_id((string) ($approval['document_public_id'] ?? ''));
        $hash = portal_document_version_hash((string) ($approval['approved_content_hash'] ?? ''));
        $relativePath = str_replace('\\', '/', (string) ($approval['document_relative_path'] ?? ''));
        if (!is_array($approval)
            || (int) ($approval['schema_version'] ?? 0) !== 1
            || $publicId === ''
            || $hash === ''
            || $publicId !== strtolower($matches[1])
            || $hash !== strtolower($matches[2])
            || $relativePath === ''
            || str_contains($relativePath, '..')
            || str_starts_with($relativePath, '/')
        ) {
            continue;
        }
        $approval['document_public_id'] = $publicId;
        $approval['document_relative_path'] = $relativePath;
        $approval['approved_content_hash'] = $hash;
        $approval['_path'] = $path;
        $approvals[strtolower($relativePath)] = $approval;
    }
    return $approvals;
}

function portal_document_version_approval_matches(array $approval, array $document, string $sourceHash): bool
{
    $sourceHash = portal_document_version_hash($sourceHash);
    return $sourceHash !== ''
        && hash_equals((string) ($approval['approved_content_hash'] ?? ''), $sourceHash)
        && portal_valid_public_id((string) ($approval['document_public_id'] ?? '')) === portal_valid_public_id((string) ($document['public_id'] ?? ''))
        && strtolower(str_replace('\\', '/', (string) ($approval['document_relative_path'] ?? '')))
            === strtolower(str_replace('\\', '/', (string) ($document['relative_path'] ?? '')));
}

function portal_consume_document_version_approvals(array $approvals): void
{
    foreach ($approvals as $approval) {
        $path = (string) ($approval['_path'] ?? '');
        if ($path !== '' && is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }
}

function portal_document_version_blob_schema_ready(PDO $database): bool
{
    try {
        if ((int) $database->query("SELECT CONVERT(INT,SERVERPROPERTY('ProductMajorVersion'))")->fetchColumn() < 13
            || (int) $database->query("SELECT ISNULL(DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP'),0)")->fetchColumn() <= 0
            || (int) $database->query("SELECT CASE WHEN OBJECT_ID(N'dbo.document_version_contents',N'U') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn() !== 1) {
            return false;
        }
        $columnQuery = static function(PDO $database, string $object, array $expected): bool {
            $statement = $database->prepare("SELECT name,TYPE_NAME(user_type_id) type_name,max_length,scale,is_nullable FROM sys.columns WHERE object_id=OBJECT_ID(?)");
            $statement->execute([$object]);
            $actual = [];
            foreach ($statement->fetchAll() as $row) {
                if (isset($expected[$row['name']])) {
                    $actual[$row['name']] = [(string) $row['type_name'], (int) $row['max_length'], (int) $row['scale'], (int) $row['is_nullable']];
                }
            }
            return $actual === $expected;
        };
        if (!$columnQuery($database, 'dbo.document_version_contents', [
                'version_id' => ['bigint',8,0,0], 'content_hash' => ['char',64,0,0], 'file_size_bytes' => ['bigint',8,0,0],
                'content_bytes' => ['varbinary',-1,0,0], 'stored_at' => ['datetime2',7,3,0], 'rowver' => ['timestamp',8,0,0],
            ])
            || !$columnQuery($database, 'dbo.documents', [
                'current_version_id' => ['bigint',8,0,1], 'version_capture_status' => ['varchar',8,0,0],
                'version_capture_error_code' => ['varchar',64,0,1], 'version_capture_updated_at' => ['datetime2',7,3,0],
            ])) {
            return false;
        }
        $indexColumns = static function(PDO $database, string $object, string $name): array {
            $statement = $database->prepare("SELECT ix.is_unique,ix.is_disabled,ic.key_ordinal,COL_NAME(ic.object_id,ic.column_id) column_name FROM sys.indexes ix JOIN sys.index_columns ic ON ic.object_id=ix.object_id AND ic.index_id=ix.index_id WHERE ix.object_id=OBJECT_ID(?) AND ix.name=? AND ic.key_ordinal>0 ORDER BY ic.key_ordinal");
            $statement->execute([$object,$name]);
            return $statement->fetchAll();
        };
        $versionHashIndex = $indexColumns($database,'dbo.document_versions','UQ_document_versions_document_hash');
        $versionIdentityIndex = $indexColumns($database,'dbo.document_versions','UQ_document_versions_version_document');
        $contentPrimary = $database->query("SELECT ix.is_unique,ix.is_disabled,ic.key_ordinal,COL_NAME(ic.object_id,ic.column_id) column_name FROM sys.key_constraints kc JOIN sys.indexes ix ON ix.object_id=kc.parent_object_id AND ix.index_id=kc.unique_index_id JOIN sys.index_columns ic ON ic.object_id=ix.object_id AND ic.index_id=ix.index_id WHERE kc.parent_object_id=OBJECT_ID(N'dbo.document_version_contents') AND kc.name=N'PK_document_version_contents' AND kc.type='PK' AND ic.key_ordinal>0 ORDER BY ic.key_ordinal")->fetchAll();
        if (array_map(static fn(array $row):string=>(string)$row['column_name'],$versionHashIndex) !== ['document_id','source_content_hash']
            || array_map(static fn(array $row):string=>(string)$row['column_name'],$versionIdentityIndex) !== ['version_id','document_id']
            || array_map(static fn(array $row):string=>(string)$row['column_name'],$contentPrimary) !== ['version_id']
            || array_filter(array_merge($versionHashIndex,$versionIdentityIndex,$contentPrimary),static fn(array $row):bool=>(int)$row['is_unique']!==1 || (int)$row['is_disabled']!==0)!==[]) {
            return false;
        }
        $fkRows = $database->query("SELECT fk.name,fk.is_disabled,fk.is_not_trusted,OBJECT_SCHEMA_NAME(fk.parent_object_id)+'.'+OBJECT_NAME(fk.parent_object_id) parent_name,OBJECT_SCHEMA_NAME(fk.referenced_object_id)+'.'+OBJECT_NAME(fk.referenced_object_id) referenced_name,fc.constraint_column_id,COL_NAME(fc.parent_object_id,fc.parent_column_id) parent_column,COL_NAME(fc.referenced_object_id,fc.referenced_column_id) referenced_column FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fc ON fc.constraint_object_id=fk.object_id WHERE fk.name IN(N'FK_document_version_contents_version',N'FK_documents_current_version') ORDER BY fk.name,fc.constraint_column_id")->fetchAll();
        $expectedFk = [
            ['FK_document_version_contents_version',0,0,'dbo.document_version_contents','dbo.document_versions',1,'version_id','version_id'],
            ['FK_documents_current_version',0,0,'dbo.documents','dbo.document_versions',1,'current_version_id','version_id'],
            ['FK_documents_current_version',0,0,'dbo.documents','dbo.document_versions',2,'document_id','document_id'],
        ];
        $actualFk = array_map(static fn(array $row):array=>[(string)$row['name'],(int)$row['is_disabled'],(int)$row['is_not_trusted'],(string)$row['parent_name'],(string)$row['referenced_name'],(int)$row['constraint_column_id'],(string)$row['parent_column'],(string)$row['referenced_column']],$fkRows);
        if ($actualFk !== $expectedFk) {
            return false;
        }
        $definitions = static function(PDO $database, string $catalog, array $expected): bool {
            $names = implode(',',array_fill(0,count($expected),'?'));
            $statement = $database->prepare("SELECT name,is_disabled,is_not_trusted,definition,OBJECT_SCHEMA_NAME(parent_object_id)+'.'+OBJECT_NAME(parent_object_id) parent_name FROM $catalog WHERE name IN($names)");
            $statement->execute(array_keys($expected));
            $seen = [];
            foreach ($statement->fetchAll() as $row) {
                $name = (string) $row['name']; $definition = (string) ($row['definition'] ?? '');
                if (!isset($expected[$name]) || (int) ($row['is_disabled'] ?? 0) !== 0 || (int) ($row['is_not_trusted'] ?? 0) !== 0 || (string) ($row['parent_name'] ?? '') !== (string) $expected[$name]['parent']) return false;
                foreach ($expected[$name]['tokens'] as $token) if (stripos($definition,$token)===false) return false;
                $seen[$name]=true;
            }
            return count($seen)===count($expected);
        };
        if (!$definitions($database,'sys.check_constraints',[
            'CK_document_version_contents_hash'=>['parent'=>'dbo.document_version_contents','tokens'=>['content_hash']],
            'CK_document_version_contents_size'=>['parent'=>'dbo.document_version_contents','tokens'=>['524288000','DATALENGTH']],
            'CK_documents_version_capture_status'=>['parent'=>'dbo.documents','tokens'=>['pending','ready','failed']],
            'CK_documents_version_capture_error'=>['parent'=>'dbo.documents','tokens'=>['version_capture_error_code']],
            'CK_documents_version_capture_consistency'=>['parent'=>'dbo.documents','tokens'=>['current_version_id','version_capture_status']],
        ])) return false;
        $moduleRows = $database->query("SELECT o.name,SCHEMA_NAME(o.schema_id) schema_name,o.type,tr.is_disabled,CASE WHEN tr.parent_id IS NULL THEN NULL ELSE OBJECT_SCHEMA_NAME(tr.parent_id)+'.'+OBJECT_NAME(tr.parent_id) END parent_name,m.definition FROM sys.objects o LEFT JOIN sys.triggers tr ON tr.object_id=o.object_id LEFT JOIN sys.sql_modules m ON m.object_id=o.object_id WHERE o.name IN(N'TR_document_version_contents_validate',N'TR_document_version_contents_immutable',N'portal_prepare_document_version_capture',N'portal_capture_current_document_version',N'portal_mark_document_version_capture_failed',N'portal_list_document_versions',N'portal_read_document_version_content',N'portal_verify_document_version_contents')")->fetchAll();
        $moduleTokens = [
            'TR_document_version_contents_validate'=>['type'=>'TR','parent'=>'dbo.document_version_contents','tokens'=>['LEFT JOIN','document_versions','HASHBYTES']],
            'TR_document_version_contents_immutable'=>['type'=>'TR','parent'=>'dbo.document_version_contents','tokens'=>['INSTEAD OF UPDATE,DELETE','THROW']],
            'portal_prepare_document_version_capture'=>['type'=>'P','parent'=>null,'tokens'=>['HASHBYTES','version_capture_status']],
            'portal_capture_current_document_version'=>['type'=>'P','parent'=>null,'tokens'=>['UPDLOCK,HOLDLOCK','HASHBYTES','INSERT dbo.document_versions','INSERT dbo.document_version_contents']],
            'portal_mark_document_version_capture_failed'=>['type'=>'P','parent'=>null,'tokens'=>["version_capture_status='failed'"]],
            'portal_list_document_versions'=>['type'=>'P','parent'=>null,'tokens'=>['d.content_hash=v.source_content_hash']],
            'portal_read_document_version_content'=>['type'=>'P','parent'=>null,'tokens'=>['HASHBYTES','content_bytes']],
            'portal_verify_document_version_contents'=>['type'=>'P','parent'=>null,'tokens'=>['FROM dbo.document_versions','LEFT JOIN','missing_rows','invalid_rows']],
        ];
        $seenModules=[];
        foreach($moduleRows as $row){$name=(string)$row['name'];$definition=(string)($row['definition']??'');if(!isset($moduleTokens[$name])||(string)($row['schema_name']??'')!=='dbo'||trim((string)($row['type']??''))!==$moduleTokens[$name]['type']||(($row['parent_name']??null)!==$moduleTokens[$name]['parent'])||(isset($row['is_disabled'])&&(int)$row['is_disabled']!==0))return false;foreach($moduleTokens[$name]['tokens'] as $token)if(stripos($definition,$token)===false)return false;if($name==='portal_capture_current_document_version'&&stripos($definition,'@actor_user_id')!==false)return false;$seenModules[$name]=true;}
        if(count($seenModules)!==count($moduleTokens))return false;
        $principal=(int)$database->query("SELECT DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP')")->fetchColumn();
        $permissions=$database->query("SELECT permission_name,state_desc,major_id,class_desc FROM sys.database_permissions WHERE grantee_principal_id=$principal")->fetchAll();
        $contentId=(int)$database->query("SELECT OBJECT_ID(N'dbo.document_version_contents')")->fetchColumn();$versionsId=(int)$database->query("SELECT OBJECT_ID(N'dbo.document_versions')")->fetchColumn();$denies=[];$versionInsert=false;$exec=[];
        foreach($permissions as $row){if((string)$row['class_desc']!=='OBJECT_OR_COLUMN')continue;if((int)$row['major_id']===$contentId&&$row['state_desc']==='DENY')$denies[]=(string)$row['permission_name'];if((int)$row['major_id']===$versionsId&&$row['state_desc']==='DENY'&&$row['permission_name']==='INSERT')$versionInsert=true;if($row['state_desc']==='GRANT'&&$row['permission_name']==='EXECUTE'&&(int)$row['major_id']>0)$exec[(int)$row['major_id']]=true;}
        sort($denies);$requiredExec=array_map(static fn(string $name):int=>(int)$database->query("SELECT OBJECT_ID(N'dbo.$name')")->fetchColumn(),array_keys(array_filter($moduleTokens,static fn(array $tokens,string $name):bool=>str_starts_with($name,'portal_'),ARRAY_FILTER_USE_BOTH)));
        return $contentId>0&&$versionsId>0&&$denies===['DELETE','INSERT','SELECT','UPDATE']&&$versionInsert&&count($requiredExec)===6&&!in_array(0,$requiredExec,true)&&count(array_unique($requiredExec))===6&&count(array_filter($requiredExec,static fn(int $id):bool=>isset($exec[$id])))===6;
    } catch (Throwable) {
        return false;
    }
}

function portal_open_sqlsrv_stream_database(array $config)
{
    if (!function_exists('sqlsrv_connect') || !function_exists('sqlsrv_send_stream_data')) {
        throw new RuntimeException('SQL Server串流驅動不可用。');
    }
    $connection = sqlsrv_connect((string) $config['db_host'], [
        'Database' => (string) $config['db_name'],
        'UID' => (string) $config['db_username'],
        'PWD' => (string) $config['db_password'],
        'CharacterSet' => 'UTF-8',
        'Encrypt' => (bool) $config['db_encrypt'],
        'TrustServerCertificate' => (bool) $config['db_trust_server_certificate'],
        'LoginTimeout' => 15,
    ]);
    if ($connection === false) {
        throw new RuntimeException('無法建立SQL Server串流連線。');
    }
    return $connection;
}

function portal_capture_current_document_version_content(PDO $database, array $config, array $document): array
{
    if (!portal_document_version_blob_schema_ready($database)) {
        throw new RuntimeException('SQL文件版本內容尚未啟用。');
    }
    $documentId = (int) ($document['document_id'] ?? 0);
    $expectedHash = portal_document_version_hash((string) ($document['content_hash'] ?? ''));
    $expectedSize = (int) ($document['file_size_bytes'] ?? -1);
    if ($documentId <= 0 || $expectedHash === '' || $expectedSize < 0 || $expectedSize > PORTAL_DOCUMENT_HASH_MAX_BYTES) {
        throw new RuntimeException('文件版本內容超過限制或識別資料無效。');
    }
    $path = portal_document_file_path($config, $document);
    $stream = fopen($path, 'rb');
    if (!is_resource($stream) || !flock($stream, LOCK_SH)) {
        if (is_resource($stream)) {
            fclose($stream);
        }
        throw new RuntimeException('無法鎖定目前文件以建立版本。');
    }
    $connection = null;
    try {
        $before = fstat($stream);
        if (!is_array($before) || (int) ($before['size'] ?? -1) !== $expectedSize) {
            throw new RuntimeException('目前文件大小與索引不一致。');
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        $actualHash = hash_final($context);
        if (!hash_equals($expectedHash, $actualHash) || !rewind($stream)) {
            throw new RuntimeException('目前文件內容與索引雜湊不一致。');
        }
        $connection = portal_open_sqlsrv_stream_database($config);
        $params = [
            [&$documentId, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_INT, SQLSRV_SQLTYPE_BIGINT],
            [&$expectedHash, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING('UTF-8'), SQLSRV_SQLTYPE_CHAR('64')],
            [&$stream, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
        ];
        $statement = sqlsrv_prepare(
            $connection,
            'EXEC dbo.portal_capture_current_document_version @document_id=?,@expected_hash=?,@content_bytes=?',
            $params,
            ['SendStreamParamsAtExec' => false, 'QueryTimeout' => 600]
        );
        if ($statement === false || sqlsrv_execute($statement) === false) {
            throw new RuntimeException('SQL文件版本串流命令無法啟動。');
        }
        while (sqlsrv_send_stream_data($statement)) {
        }
        if (sqlsrv_fetch($statement) !== true) {
            throw new RuntimeException('SQL文件版本串流命令沒有回傳結果。');
        }
        $result = [
            'version_id' => (int) sqlsrv_get_field($statement, 0),
            'content_hash' => (string) sqlsrv_get_field($statement, 1),
            'file_size_bytes' => (int) sqlsrv_get_field($statement, 2),
        ];
        sqlsrv_free_stmt($statement);
        $after = fstat($stream);
        if (!is_array($result)
            || (int) ($result['version_id'] ?? 0) <= 0
            || portal_document_version_hash((string) ($result['content_hash'] ?? '')) !== $expectedHash
            || (int) ($result['file_size_bytes'] ?? -1) !== $expectedSize
            || !is_array($after)
            || (int) ($after['size'] ?? -1) !== $expectedSize
        ) {
            throw new RuntimeException('SQL文件版本內容回讀驗證失敗。');
        }
        return $result;
    } finally {
        if ($connection !== null) {
            sqlsrv_close($connection);
        }
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function portal_capture_current_document_version_contents(PDO $database, array $config, ?array $actor = null): array
{
    $summary = ['enabled' => false, 'required' => 0, 'captured' => 0, 'already_ready' => 0, 'ready' => 0, 'failed' => 0, 'complete' => false, 'errors' => []];
    if (!portal_document_version_blob_schema_ready($database)) {
        return $summary;
    }
    $summary['enabled'] = true;
    $database->exec('EXEC dbo.portal_prepare_document_version_capture');
    $documents = $database->query(
        "SELECT document_id,CONVERT(varchar(36),public_id) public_id,relative_path,file_name,extension,
                file_size_bytes,source_modified_at,content_hash,version_capture_status
         FROM dbo.documents WHERE status_code<>'archived' AND content_hash IS NOT NULL ORDER BY document_id"
    )->fetchAll();
    $summary['required'] = count($documents);
    foreach ($documents as $document) {
        if ((string) ($document['version_capture_status'] ?? '') === 'ready') {
            $summary['already_ready']++;
            continue;
        }
        try {
            portal_capture_current_document_version_content($database, $config, $document);
            $summary['captured']++;
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof PDOException ? 'SQL_CAPTURE_FAILED' : 'FILE_INTEGRITY_FAILED';
            try {
                $failed = $database->prepare('EXEC dbo.portal_mark_document_version_capture_failed @document_id=?,@expected_hash=?,@error_code=?');
                $failed->execute([(int) $document['document_id'], (string) $document['content_hash'], $errorCode]);
                while ($failed->nextRowset()) {
                }
            } catch (Throwable) {
                $errorCode = 'STATE_WRITE_FAILED';
            }
            $summary['failed']++;
            $summary['errors'][] = ['document_id' => (int) $document['document_id'], 'error_code' => $errorCode];
        }
    }
    $parity = $database->query(
        "SELECT COUNT(*) required_count,SUM(CASE WHEN version_capture_status='ready' AND current_version_id IS NOT NULL THEN 1 ELSE 0 END) ready_count,
                SUM(CASE WHEN version_capture_status='failed' THEN 1 ELSE 0 END) failed_count
         FROM dbo.documents WHERE status_code<>'archived' AND content_hash IS NOT NULL"
    )->fetch();
    $summary['ready'] = (int) ($parity['ready_count'] ?? 0);
    $summary['failed'] = max($summary['failed'], (int) ($parity['failed_count'] ?? 0));
    $summary['complete'] = (int) ($parity['required_count'] ?? -1) === $summary['ready'] && $summary['failed'] === 0;
    return $summary;
}

function portal_get_database_document_versions(PDO $database, array $document): array
{
    if (!portal_document_version_blob_schema_ready($database) || (int) ($document['document_id'] ?? 0) <= 0) {
        return [];
    }
    $statement = $database->prepare('EXEC dbo.portal_list_document_versions @document_id=?');
    $statement->execute([(int) $document['document_id']]);
    $versions = [];
    foreach ($statement->fetchAll() as $row) {
        $versions[] = [
            'version_id' => (int) $row['version_id'],
            'version_number' => (int) $row['version_number'],
            'content_hash' => (string) $row['content_hash'],
            'file_size_bytes' => (int) $row['file_size_bytes'],
            'source_modified_at' => (string) $row['source_modified_at'],
            'archived_utc' => (string) ($row['stored_at'] ?? $row['created_at']),
            'archived_reason' => 'sql-version-content',
            'artifact_path' => null,
            'has_content' => (int) $row['has_content'] === 1,
            'is_current' => (int) $row['is_current'] === 1,
        ];
    }
    while ($statement->nextRowset()) {
    }
    return $versions;
}

function portal_stream_database_document_version(PDO $database, array $config, array $document, string $contentHash, array $admin): bool
{
    $contentHash = portal_document_version_hash($contentHash);
    $documentId = (int) ($document['document_id'] ?? 0);
    $sessionRoot = realpath((string) ($config['session_save_path'] ?? ''));
    if ($contentHash === '' || $documentId <= 0 || $sessionRoot === false || !is_dir($sessionRoot) || is_link($sessionRoot) || !portal_document_version_blob_schema_ready($database)) {
        return false;
    }
    $tempPath = tempnam($sessionRoot, 'version-download-');
    if ($tempPath === false || is_link($tempPath) || !str_starts_with(strtolower($tempPath), strtolower(rtrim($sessionRoot, '\\/') . DIRECTORY_SEPARATOR))) {
        if (is_string($tempPath) && is_file($tempPath)) {
            @unlink($tempPath);
        }
        return false;
    }
    $output = fopen($tempPath, 'w+b');
    if (!is_resource($output)) {
        @unlink($tempPath);
        return false;
    }
    $streamConnection = null;
    try {
        $streamConnection = portal_open_sqlsrv_stream_database($config);
        $statement = sqlsrv_query(
            $streamConnection,
            'EXEC dbo.portal_read_document_version_content @document_id=?,@content_hash=?',
            [$documentId, $contentHash],
            ['QueryTimeout' => 600]
        );
        if ($statement === false || sqlsrv_fetch($statement) !== true) {
            return false;
        }
        $size = (int) sqlsrv_get_field($statement, 0);
        $storedHash = (string) sqlsrv_get_field($statement, 1);
        $sqlHash = (string) sqlsrv_get_field($statement, 2);
        $stream = sqlsrv_get_field($statement, 3, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY));
        if (!is_resource($stream)) {
            return false;
        }
        $hashContext = hash_init('sha256');
        $written = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                return false;
            }
            if ($chunk === '') {
                continue;
            }
            $length = strlen($chunk);
            if (fwrite($output, $chunk) !== $length) {
                return false;
            }
            hash_update($hashContext, $chunk);
            $written += $length;
            if ($written > PORTAL_DOCUMENT_HASH_MAX_BYTES) {
                return false;
            }
        }
        fclose($stream);
        sqlsrv_free_stmt($statement);
        $actualHash = hash_final($hashContext);
        if ($written !== (int) $size
            || !hash_equals($contentHash, strtolower((string) $storedHash))
            || !hash_equals($contentHash, strtolower((string) $sqlHash))
            || !hash_equals($contentHash, $actualHash)
            || !rewind($output)
        ) {
            return false;
        }
        $types = [
            'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'md' => 'text/markdown; charset=UTF-8', 'txt' => 'text/plain; charset=UTF-8', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        ];
        $extension = strtolower((string) ($document['extension'] ?? ''));
        header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) $written);
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode((string) ($document['file_name'] ?? 'document')));
        header('X-Content-Type-Options: nosniff');
        $sent = fpassthru($output);
        $result = $sent === $written ? 'accepted' : 'rejected';
        portal_document_audit($database, 'document_version_download', $result, (int) $admin['user_id'], $documentId, 'sql-hash=' . substr($contentHash, 0, 12));
        exit;
    } finally {
        if (isset($stream) && is_resource($stream)) {
            fclose($stream);
        }
        if ($streamConnection !== null) {
            sqlsrv_close($streamConnection);
        }
        fclose($output);
        @unlink($tempPath);
    }
}

function portal_get_document_version_numbers(PDO $database, int $documentId): array
{
    if ($documentId <= 0) {
        return [];
    }
    $statement = $database->prepare(
        'SELECT LOWER(source_content_hash) AS source_content_hash, version_number
         FROM dbo.document_versions WHERE document_id = ?'
    );
    $statement->execute([$documentId]);
    $versions = [];
    foreach ($statement->fetchAll() as $row) {
        $hash = portal_document_version_hash((string) ($row['source_content_hash'] ?? ''));
        if ($hash !== '') {
            $versions[$hash] = (int) $row['version_number'];
        }
    }
    return $versions;
}

function portal_render_document_version_compare(array $document, array $target, array $user, ?array $activeOperation, bool $restoreEnabled = true, string $scope = 'document'): string
{
    if (!portal_is_admin($user)) {
        return '';
    }
    $currentHash = portal_document_version_hash((string) ($document['content_hash'] ?? ''));
    $targetHash = portal_document_version_hash((string) ($target['content_hash'] ?? ''));
    if ($currentHash === '' || $targetHash === '' || ($target['is_current'] ?? true) === true) {
        throw new RuntimeException('版本比較資料無效。');
    }
    $status = (string) ($document['status_code'] ?? 'draft');
    $imageScope = $scope === 'image-source';
    $impact = $status === 'published'
        ? '目前文件為已發布；受控還原完成後維持已發布，不略過既有權限與稽核。'
        : '目前文件為' . ($status === 'archived' ? '封存' : '草稿') . '；還原後維持原狀態，不會自動發布。';
    $activeNotice = '';
    $submit = '';
    $modeNotice = $imageScope
        ? '<section class="drive-sync-warning"><h2>圖片還原暫時停用</h2><p>安全驗證發現還原命令仍需補齊可信文件身分綁定、簽章與來源鎖；目前不會送出還原命令。正式圖片、封存版本與稽核證據均保留，仍可下載舊版評估。</p></section>'
        : '<section class="drive-sync-warning"><h2>Phase 1 只讀</h2><p>目前正式文件由受保護直接文件庫提供；版本還原尚未接入此權威根，因此不會送出還原命令。仍可下載舊版供管理者人工評估。</p></section>';
    if ($restoreEnabled) {
        $submit = '<form method="post" action="' . portal_url('document_version_restore') . '" class="version-confirm-form" data-confirm="確定送出版本還原？系統會先封存目前版本。">'
            . portal_csrf_field()
            . '<input type="hidden" name="id" value="' . portal_e((string) $document['public_id']) . '">'
            . '<input type="hidden" name="hash" value="' . portal_e($targetHash) . '">'
            . '<button type="submit" class="danger-button">確認並排入還原</button></form>';
        $modeNotice = $imageScope
            ? '<section class="drive-sync-warning"><h2>網站正式圖片還原</h2><p>系統會先封存目前網站正式圖片，再原子替換為選定版本；完成後等待網站索引核對內容雜湊，不會自動改變草稿或發布狀態。</p></section>'
            : '<section class="drive-sync-warning"><h2>Google Drive 同步提示</h2><p>還原完成先代表本機 Google Drive 來源已替換；網站會另外顯示 indexed 與 preview-ready。雲端上傳狀態無法由本機檔案 API 證明，將保持 pending-confirmation，請至 Google Drive 確認。</p></section>';
    }
    if (is_array($activeOperation)) {
        $operationId = portal_valid_public_id((string) ($activeOperation['operation_id'] ?? ''));
        $activeNotice = '<div class="notice warning"><strong>已有進行中的版本操作</strong><p>目前狀態：<code>'
            . portal_e((string) ($activeOperation['status_code'] ?? 'queued')) . '</code>。請等待完成，避免重複提交。</p>'
            . ($operationId !== '' ? '<a class="secondary-button compact-button" href="' . portal_e(portal_url('document_version_operation', ['id' => $operationId])) . '">查看操作狀態</a>' : '')
            . '</div>';
        $submit = '';
    }
    $versionNumber = (int) ($target['version_number'] ?? 0);
    return '<section class="panel version-compare-panel"><p class="eyebrow">RESTORE COMPARISON</p><h1>版本比較與還原確認</h1>'
        . $activeNotice
        . '<div class="version-compare-grid">'
        . '<article class="version-compare-card current"><h2>目前版本</h2><dl>'
        . '<div><dt>內容雜湊</dt><dd><code>' . portal_e($currentHash) . '</code></dd></div>'
        . '<div><dt>大小</dt><dd>' . portal_e(portal_format_file_size((int) ($document['file_size_bytes'] ?? 0))) . '</dd></div>'
        . '<div><dt>修改時間</dt><dd>' . portal_e(portal_format_datetime((string) ($document['source_modified_at'] ?? ''))) . '</dd></div>'
        . '<div><dt>發布狀態</dt><dd>' . portal_e($status) . '</dd></div></dl></article>'
        . '<article class="version-compare-card target"><h2>目標版本' . ($versionNumber > 0 ? ' v' . $versionNumber : '') . '</h2><dl>'
        . '<div><dt>內容雜湊</dt><dd><code>' . portal_e($targetHash) . '</code></dd></div>'
        . '<div><dt>大小</dt><dd>' . portal_e(portal_format_file_size((int) ($target['file_size_bytes'] ?? 0))) . '</dd></div>'
        . '<div><dt>來源時間</dt><dd>' . portal_e(portal_format_datetime((string) ($target['source_modified_at'] ?? ''))) . '</dd></div>'
        . '<div><dt>封存時間</dt><dd>' . portal_e(portal_format_datetime((string) ($target['archived_utc'] ?? ''))) . '</dd></div></dl></article></div>'
        . '<section class="version-impact"><h2>發布狀態影響</h2><p>' . portal_e($impact) . '</p></section>'
        . $modeNotice
        . '<div class="form-actions"><a class="secondary-button" href="' . portal_e(portal_url($imageScope ? 'image_review' : 'view', ['id' => (string) $document['public_id']])) . '">取消</a>' . $submit . '</div></section>';
}

function portal_render_document_version_operation_page(array $document, array $operation, array $events): string
{
    $operationId = portal_valid_public_id((string) ($operation['operation_id'] ?? ''));
    $status = (string) ($operation['status_code'] ?? 'failed');
    $catalog = portal_document_version_operation_status_catalog();
    if ($operationId === '' || !isset($catalog[$status])) {
        throw new RuntimeException('版本操作資料無效。');
    }
    $stages = ['queued','running','local-restored','indexed'];
    if (strtolower((string) ($document['extension'] ?? '')) === 'pptx') {
        $stages[] = 'preview-ready';
    } else {
        $stages[] = 'completed';
    }
    $terminalError = in_array($status, ['conflict','failed','cancelled'], true);
    $currentIndex = array_search($status, $stages, true);
    $timeline = '';
    foreach ($stages as $index => $stage) {
        $class = $terminalError ? '' : (($currentIndex !== false && $index < $currentIndex) ? ' done' : (($currentIndex === $index) ? ' current' : ''));
        $timeline .= '<li class="operation-stage' . $class . '"><span></span><div><strong>' . portal_e($stage) . '</strong><small>' . portal_e((string) $catalog[$stage]['description']) . '</small></div></li>';
    }
    if ($terminalError) {
        $timeline .= '<li class="operation-stage current error"><span></span><div><strong>' . portal_e($status) . '</strong><small>' . portal_e((string) $catalog[$status]['description']) . '</small></div></li>';
    }
    $eventRows = '';
    foreach ($events as $event) {
        $eventRows .= '<tr><td>' . portal_e(portal_format_datetime((string) ($event['occurred_at'] ?? ''))) . '</td>'
            . '<td><code>' . portal_e((string) ($event['status_code'] ?? '')) . '</code></td>'
            . '<td>' . portal_e((string) ($event['event_code'] ?? '')) . '</td></tr>';
    }
    if ($eventRows === '') {
        $eventRows = '<tr><td colspan="3" class="muted">尚無事件紀錄。</td></tr>';
    }
    $error = (string) ($operation['error_code'] ?? '');
    $errorPanel = $error === '' ? '' : '<section class="operation-error"><h2>錯誤資訊</h2><p><code>' . portal_e($error) . '</code></p><p>' . portal_e((string) $catalog[$status]['description']) . '</p></section>';
    $statusUrl = portal_url('document_version_operation_status', ['id' => $operationId]);
    return '<section class="panel operation-page" data-operation-status-url="' . portal_e($statusUrl) . '" data-operation-status="' . portal_e($status) . '">'
        . '<p class="eyebrow">VERSION OPERATION</p><div class="operation-heading"><div><h1>版本操作結果</h1><p>' . portal_e((string) ($document['title'] ?? $document['file_name'] ?? '文件')) . '</p></div>'
        . '<span class="operation-status status-' . portal_e($status) . '">' . portal_e($status) . '</span></div>'
        . '<p class="operation-id">Operation ID：<code>' . portal_e($operationId) . '</code></p>'
        . '<ol class="operation-timeline">' . $timeline . '</ol>'
        . '<div class="operation-summary-grid">'
        . '<div><span>預期目前雜湊</span><code>' . portal_e((string) ($operation['expected_current_hash'] ?? '')) . '</code></div>'
        . '<div><span>目標雜湊</span><code>' . portal_e((string) ($operation['target_content_hash'] ?? '')) . '</code></div>'
        . '<div><span>實際內容雜湊</span><code>' . portal_e((string) ($operation['actual_content_hash'] ?? '尚未建立')) . '</code></div>'
        . '<div><span>Google Drive</span><code>' . portal_e((string) ($operation['drive_sync_status'] ?? 'pending-confirmation')) . '</code></div></div>'
        . '<div class="drive-sync-warning"><strong>Google Drive 同步提示</strong><p>本機來源、網站索引與預覽可由系統驗證；Google Drive 雲端同步仍需人工確認，因此不會自動標示為已完成。</p></div>'
        . $errorPanel
        . '<section class="operation-events"><h2>操作事件與錯誤紀錄</h2><div class="table-wrap"><table><thead><tr><th>時間</th><th>狀態</th><th>事件</th></tr></thead><tbody>' . $eventRows . '</tbody></table></div></section>'
        . '<div class="form-actions"><a class="secondary-button" href="' . portal_e(portal_url('view', ['id' => (string) $document['public_id']])) . '">返回文件</a></div></section>';
}

function portal_merge_document_versions(array $databaseVersions, array $archiveVersions): array
{
    $merged = [];
    foreach (array_merge($databaseVersions, $archiveVersions) as $version) {
        $hash = portal_document_version_hash((string) ($version['content_hash'] ?? ''));
        if ($hash === '') {
            continue;
        }
        if (!isset($merged[$hash])) {
            $version['has_content'] = (bool) ($version['has_content'] ?? ((string) ($version['artifact_path'] ?? '') !== '' || (bool) ($version['is_current'] ?? false)));
            $merged[$hash] = $version;
            continue;
        }
        if ((string) ($version['artifact_path'] ?? '') !== '') {
            $merged[$hash]['artifact_path'] = (string) $version['artifact_path'];
            $merged[$hash]['has_content'] = true;
        }
        $merged[$hash]['is_current'] = (bool) ($merged[$hash]['is_current'] ?? false) || (bool) ($version['is_current'] ?? false);
    }
    $versions = array_values($merged);
    usort($versions, static function(array $left,array $right):int {
        $current = ((int) ($right['is_current'] ?? false)) <=> ((int) ($left['is_current'] ?? false));
        return $current !== 0 ? $current : ((int) ($right['version_number'] ?? 0) <=> (int) ($left['version_number'] ?? 0));
    });
    return $versions;
}

function portal_render_document_version_panel(array $config, array $document, array $user, array $knownVersionNumbers = [], array $operations = [], array $databaseVersions = []): string
{
    if (!portal_is_admin($user) || ($config['document_version_enabled'] ?? false) !== true) {
        return '';
    }
    $imageScope = (string) ($config['document_version_scope'] ?? 'document') === 'image-source';
    $panelTitle = $imageScope ? '圖片版本與還原' : '版本管理';
    $versions = portal_merge_document_versions(
        $databaseVersions,
        portal_list_document_versions($config, $document, $knownVersionNumbers)
    );
    if ($versions === []) {
        return '<section class="panel version-panel"><h2>' . portal_e($panelTitle) . '</h2><p class="muted">目前尚無可辨識的文件版本。</p></section>';
    }
    $restoreEnabled = ($config['document_restore_enabled'] ?? true) === true;
    $publicId = (string) $document['public_id'];
    $rows = '';
    foreach ($versions as $version) {
        $hash = (string) $version['content_hash'];
        $number = (int) $version['version_number'];
        $label = $number > 0 ? 'v' . $number : '版本';
        $current = (bool) $version['is_current'];
        $date = $current ? (string) $version['source_modified_at'] : (string) $version['archived_utc'];
        $actions = '<span class="version-current-badge">目前版本</span>';
        if (!$current) {
            if (($version['has_content'] ?? false) !== true) {
                $actions = '<span class="version-current-badge">內容尚未回填</span>';
            } else {
                $fileUrl = portal_url('document_version_file', [
                    'id' => $publicId,
                    'hash' => $hash,
                ]);
                $compareUrl = portal_url('document_version_compare', [
                    'id' => $publicId,
                    'hash' => $hash,
                ]);
                $actions = '<a class="secondary-button compact-button" href="' . portal_e($fileUrl) . '">下載舊版</a>';
                if ($restoreEnabled) {
                    $actions .= '<a class="danger-button compact-button" href="' . portal_e($compareUrl) . '">比較並還原</a>';
                } else {
                    $actions .= '<span class="version-current-badge">Phase 1 只讀</span>';
                }
            }
        }
        $rows .= '<tr' . ($current ? ' class="current-version-row"' : '') . '>'
            . '<td><strong>' . portal_e($label) . '</strong></td>'
            . '<td><code>' . portal_e(substr($hash, 0, 12)) . '</code></td>'
            . '<td>' . portal_e(portal_format_file_size((int) $version['file_size_bytes'])) . '</td>'
            . '<td>' . portal_e(portal_format_datetime($date)) . '</td>'
            . '<td class="version-actions">' . $actions . '</td></tr>';
    }
    $operationRows = '';
    foreach ($operations as $operation) {
        $operationId = portal_valid_public_id((string) ($operation['operation_id'] ?? ''));
        if ($operationId === '') {
            continue;
        }
        $status = (string) ($operation['status_code'] ?? 'failed');
        $operationRows .= '<tr><td>' . portal_e(portal_format_datetime((string) ($operation['requested_at'] ?? ''))) . '</td>'
            . '<td><span class="operation-status status-' . portal_e($status) . '">' . portal_e($status) . '</span></td>'
            . '<td><code>' . portal_e(substr((string) ($operation['target_content_hash'] ?? ''), 0, 12)) . '</code></td>'
            . '<td><code>' . portal_e((string) ($operation['drive_sync_status'] ?? 'pending-confirmation')) . '</code></td>'
            . '<td>' . ((string) ($operation['error_code'] ?? '') === '' ? '—' : '<code>' . portal_e((string) $operation['error_code']) . '</code>') . '</td>'
            . '<td><a class="secondary-button compact-button" href="' . portal_e(portal_url('document_version_operation', ['id' => $operationId])) . '">查看結果</a></td></tr>';
    }
    if ($operationRows === '') {
        $operationRows = '<tr><td colspan="6" class="muted">尚無版本還原操作。</td></tr>';
    }
    $history = '<section class="operation-history"><div class="section-heading"><div><p class="eyebrow">OPERATION LOG</p><h3>操作結果及錯誤紀錄</h3></div></div>'
        . '<div class="table-wrap"><table><thead><tr><th>提出時間</th><th>狀態</th><th>目標雜湊</th><th>Google Drive</th><th>錯誤</th><th></th></tr></thead><tbody>'
        . $operationRows . '</tbody></table></div></section>';
    $panelNote = $imageScope
        ? ($restoreEnabled
            ? '正式圖片路徑固定不變。每次套用或還原前，系統都會先封存當時版本；「比較並還原」僅更新網站權威圖片，完成後重新確認網站索引雜湊。'
            : '圖片版本歷史仍可下載檢查；目前未配置簽章命令，因此不會建立還原寫入命令。')
        : ($restoreEnabled
            ? '主檔名固定不變。每次發布或還原前，系統會先將當時版本封存；「比較並還原」會先顯示完整雜湊、大小、時間、發布狀態與 Google Drive 影響。'
            : 'Phase 1採受保護直接文件庫且網站只讀；歷史版本仍可下載檢查，但版本還原不會建立寫入命令。');
    return '<section class="panel version-panel"><div class="section-heading"><div><p class="eyebrow">VERSION HISTORY</p><h2>' . portal_e($panelTitle) . '</h2></div></div>'
        . '<p class="muted">' . portal_e($panelNote) . '</p>'
        . '<div class="table-wrap"><table class="version-table"><thead><tr><th>版本</th><th>內容雜湊</th><th>大小</th><th>時間</th><th>操作</th></tr></thead><tbody>'
        . $rows . '</tbody></table></div>' . $history . '</section>';
}
