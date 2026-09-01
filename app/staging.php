<?php
declare(strict_types=1);

const PORTAL_STAGING_MAX_BYTES = 524288000;
const PORTAL_STAGING_CHUNK_BYTES = 1048576;

function portal_staging_extension_allowed(string $extension): bool
{
    return in_array(strtolower($extension), ['pptx', 'pdf', 'docx', 'xlsx', 'md', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true);
}

function portal_staging_file_name(string $value): string
{
    $value = trim($value);
    $base = basename(str_replace('\\', '/', $value));
    $extension = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    if ($value === '' || $base !== $value || str_contains($value, "\0")
        || str_starts_with($base, '.') || str_starts_with($base, '~$')
        || mb_strlen($base, 'UTF-8') > 240 || !portal_staging_extension_allowed($extension)
        || preg_match('/[\x00-\x1F<>:"\\|?*]/u', $base) === 1) {
        throw new RuntimeException('暫存文件檔名或格式不正確。');
    }
    return $base;
}

function portal_staging_upload_size(int $bytes): int
{
    if ($bytes < 1 || $bytes > PORTAL_STAGING_MAX_BYTES) {
        throw new RuntimeException('暫存文件大小必須介於 1 byte 到 500 MB。');
    }
    return $bytes;
}

function portal_staging_expected_chunks(int $bytes, int $chunkBytes = PORTAL_STAGING_CHUNK_BYTES): int
{
    portal_staging_upload_size($bytes);
    if ($chunkBytes < 65536 || $chunkBytes > 2097152) {
        throw new RuntimeException('暫存分段大小不正確。');
    }
    return (int) ceil($bytes / $chunkBytes);
}

function portal_staging_normalize_relative_path(string $value): string
{
    if ($value === '' || str_contains($value, "\0") || str_starts_with($value, '/')
        || str_starts_with($value, '\\') || preg_match('/\A[A-Za-z]:/', $value) === 1) {
        throw new RuntimeException('正式文件路徑不正確。');
    }
    $segments = explode('/', str_replace('\\', '/', $value));
    if ($segments === []) {
        throw new RuntimeException('正式文件路徑不正確。');
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')
            || preg_match('/[\x00-\x1F<>:"|?*]/u', $segment) === 1) {
            throw new RuntimeException('正式文件路徑不正確。');
        }
    }
    $normal = implode('/', $segments);
    if (mb_strlen($normal, 'UTF-8') > 500 || !portal_staging_extension_allowed((string) pathinfo($normal, PATHINFO_EXTENSION))) {
        throw new RuntimeException('正式文件路徑不正確。');
    }
    return $normal;
}

function portal_staging_target_relative_path(string $fileName): string
{
    return portal_staging_normalize_relative_path('網站文件/' . portal_staging_file_name($fileName));
}

function portal_staging_root(array $config): string
{
    $root = rtrim((string) ($config['preupload_preview_root'] ?? ''), '\\/');
    if ($root === '' || !is_dir($root) || is_link($root) || !portal_path_is_outside_application_root($root)) {
        throw new RuntimeException('暫存預覽空間尚未啟用。');
    }
    $resolved = realpath($root);
    if ($resolved === false || is_link($resolved)) {
        throw new RuntimeException('暫存預覽空間尚未啟用。');
    }
    return rtrim($resolved, '\\/');
}

function portal_staging_workspace_path(array $config, string $publicId): string
{
    $publicId = portal_valid_public_id($publicId);
    if ($publicId === '') {
        throw new RuntimeException('暫存工作區識別碼不正確。');
    }
    return portal_staging_root($config) . DIRECTORY_SEPARATOR . strtolower($publicId);
}

function portal_staging_schema_available(PDO $database): bool
{
    return (int) $database->query("SELECT CASE WHEN OBJECT_ID(N'dbo.staged_document_revisions', N'U') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn() === 1;
}

function portal_staging_create(PDO $database, array $config, array $user, array $input): array
{
    if (!portal_can_edit_documents($user) || !portal_staging_schema_available($database)) {
        throw new RuntimeException('暫存預覽功能尚未啟用。');
    }
    $fileName = portal_staging_file_name((string) ($input['file_name'] ?? ''));
    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    $size = portal_staging_upload_size((int) ($input['file_size_bytes'] ?? 0));
    $chunks = portal_staging_expected_chunks($size);
    $targetDocumentId = null;
    $changeRequestId = null;
    $expectedHash = null;
    $targetRelativePath = portal_staging_target_relative_path($fileName);
    $targetPublicId = portal_valid_public_id((string) ($input['target_document_id'] ?? ''));
    $changeRequestPublicId = portal_valid_public_id((string) ($input['change_request_id'] ?? ''));
    if ($changeRequestPublicId !== '') {
        $requestStatement = $database->prepare(
            "SELECT cr.change_request_id, cr.created_by_user_id,
                    d.document_id, CONVERT(varchar(36), d.public_id) AS document_public_id,
                    d.relative_path, d.content_hash, d.extension
             FROM dbo.presentation_change_requests cr
             INNER JOIN dbo.document_versions dv ON dv.version_id = cr.document_version_id
             INNER JOIN dbo.documents d ON d.document_id = dv.document_id
             WHERE cr.public_id = CONVERT(uniqueidentifier, ?)
               AND cr.status_code IN ('ready','submitted')
               AND d.status_code <> 'archived' AND d.relative_path LIKE N'網站文件/%'"
        );
        $requestStatement->execute([$changeRequestPublicId]);
        $target = $requestStatement->fetch();
        if ($target === false || (!portal_is_admin($user) && (int) $target['created_by_user_id'] !== (int) $user['user_id'])
            || ($targetPublicId !== '' && $targetPublicId !== strtolower((string) $target['document_public_id']))
            || strtolower((string) $target['extension']) !== $extension) {
            throw new RuntimeException('修改需求不存在、沒有權限或輸出副檔名不同。');
        }
        $changeRequestId = (int) $target['change_request_id'];
        $targetPublicId = strtolower((string) $target['document_public_id']);
        $targetDocumentId = (int) $target['document_id'];
        $targetRelativePath = portal_staging_normalize_relative_path((string) $target['relative_path']);
        $storedHash = strtolower((string) ($target['content_hash'] ?? ''));
        $expectedHash = preg_match('/\A[0-9a-f]{64}\z/', $storedHash) === 1 ? $storedHash : null;
    } elseif ($targetPublicId !== '') {
        $statement = $database->prepare(
            "SELECT document_id, relative_path, content_hash, extension FROM dbo.documents
             WHERE public_id = CONVERT(uniqueidentifier, ?) AND status_code <> 'archived'
               AND relative_path LIKE N'網站文件/%'"
        );
        $statement->execute([$targetPublicId]);
        $target = $statement->fetch();
        if ($target === false || strtolower((string) $target['extension']) !== $extension) {
            throw new RuntimeException('要更新的正式文件不存在或副檔名不同。');
        }
        $targetDocumentId = (int) $target['document_id'];
        $targetRelativePath = portal_staging_normalize_relative_path((string) $target['relative_path']);
        $storedHash = strtolower((string) ($target['content_hash'] ?? ''));
        $expectedHash = preg_match('/\A[0-9a-f]{64}\z/', $storedHash) === 1 ? $storedHash : null;
    }
    $insert = $database->prepare(
        "INSERT INTO dbo.staged_document_revisions
            (target_document_id, change_request_id, created_by_user_id, file_name, extension, target_relative_path,
             expected_source_hash, declared_size_bytes, chunk_size_bytes, total_chunks)
         OUTPUT CONVERT(varchar(36), INSERTED.public_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insert->execute([
        $targetDocumentId, $changeRequestId, (int) $user['user_id'], $fileName, $extension, $targetRelativePath,
        $expectedHash, $size, PORTAL_STAGING_CHUNK_BYTES, $chunks,
    ]);
    $publicId = strtolower((string) $insert->fetchColumn());
    $workspace = portal_staging_workspace_path($config, $publicId);
    if (!mkdir($workspace . DIRECTORY_SEPARATOR . 'chunks', 0770, true) && !is_dir($workspace . DIRECTORY_SEPARATOR . 'chunks')) {
        throw new RuntimeException('無法建立暫存工作區。');
    }
    portal_document_audit($database, 'staging_workspace_create', 'accepted', (int) $user['user_id'], $targetDocumentId, 'workspace=' . $publicId);
    return portal_staging_get($database, $user, $publicId, true) ?? throw new RuntimeException('無法建立暫存工作區。');
}

function portal_staging_get(PDO $database, array $user, string $publicId, bool $forUpdate = false): ?array
{
    $publicId = portal_valid_public_id($publicId);
    if ($publicId === '') {
        return null;
    }
    $lock = $forUpdate ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
    $statement = $database->prepare(
        "SELECT staged_revision_id, CONVERT(varchar(36), public_id) AS public_id, target_document_id, change_request_id,
                created_by_user_id, confirmed_by_user_id, file_name, extension, target_relative_path,
                expected_source_hash, declared_size_bytes, chunk_size_bytes, total_chunks,
                staged_content_hash, status_code, preview_kind, preview_relative_path,
                preview_page_count, error_code, review_note, drive_sync_status, drive_file_id,
                drive_verified_at, confirmed_at, expires_at, created_at, updated_at
         FROM dbo.staged_document_revisions{$lock}
         WHERE public_id = CONVERT(uniqueidentifier, ?)"
    );
    $statement->execute([$publicId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }
    if (!portal_is_admin($user) && (int) $row['created_by_user_id'] !== (int) ($user['user_id'] ?? 0)) {
        return null;
    }
    return $row;
}

function portal_staging_remove_workspace(array $config, string $publicId): void
{
    $path = portal_staging_workspace_path($config, $publicId);
    if (!is_dir($path) || is_link($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isLink() || !$item->isDir()) {
            @unlink($itemPath);
        } else {
            @rmdir($itemPath);
        }
    }
    @rmdir($path);
}

function portal_staging_cleanup_expired(PDO $database, array $config, int $limit = 20): int
{
    $limit = max(1, min(100, $limit));
    $rows = $database->query(
        "SELECT TOP ({$limit}) CONVERT(varchar(36), public_id) AS public_id
         FROM dbo.staged_document_revisions
         WHERE expires_at < SYSUTCDATETIME()
           AND status_code IN ('uploading','uploaded','preview_ready','changes_requested','confirmed','failed','conflict','cancelled')
         ORDER BY expires_at"
    )->fetchAll();
    $update = $database->prepare(
        "UPDATE dbo.staged_document_revisions SET status_code = 'expired', updated_at = SYSUTCDATETIME()
         WHERE public_id = CONVERT(uniqueidentifier, ?) AND expires_at < SYSUTCDATETIME()
           AND status_code IN ('uploading','uploaded','preview_ready','changes_requested','confirmed','failed','conflict','cancelled')"
    );
    $cleaned = 0;
    foreach ($rows as $row) {
        $id = portal_valid_public_id((string) $row['public_id']);
        if ($id === '') continue;
        $update->execute([$id]);
        if ($update->rowCount() === 1) {
            portal_staging_remove_workspace($config, $id);
            $cleaned++;
        }
    }
    return $cleaned;
}

function portal_staging_list(PDO $database, array $user): array
{
    $where = portal_is_admin($user) ? '' : 'WHERE s.created_by_user_id = ?';
    $statement = $database->prepare(
        "SELECT TOP (100) CONVERT(varchar(36), s.public_id) AS public_id, s.file_name, s.extension,
                s.target_relative_path, s.declared_size_bytes, s.status_code, s.preview_kind,
                s.drive_sync_status, s.created_by_user_id, u.display_name AS creator_name,
                s.created_at, s.updated_at
         FROM dbo.staged_document_revisions s
         INNER JOIN dbo.auth_users u ON u.user_id = s.created_by_user_id
         {$where}
         ORDER BY s.updated_at DESC"
    );
    $statement->execute(portal_is_admin($user) ? [] : [(int) $user['user_id']]);
    return $statement->fetchAll();
}

function portal_staging_write_chunk(PDO $database, array $config, array $user, string $publicId, int $index, string $body): array
{
    $database->beginTransaction();
    try {
        $workspace = portal_staging_get($database, $user, $publicId, true);
        if ($workspace === null || (string) $workspace['status_code'] !== 'uploading') {
            throw new RuntimeException('暫存工作區目前不能接收檔案。');
        }
        $total = (int) $workspace['total_chunks'];
        $chunkBytes = (int) $workspace['chunk_size_bytes'];
        if ($index < 0 || $index >= $total || $body === '' || strlen($body) > $chunkBytes) {
            throw new RuntimeException('上傳分段不正確。');
        }
        $expected = $index === $total - 1
            ? (int) $workspace['declared_size_bytes'] - ($index * $chunkBytes)
            : $chunkBytes;
        if (strlen($body) !== $expected) {
            throw new RuntimeException('上傳分段大小不正確。');
        }
        $path = portal_staging_workspace_path($config, $publicId) . DIRECTORY_SEPARATOR . 'chunks'
            . DIRECTORY_SEPARATOR . sprintf('%06d.part', $index);
        if (is_link($path)) {
            throw new RuntimeException('上傳分段路徑不安全。');
        }
        $written = file_put_contents($path, $body, LOCK_EX);
        if ($written !== strlen($body)) {
            throw new RuntimeException('無法寫入上傳分段。');
        }
        $update = $database->prepare('UPDATE dbo.staged_document_revisions SET updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ?');
        $update->execute([(int) $workspace['staged_revision_id']]);
        $database->commit();
        return ['index' => $index, 'bytes' => $written];
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function portal_staging_utf8_incomplete_suffix_length(string $data): int
{
    $length = strlen($data);
    if ($length === 0) return 0;
    $continuations = 0;
    for ($index = $length - 1; $index >= 0 && $continuations < 3; $index--) {
        $byte = ord($data[$index]);
        if (($byte & 0xC0) !== 0x80) break;
        $continuations++;
    }
    $leadIndex = $length - $continuations - 1;
    if ($leadIndex < 0) return 0;
    $lead = ord($data[$leadIndex]);
    $expected = $lead >= 0xC2 && $lead <= 0xDF ? 2
        : ($lead >= 0xE0 && $lead <= 0xEF ? 3
        : ($lead >= 0xF0 && $lead <= 0xF4 ? 4 : 1));
    $present = $continuations + 1;
    return $expected > $present ? $present : 0;
}

function portal_staging_validate_utf8_text(string $path): void
{
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > PORTAL_STAGING_MAX_BYTES) {
        throw new RuntimeException('文字文件大小不正確。');
    }
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('文字文件無法讀取。');
    $carry = '';
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 4194304);
            if ($chunk === false) throw new RuntimeException('文字文件無法讀取。');
            $data = $carry . $chunk;
            if (str_contains($data, "\0")) throw new RuntimeException('文字文件不可包含 NUL。');
            $suffixLength = feof($handle) ? 0 : portal_staging_utf8_incomplete_suffix_length($data);
            $complete = $suffixLength === 0 ? $data : substr($data, 0, -$suffixLength);
            $carry = $suffixLength === 0 ? '' : substr($data, -$suffixLength);
            if ($complete !== '' && !mb_check_encoding($complete, 'UTF-8')) {
                throw new RuntimeException('文字文件必須是 UTF-8 純文字。');
            }
        }
        if ($carry !== '' && !mb_check_encoding($carry, 'UTF-8')) {
            throw new RuntimeException('文字文件必須是完整 UTF-8 純文字。');
        }
    } finally {
        fclose($handle);
    }
}

function portal_staging_validate_artifact(string $path, string $extension): void
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('暫存文件無法讀取。');
    }
    $prefix = (string) fread($handle, 8);
    fclose($handle);
    if ($extension === 'pdf' && !str_starts_with($prefix, '%PDF-')) {
        throw new RuntimeException('PDF 檔案格式驗證失敗。');
    }
    if (in_array($extension, ['pptx','docx','xlsx'], true)) {
        if (!str_starts_with($prefix, "PK\x03\x04")) {
            throw new RuntimeException('Office 檔案格式驗證失敗。');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Office 檔案格式驗證失敗。');
        }
        try {
            $required = match ($extension) {
                'pptx' => 'ppt/presentation.xml',
                'docx' => 'word/document.xml',
                'xlsx' => 'xl/workbook.xml',
            };
            $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($required) !== false;
            if (!$valid || $zip->numFiles < 2 || $zip->numFiles > 10000) {
                throw new RuntimeException('Office 檔案結構驗證失敗。');
            }
            $totalUncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) throw new RuntimeException('Office 壓縮項目無法驗證。');
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $segments = explode('/', trim($name, '/'));
                if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')
                    || preg_match('/\A[A-Za-z]:/', $name) === 1 || in_array('..', $segments, true) || count($segments) > 24) {
                    throw new RuntimeException('Office 壓縮項目路徑不安全。');
                }
                $size = max(0, (int) ($stat['size'] ?? 0));
                $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
                $totalUncompressed += $size;
                if ($totalUncompressed > 536870912 || $size > 268435456
                    || ($size >= 1048576 && ($compressed === 0 || ($size / max(1, $compressed)) > 200))) {
                    throw new RuntimeException('Office 壓縮內容超出安全限制。');
                }
                if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== 0) {
                    throw new RuntimeException('Office 文件不可加密。');
                }
            }
        } finally {
            $zip->close();
        }
    }
    if (in_array($extension, ['md','txt'], true)) {
        portal_staging_validate_utf8_text($path);
    }
    if (in_array($extension, ['png','jpg','jpeg','webp','gif'], true)) {
        $details = @getimagesize($path);
        $allowedMimes = ['image/png','image/jpeg','image/webp','image/gif'];
        if (!is_array($details) || (int) ($details[0] ?? 0) < 1 || (int) ($details[1] ?? 0) < 1
            || (int) ($details[0] ?? 0) * (int) ($details[1] ?? 0) > 40000000
            || !in_array((string) ($details['mime'] ?? ''), $allowedMimes, true)) {
            throw new RuntimeException('圖片檔案格式或尺寸驗證失敗。');
        }
        $expectedMime = in_array($extension, ['jpg','jpeg'], true) ? 'image/jpeg' : 'image/' . $extension;
        if ((string) $details['mime'] !== $expectedMime) {
            throw new RuntimeException('圖片副檔名與內容格式不一致。');
        }
    }
}

function portal_staging_run_process(array $command, string $workingDirectory, int $timeoutSeconds = 240): void
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('無法啟動文件預覽轉換器。');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = microtime(true);
    $outputBytes = 0;
    $timedOut = false;
    do {
        foreach ([1, 2] as $index) {
            $data = stream_get_contents($pipes[$index], 8192);
            if (is_string($data)) {
                $outputBytes += strlen($data);
            }
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $started > $timeoutSeconds || $outputBytes > 65536) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(100000);
    } while (true);
    foreach ([1, 2] as $index) {
        fclose($pipes[$index]);
    }
    $exitCode = proc_close($process);
    if ($timedOut || $exitCode !== 0) {
        throw new RuntimeException($timedOut ? '文件預覽轉換逾時。' : '文件預覽轉換失敗。');
    }
}

function portal_staging_validate_decodable_image(string $source, string $extension, string $executor): void
{
    $script=dirname(__DIR__).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'image_exporter.py';
    if($executor===''||!is_file($executor)||is_link($executor)) throw new RuntimeException('圖片驗證工具尚未設定。');
    $command = str_ends_with(strtolower($executor), '.exe') && str_contains(strtolower(basename($executor)), 'image_exporter')
        ? [$executor,'--validate',$source,$extension]
        : [$executor,$script,'--validate',$source,$extension];
    if(count($command)===5&&!is_file($script)) throw new RuntimeException('圖片驗證工具尚未設定。');
    portal_staging_run_process($command,dirname(__DIR__),30);
}

function portal_staging_build_preview(string $source, string $extension, string $workspaceRoot, string $libreOfficePath, string $imageExecutor = ''): array
{
    $extension = strtolower($extension);
    $workspace = realpath($workspaceRoot);
    $sourceReal = realpath($source);
    if ($workspace === false || $sourceReal === false || !is_dir($workspace) || !is_file($sourceReal)
        || is_link($workspace) || is_link($sourceReal)
        || !str_starts_with(strtolower($sourceReal), strtolower(rtrim($workspace, '\\/') . DIRECTORY_SEPARATOR))
        || !portal_staging_extension_allowed($extension)) {
        throw new RuntimeException('暫存預覽來源不安全。');
    }
    if (in_array($extension, ['md','txt'], true)) {
        portal_staging_validate_artifact($sourceReal, $extension);
        return ['kind' => 'text', 'relative_path' => 'source.' . $extension];
    }
    if (in_array($extension, ['png','jpg','jpeg','webp','gif'], true)) {
        portal_staging_validate_artifact($sourceReal, $extension);
        portal_staging_validate_decodable_image($sourceReal,$extension,$imageExecutor);
        return ['kind' => 'image', 'relative_path' => 'source.' . $extension];
    }
    $preview = $workspace . DIRECTORY_SEPARATOR . 'preview.pdf';
    if (is_link($preview) || (is_file($preview) && !unlink($preview))) {
        throw new RuntimeException('無法重建文件預覽。');
    }
    if ($extension === 'pdf') {
        portal_staging_validate_artifact($sourceReal, 'pdf');
        if (!copy($sourceReal, $preview)) {
            throw new RuntimeException('無法建立 PDF 預覽。');
        }
    } else {
        $libreOffice = realpath($libreOfficePath) ?: '';
        if ($libreOffice === '' || !is_file($libreOffice) || is_link($libreOffice)) {
            throw new RuntimeException('LibreOffice 預覽轉換器尚未設定。');
        }
        $output = $workspace . DIRECTORY_SEPARATOR . 'conversion-output';
        $profile = $workspace . DIRECTORY_SEPARATOR . 'libreoffice-profile';
        if ((!is_dir($output) && !mkdir($output, 0770, true)) || (!is_dir($profile) && !mkdir($profile, 0770, true))
            || is_link($output) || is_link($profile)) {
            throw new RuntimeException('無法建立文件預覽轉換空間。');
        }
        $filter = match ($extension) {
            'pptx' => 'pdf:impress_pdf_Export',
            'docx' => 'pdf:writer_pdf_Export',
            'xlsx' => 'pdf:calc_pdf_Export',
            default => throw new RuntimeException('文件格式不支援預覽轉換。'),
        };
        $profileUri = 'file:///' . str_replace(['%', ' ', '\\'], ['%25', '%20', '/'], $profile);
        portal_staging_run_process([
            $libreOffice, '--headless', '--nologo', '--nodefault', '--nolockcheck', '--norestore',
            '-env:UserInstallation=' . $profileUri, '--convert-to', $filter, '--outdir', $output, $sourceReal,
        ], $workspace);
        $converted = $output . DIRECTORY_SEPARATOR . 'source.pdf';
        if (!is_file($converted) || filesize($converted) < 5 || !rename($converted, $preview)) {
            throw new RuntimeException('文件預覽轉換未產生 PDF。');
        }
    }
    portal_staging_validate_artifact($preview, 'pdf');
    return ['kind' => 'pdf', 'relative_path' => 'preview.pdf'];
}

function portal_staging_generate_preview(PDO $database, array $config, array $user, string $publicId): array
{
    $workspace = portal_staging_get($database, $user, $publicId);
    if ($workspace === null || (string) $workspace['status_code'] !== 'uploaded') {
        throw new RuntimeException('暫存文件尚未完成上傳。');
    }
    $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'previewing', error_code = NULL, updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'uploaded'");
    $update->execute([(int) $workspace['staged_revision_id']]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('暫存文件正在由其他程序處理。');
    }
    $root = portal_staging_workspace_path($config, $publicId);
    $extension = (string) $workspace['extension'];
    $source = $root . DIRECTORY_SEPARATOR . 'source.' . $extension;
    try {
        $libreOfficeValue = getenv('PORTAL_LIBREOFFICE_PATH');
        $libreOffice = $libreOfficeValue === false ? '' : (string) $libreOfficeValue;
        $previewResult = portal_staging_build_preview($source, $extension, $root, $libreOffice,(string)($config['image_export_python']??''));
        $kind = (string) $previewResult['kind'];
        $relative = (string) $previewResult['relative_path'];
        $ready = $database->prepare(
            "UPDATE dbo.staged_document_revisions
             SET status_code = 'preview_ready', preview_kind = ?, preview_relative_path = ?,
                 preview_page_count = NULL, updated_at = SYSUTCDATETIME()
             WHERE staged_revision_id = ? AND status_code = 'previewing'"
        );
        $ready->execute([$kind, $relative, (int) $workspace['staged_revision_id']]);
        portal_document_audit($database, 'staging_preview_ready', 'accepted', (int) $user['user_id'], $workspace['target_document_id'] === null ? null : (int) $workspace['target_document_id'], 'workspace=' . $publicId);
    } catch (Throwable $exception) {
        $failed = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'failed', error_code = ?, updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ?");
        $failed->execute([portal_trim_text($exception->getMessage(), 64), (int) $workspace['staged_revision_id']]);
        throw $exception;
    }
    return portal_staging_get($database, $user, $publicId) ?? throw new RuntimeException('暫存工作區不存在。');
}

function portal_staging_commit_directory(array $config, string $name): string
{
    if (!in_array($name, ['requests', 'results'], true)) {
        throw new RuntimeException('暫存確認通道不正確。');
    }
    $rootValue = (string) ($config['staged_commit_root'] ?? '');
    $root = realpath($rootValue);
    if ($root === false || !is_dir($root) || is_link($root) || !portal_path_is_outside_application_root($root)) {
        throw new RuntimeException('暫存確認通道尚未啟用。');
    }
    $directory = $root . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('暫存確認通道不安全。');
    }
    return $directory;
}

function portal_staging_prepare_commit_request(array $config, array $workspace): array
{
    $id = portal_valid_public_id((string) ($workspace['public_id'] ?? ''));
    $extension = strtolower((string) ($workspace['extension'] ?? ''));
    $hash = strtolower((string) ($workspace['staged_content_hash'] ?? ''));
    $expected = strtolower((string) ($workspace['expected_source_hash'] ?? ''));
    $relative = portal_staging_normalize_relative_path((string) ($workspace['target_relative_path'] ?? ''));
    if ($id === '' || !portal_staging_extension_allowed($extension) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1
        || ($expected !== '' && preg_match('/\A[0-9a-f]{64}\z/', $expected) !== 1)) {
        throw new RuntimeException('暫存確認資料不正確。');
    }
    $artifact = portal_staging_workspace_path($config, $id) . DIRECTORY_SEPARATOR . 'source.' . $extension;
    if (!is_file($artifact) || is_link($artifact) || !hash_equals($hash, strtolower((string) hash_file('sha256', $artifact)))) {
        throw new RuntimeException('暫存確認檔案完整性驗證失敗。');
    }
    $directory = portal_staging_commit_directory($config, 'requests');
    $path = $directory . DIRECTORY_SEPARATOR . $id . '.json';
    if (is_file($path) || is_link($path)) {
        throw new RuntimeException('暫存確認要求已存在。');
    }
    $pending = dirname($artifact) . DIRECTORY_SEPARATOR . '.commit-request.' . bin2hex(random_bytes(8)) . '.pending';
    $payload = json_encode([
        'schema_version' => 1,
        'operation_id' => $id,
        'target_relative_path' => $relative,
        'extension' => $extension,
        'candidate_sha256' => $hash,
        'expected_current_sha256' => $expected === '' ? null : $expected,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($pending, $payload, LOCK_EX) !== strlen($payload)) {
        @unlink($pending);
        throw new RuntimeException('無法準備暫存確認要求。');
    }
    return ['pending_path' => $pending, 'final_path' => $path];
}

function portal_staging_publish_commit_request(array $prepared): string
{
    $pending = (string) ($prepared['pending_path'] ?? '');
    $final = (string) ($prepared['final_path'] ?? '');
    if ($pending === '' || $final === '' || !is_file($pending) || is_link($pending) || is_file($final) || is_link($final)
        || !rename($pending, $final)) {
        @unlink($pending);
        throw new RuntimeException('無法送出暫存確認要求。');
    }
    return $final;
}

function portal_staging_write_commit_request(array $config, array $workspace): string
{
    return portal_staging_publish_commit_request(portal_staging_prepare_commit_request($config, $workspace));
}

function portal_staging_read_commit_result(array $config, string $publicId): ?array
{
    $id = portal_valid_public_id($publicId);
    if ($id === '') {
        return null;
    }
    try {
        $path = portal_staging_commit_directory($config, 'results') . DIRECTORY_SEPARATOR . $id . '.json';
    } catch (Throwable) {
        return null;
    }
    if (!is_file($path) || is_link($path)) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size < 20 || $size > 16384) {
        return null;
    }
    try {
        $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    $status = (string) ($result['status'] ?? '');
    return is_array($result) && (int) ($result['schema_version'] ?? 0) === 1
        && portal_valid_public_id((string) ($result['operation_id'] ?? '')) === $id
        && in_array($status, ['local-drive-written', 'conflict', 'failed'], true)
        ? $result : null;
}

function portal_staging_sync_commit_result(PDO $database, array $config, array $user, string $publicId): void
{
    $workspace = portal_staging_get($database, $user, $publicId);
    if ($workspace === null || (string) $workspace['status_code'] !== 'confirming') {
        return;
    }
    $result = portal_staging_read_commit_result($config, $publicId);
    if ($result === null) {
        return;
    }
    $status = (string) $result['status'];
    $actual = strtolower((string) ($result['actual_sha256'] ?? ''));
    $expectedCandidate = strtolower((string) $workspace['staged_content_hash']);
    $resultCandidate = strtolower((string) ($result['candidate_sha256'] ?? ''));
    $workspaceExpected = strtolower((string) ($workspace['expected_source_hash'] ?? ''));
    $resultExpected = strtolower((string) ($result['expected_current_sha256'] ?? ''));
    try {
        $resultTarget = portal_staging_normalize_relative_path((string) ($result['target_relative_path'] ?? ''));
        $workspaceTarget = portal_staging_normalize_relative_path((string) $workspace['target_relative_path']);
    } catch (Throwable) {
        $resultTarget = '';
        $workspaceTarget = '__invalid__';
    }
    $bindingValid = preg_match('/\A[0-9a-f]{64}\z/', $resultCandidate) === 1
        && hash_equals($expectedCandidate, $resultCandidate)
        && hash_equals($workspaceExpected, $resultExpected)
        && hash_equals($workspaceTarget, $resultTarget);
    $database->beginTransaction();
    try {
        if (!$bindingValid) {
            $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'failed', error_code = 'RESULT_BINDING_MISMATCH', updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'confirming'");
            $update->execute([(int) $workspace['staged_revision_id']]);
        } elseif ($status === 'local-drive-written' && preg_match('/\A[0-9a-f]{64}\z/', $actual) === 1 && hash_equals($expectedCandidate, $actual)) {
            $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'confirmed', drive_sync_status = 'pending', confirmed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'confirming'");
            $update->execute([(int) $workspace['staged_revision_id']]);
            portal_document_audit($database, 'staging_commit_local_drive_written', 'accepted', (int) ($workspace['confirmed_by_user_id'] ?? $user['user_id']), $workspace['target_document_id'] === null ? null : (int) $workspace['target_document_id'], 'workspace=' . $publicId . ';sha256=' . $actual);
        } elseif ($status === 'conflict') {
            $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'conflict', error_code = ?, updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'confirming'");
            $update->execute([portal_trim_text((string) ($result['error_code'] ?? 'COMMIT_CONFLICT'), 64), (int) $workspace['staged_revision_id']]);
            portal_document_audit($database, 'staging_commit_conflict', 'rejected', (int) ($workspace['confirmed_by_user_id'] ?? $user['user_id']), $workspace['target_document_id'] === null ? null : (int) $workspace['target_document_id'], 'workspace=' . $publicId);
        } else {
            $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'failed', error_code = ?, updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'confirming'");
            $update->execute([portal_trim_text((string) ($result['error_code'] ?? 'COMMIT_FAILED'), 64), (int) $workspace['staged_revision_id']]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function portal_staging_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function portal_staging_targets(PDO $database): array
{
    return $database->query(
        "SELECT CONVERT(varchar(36), public_id) AS public_id, file_name, relative_path, extension
         FROM dbo.documents WHERE status_code <> 'archived' AND relative_path LIKE N'網站文件/%'
         ORDER BY file_name, relative_path"
    )->fetchAll();
}

function portal_render_staging_workspace(PDO $database, array $config, array $user, string $selectedId = '', string $requestId = '', string $targetId = ''): void
{
    if (!portal_staging_schema_available($database) || ($config['preupload_preview_enabled'] ?? false) !== true) {
        portal_render_page('暫存預覽尚未啟用', '<section class="panel narrow"><h1>暫存預覽尚未啟用</h1><p>請先完成資料庫與私有暫存空間設定。</p></section>', $user);
        return;
    }
    portal_staging_cleanup_expired($database, $config, 20);
    if (portal_valid_public_id($selectedId) !== '') {
        portal_staging_sync_commit_result($database, $config, $user, strtolower($selectedId));
    }
    $requestId = portal_valid_public_id($requestId);
    $targetId = portal_valid_public_id($targetId);
    $targetOptions = '<option value="">新增到「網站文件」</option>';
    foreach (portal_staging_targets($database) as $target) {
        $targetPublicId = strtolower((string) $target['public_id']);
        $selectedAttribute = $targetId !== '' && $targetId === $targetPublicId ? ' selected' : '';
        $targetOptions .= '<option value="' . portal_e($targetPublicId) . '"' . $selectedAttribute . '>更新：'
            . portal_e((string) $target['file_name']) . '（' . portal_e((string) $target['relative_path']) . '）</option>';
    }
    $items = '';
    foreach (portal_staging_list($database, $user) as $workspace) {
        $id = (string) $workspace['public_id'];
        $items .= '<li><div><strong>' . portal_e((string) $workspace['file_name']) . '</strong><small>'
            . portal_e((string) $workspace['creator_name']) . ' · ' . portal_e((string) $workspace['status_code'])
            . ' · ' . portal_e(portal_format_file_size((int) $workspace['declared_size_bytes'])) . '</small></div>'
            . '<a class="secondary-button compact-button" href="' . portal_url('staging', ['id' => $id]) . '">開啟</a></li>';
    }
    if ($items === '') {
        $items = '<li class="empty-state">尚無暫存文件。</li>';
    }
    $selected = portal_staging_get($database, $user, $selectedId);
    $preview = '';
    if ($selected !== null) {
        $status = (string) $selected['status_code'];
        $id = (string) $selected['public_id'];
        $statusMessage = match ($status) {
            'confirming' => '橋接器正在進行衝突檢查與正式寫入，完成後頁面會自動更新。',
            'confirmed' => '已寫入本機 Google Drive 正式來源；目前等待雲端與索引確認。',
            'changes_requested' => '此版本已退回修改：' . (string) ($selected['review_note'] ?? ''),
            'conflict' => '正式來源在預覽期間已變更，為避免覆寫而停止確認。請重新建立暫存版本。',
            'failed' => '處理失敗，正式來源未被宣稱為同步完成。',
            default => '目前狀態：' . $status . '。完成上傳與轉換後即可預覽。',
        };
        $previewVisibleStatuses = ['preview_ready','changes_requested','confirming','confirmed','conflict'];
        $statusNotice = $status === 'preview_ready' ? '' : '<p class="notice">' . portal_e($statusMessage) . '</p>';
        $previewBody = in_array($status, $previewVisibleStatuses, true)
            ? $statusNotice . '<iframe class="staging-preview-frame" src="' . portal_url('staging_preview_asset', ['id' => $id]) . '" title="暫存文件預覽"></iframe>'
            : '<div class="empty-state">' . portal_e($statusMessage) . '</div>';
        $confirm = $status === 'preview_ready' && portal_can_confirm_staged_revision($user, (int) $selected['created_by_user_id'])
            ? '<form method="post" action="' . portal_url('staging_confirm') . '">' . portal_csrf_field() . '<input type="hidden" name="id" value="' . portal_e($id) . '"><button class="primary-button" type="submit">確認同步至 Google Drive</button></form>'
            : '';
        $return = $status === 'preview_ready' && portal_can_confirm_staged_revision($user, (int) $selected['created_by_user_id'])
            ? '<form method="post" action="' . portal_url('staging_return') . '" class="staging-return-form">' . portal_csrf_field() . '<input type="hidden" name="id" value="' . portal_e($id) . '"><label>退回修改說明<textarea name="review_note" maxlength="1000" required></textarea></label><button class="secondary-button" type="submit">退回修改</button></form>'
            : '';
        $refreshAttribute = $status === 'confirming' ? ' data-staging-refresh' : '';
        $preview = '<section class="panel staging-preview-panel"' . $refreshAttribute . '><div class="section-heading"><div><p class="eyebrow">PRIVATE PREVIEW</p><h2>' . portal_e((string) $selected['file_name']) . '</h2><p>正式路徑：' . portal_e((string) $selected['target_relative_path']) . '</p></div><span class="status-chip">' . portal_e($status) . '</span></div>' . $previewBody . '<div class="form-actions">' . $confirm . $return . '</div></section>';
    }
    $requestHidden = $requestId === '' ? '' : '<input type="hidden" name="change_request_id" value="' . portal_e($requestId) . '">';
    $requestNotice = $requestId === '' ? '' : '<p class="notice success">此暫存版本會連結剛建立的修改需求；請上傳依標註產生的新版本後再預覽。</p>';
    $content = '<section class="hero hero-row"><div><p class="eyebrow">STAGED DOCUMENT WORKSPACE</p><h1>文件暫存與預覽</h1><p>檔案先留在私有暫存區；確認預覽後才寫入 Google Drive 正式來源。</p></div><div class="hero-actions"><a class="secondary-button" href="' . portal_url('documents') . '">返回文件庫</a></div></section>'
        . '<section class="panel staging-upload-panel"><h2>新增暫存文件</h2>' . $requestNotice . '<form data-staging-upload-form data-create-url="' . portal_url('staging_workspace') . '" data-chunk-url="' . portal_url('staging_chunk') . '" data-finalize-url="' . portal_url('staging_finalize') . '">' . portal_csrf_field() . $requestHidden
        . '<label>檔案<input type="file" name="document_file" accept=".pptx,.pdf,.docx,.xlsx,.md,.txt,.png,.jpg,.jpeg,.webp,.gif" required></label>'
        . '<label>用途<select name="target_document_id">' . $targetOptions . '</select></label>'
        . '<button class="primary-button" type="submit">上傳至私有暫存並產生預覽</button><progress value="0" max="100" data-staging-progress hidden></progress><p data-staging-status class="muted"></p></form></section>'
        . '<section class="panel admin-section"><div class="section-heading"><div><h2>暫存版本</h2><p>編輯者只會看到自己的暫存版本；管理員可查看全部。</p></div></div><ul class="recent-document-list">' . $items . '</ul></section>' . $preview;
    portal_render_page('文件暫存與預覽', $content, $user, false, 'wide-readable-page staging-workspace-page');
}

function portal_handle_staging_workspace(PDO $database, array $config, array $user): never
{
    try {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            portal_staging_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
        }
        portal_assert_csrf();
        $workspace = portal_staging_create($database, $config, $user, $_POST);
        portal_staging_json(['ok' => true, 'workspace' => ['id' => (string) $workspace['public_id'], 'total_chunks' => (int) $workspace['total_chunks'], 'chunk_size_bytes' => (int) $workspace['chunk_size_bytes']]]);
    } catch (Throwable $exception) {
        error_log('TWWATER staging workspace failed: ' . $exception->getMessage());
        portal_staging_json(['ok' => false, 'error' => 'WORKSPACE_REJECTED'], 400);
    }
}

function portal_handle_staging_chunk(PDO $database, array $config, array $user): never
{
    try {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            portal_staging_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
        }
        portal_assert_csrf();
        $upload = $_FILES['chunk'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            throw new RuntimeException('上傳分段不存在。');
        }
        $body = file_get_contents((string) $upload['tmp_name']);
        if ($body === false) {
            throw new RuntimeException('上傳分段無法讀取。');
        }
        $result = portal_staging_write_chunk($database, $config, $user, (string) ($_POST['id'] ?? ''), (int) ($_POST['index'] ?? -1), $body);
        portal_staging_json(['ok' => true, 'chunk' => $result]);
    } catch (Throwable $exception) {
        error_log('TWWATER staging chunk failed: ' . $exception->getMessage());
        portal_staging_json(['ok' => false, 'error' => 'CHUNK_REJECTED'], 400);
    }
}

function portal_handle_staging_finalize(PDO $database, array $config, array $user): never
{
    try {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            portal_staging_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
        }
        portal_assert_csrf();
        $id = (string) ($_POST['id'] ?? '');
        portal_staging_finalize($database, $config, $user, $id);
        $workspace = portal_staging_generate_preview($database, $config, $user, $id);
        portal_staging_json(['ok' => true, 'workspace' => ['id' => (string) $workspace['public_id'], 'status' => (string) $workspace['status_code'], 'url' => portal_url('staging', ['id' => (string) $workspace['public_id']])]]);
    } catch (Throwable $exception) {
        error_log('TWWATER staging finalize failed: ' . $exception->getMessage());
        portal_staging_json(['ok' => false, 'error' => 'FINALIZE_REJECTED'], 400);
    }
}

function portal_handle_staging_return(PDO $database, array $config, array $user): never
{
    try {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            exit;
        }
        portal_assert_csrf();
        $id = portal_valid_public_id((string) ($_POST['id'] ?? ''));
        $note = portal_trim_text((string) ($_POST['review_note'] ?? ''), 1000);
        if ($id === '' || $note === '') {
            throw new RuntimeException('退回時必須填寫修改說明。');
        }
        $database->beginTransaction();
        $workspace = portal_staging_get($database, $user, $id, true);
        if ($workspace === null || (string) $workspace['status_code'] !== 'preview_ready'
            || !portal_can_confirm_staged_revision($user, (int) $workspace['created_by_user_id'])) {
            throw new RuntimeException('目前無法退回這個暫存版本。');
        }
        $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'changes_requested', review_note = ?, updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'preview_ready'");
        $update->execute([$note, (int) $workspace['staged_revision_id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('暫存版本已由其他操作處理。');
        portal_document_audit($database, 'staging_changes_requested', 'accepted', (int) $user['user_id'], $workspace['target_document_id'] === null ? null : (int) $workspace['target_document_id'], 'workspace=' . $id);
        $database->commit();
        header('Location: ' . portal_url('staging', ['id' => $id]), true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        error_log('TWWATER staging return failed: ' . $exception->getMessage());
        http_response_code(400);
        portal_render_page('無法退回暫存版本', '<section class="panel narrow"><h1>無法退回暫存版本</h1><p>請填寫修改說明並重新嘗試。</p><a class="secondary-button" href="' . portal_url('staging') . '">返回暫存預覽</a></section>', $user);
        exit;
    }
}

function portal_handle_staging_confirm(PDO $database, array $config, array $user): never
{
    $prepared = [];
    $databaseCommitted = false;
    $id = '';
    try {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            exit;
        }
        portal_assert_csrf();
        $id = portal_valid_public_id((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('暫存工作區識別碼不正確。');
        }
        $database->beginTransaction();
        $workspace = portal_staging_get($database, $user, $id, true);
        if ($workspace === null || (string) $workspace['status_code'] !== 'preview_ready'
            || !portal_can_confirm_staged_revision($user, (int) $workspace['created_by_user_id'])) {
            throw new RuntimeException('目前無法確認這個暫存版本。');
        }
        $prepared = portal_staging_prepare_commit_request($config, $workspace);
        $update = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'confirming', confirmed_by_user_id = ?, error_code = NULL, updated_at = SYSUTCDATETIME() WHERE staged_revision_id = ? AND status_code = 'preview_ready'");
        $update->execute([(int) $user['user_id'], (int) $workspace['staged_revision_id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('暫存版本已由其他操作處理。');
        }
        portal_document_audit($database, 'staging_commit_requested', 'accepted', (int) $user['user_id'], $workspace['target_document_id'] === null ? null : (int) $workspace['target_document_id'], 'workspace=' . $id);
        $database->commit();
        $databaseCommitted = true;
        portal_staging_publish_commit_request($prepared);
        $prepared = [];
        header('Location: ' . portal_url('staging', ['id' => $id]), true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        $pendingPath = (string) ($prepared['pending_path'] ?? '');
        if ($pendingPath !== '' && is_file($pendingPath) && !is_link($pendingPath)) {
            @unlink($pendingPath);
        }
        if ($databaseCommitted && $id !== '') {
            try {
                $failed = $database->prepare("UPDATE dbo.staged_document_revisions SET status_code = 'failed', error_code = 'REQUEST_PUBLISH_FAILED', updated_at = SYSUTCDATETIME() WHERE public_id = CONVERT(uniqueidentifier, ?) AND status_code = 'confirming'");
                $failed->execute([$id]);
            } catch (Throwable $repairException) {
                error_log('TWWATER staging confirm repair failed: ' . $repairException->getMessage());
            }
        }
        error_log('TWWATER staging confirm failed: ' . $exception->getMessage());
        http_response_code(400);
        portal_render_page('無法確認暫存版本', '<section class="panel narrow"><h1>無法確認暫存版本</h1><p>請重新整理暫存工作區後再試一次。</p><a class="secondary-button" href="' . portal_url('staging') . '">返回暫存預覽</a></section>', $user);
        exit;
    }
}

function portal_handle_staging_preview_asset(PDO $database, array $config, array $user, string $publicId): never
{
    $workspace = portal_staging_get($database, $user, $publicId);
    if ($workspace === null || !in_array((string) $workspace['status_code'], ['preview_ready','changes_requested','confirming','confirmed','conflict'], true)) {
        http_response_code(404);
        exit;
    }
    $relative = (string) $workspace['preview_relative_path'];
    $allowedRelative = ['preview.pdf', 'source.md', 'source.txt'];
    if (portal_is_image_extension((string) $workspace['extension'])) {
        $allowedRelative[] = 'source.' . strtolower((string) $workspace['extension']);
    }
    if (!in_array($relative, $allowedRelative, true)) {
        http_response_code(404);
        exit;
    }
    $path = portal_staging_workspace_path($config, $publicId) . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path) || is_link($path)) {
        http_response_code(404);
        exit;
    }
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if ((string) $workspace['preview_kind'] === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="preview.pdf"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ((string) $workspace['preview_kind'] === 'image') {
        $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif'][strtolower((string) $workspace['extension'])] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="preview.' . strtolower((string) $workspace['extension']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    header("Content-Security-Policy: default-src 'none'");
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function portal_staging_finalize(PDO $database, array $config, array $user, string $publicId): array
{
    $artifact = '';
    $artifactWasCreated = false;
    $database->beginTransaction();
    try {
        $workspace = portal_staging_get($database, $user, $publicId, true);
        if ($workspace === null || (string) $workspace['status_code'] !== 'uploading') {
            throw new RuntimeException('暫存工作區目前不能完成上傳。');
        }
        $root = portal_staging_workspace_path($config, $publicId);
        $artifact = $root . DIRECTORY_SEPARATOR . 'source.' . (string) $workspace['extension'];
        $target = fopen($artifact, 'x+b');
        if (!is_resource($target)) {
            throw new RuntimeException('無法建立暫存文件。');
        }
        $artifactWasCreated = true;
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            for ($index = 0; $index < (int) $workspace['total_chunks']; $index++) {
                $partPath = $root . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . sprintf('%06d.part', $index);
                if (!is_file($partPath) || is_link($partPath)) {
                    throw new RuntimeException('尚有上傳分段未完成。');
                }
                $part = fopen($partPath, 'rb');
                if (!is_resource($part)) {
                    throw new RuntimeException('上傳分段無法讀取。');
                }
                while (!feof($part)) {
                    $chunk = fread($part, 1048576);
                    if ($chunk === false) {
                        fclose($part);
                        throw new RuntimeException('上傳分段無法讀取。');
                    }
                    if ($chunk !== '') {
                        $bytes += strlen($chunk);
                        hash_update($hash, $chunk);
                        if (fwrite($target, $chunk) !== strlen($chunk)) {
                            fclose($part);
                            throw new RuntimeException('無法組合暫存文件。');
                        }
                    }
                }
                fclose($part);
            }
            fflush($target);
        } finally {
            fclose($target);
        }
        if ($bytes !== (int) $workspace['declared_size_bytes']) {
            throw new RuntimeException('暫存文件大小與上傳宣告不一致。');
        }
        portal_staging_validate_artifact($artifact, (string) $workspace['extension']);
        $digest = hash_final($hash);
        $update = $database->prepare(
            "UPDATE dbo.staged_document_revisions
             SET staged_content_hash = ?, status_code = 'uploaded', updated_at = SYSUTCDATETIME()
             WHERE staged_revision_id = ?"
        );
        $update->execute([$digest, (int) $workspace['staged_revision_id']]);
        $database->commit();
        foreach (glob($root . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . '*.part') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir($root . DIRECTORY_SEPARATOR . 'chunks');
        return portal_staging_get($database, $user, $publicId) ?? throw new RuntimeException('暫存工作區不存在。');
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if ($artifactWasCreated && $artifact !== '' && is_file($artifact) && !is_link($artifact)) {
            @unlink($artifact);
        }
        throw $exception;
    }
}
