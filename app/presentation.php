<?php
declare(strict_types=1);

/*
 * Presentation preview and review services.
 *
 * The source PPTX is never written by this module. Every preview, annotation
 * and change request is bound to an immutable SHA-256 document version.
 */

const PORTAL_PRESENTATION_JSON_MAX_BYTES = 262144;
const PORTAL_PRESENTATION_MAX_ANNOTATIONS_PER_REQUEST = 100;

function portal_reviewable_document_extension(string $extension): bool
{
    return in_array(strtolower($extension), ['pptx', 'md'], true);
}

function portal_review_rendition_kind(array $document): string
{
    return strtolower((string) ($document['extension'] ?? '')) === 'md' ? 'markdown' : 'pdf';
}

function portal_presentation_schema_available(PDO $database): bool
{
    $statement = $database->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.document_versions', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.presentation_preview_jobs', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.presentation_renditions', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.presentation_slides', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.presentation_annotations', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.presentation_change_requests', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.presentation_change_request_items', N'U') IS NOT NULL
                     THEN 1 ELSE 0 END"
    );
    return (int) $statement->fetchColumn() === 1;
}

function portal_presentation_reconcile_catalog(PDO $database, ?int $actorUserId): int
{
    if (!portal_presentation_schema_available($database)) {
        return 0;
    }

    $documents = $database->query(
        "SELECT document_id, relative_path, file_size_bytes, source_modified_at, content_hash
         FROM dbo.documents
         WHERE extension = 'pptx' AND status_code <> 'archived' AND content_hash IS NOT NULL
         ORDER BY document_id"
    )->fetchAll();
    $findVersion = $database->prepare(
        'SELECT version_id FROM dbo.document_versions WHERE document_id = ? AND source_content_hash = ?'
    );
    $nextVersion = $database->prepare(
        'SELECT ISNULL(MAX(version_number), 0) + 1 FROM dbo.document_versions WITH (UPDLOCK, HOLDLOCK) WHERE document_id = ?'
    );
    $insertVersion = $database->prepare(
        'INSERT INTO dbo.document_versions
            (document_id, version_number, source_content_hash, source_file_size_bytes, source_modified_at, source_relative_path, created_by_user_id)
         OUTPUT INSERTED.version_id
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $findJob = $database->prepare(
        'SELECT TOP (1) preview_job_id, status_code FROM dbo.presentation_preview_jobs
         WHERE document_version_id = ? ORDER BY created_at DESC'
    );
    $requeueJob = $database->prepare(
        "UPDATE dbo.presentation_preview_jobs
         SET status_code = 'queued', attempt_count = 0, available_at = SYSUTCDATETIME(),
             started_at = NULL, completed_at = NULL, lease_owner = NULL, lease_expires_at = NULL,
             error_code = NULL, error_message = NULL, requested_by_user_id = ?, updated_at = SYSUTCDATETIME()
         WHERE preview_job_id = ? AND status_code IN ('failed', 'cancelled')"
    );
    $insertJob = $database->prepare(
        "INSERT INTO dbo.presentation_preview_jobs
            (document_version_id, job_key, status_code, priority, requested_by_user_id)
         VALUES (?, ?, 'queued', 100, ?)"
    );

    $queued = 0;
    foreach ($documents as $document) {
        $hash = strtolower((string) $document['content_hash']);
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            continue;
        }
        $findVersion->execute([(int) $document['document_id'], $hash]);
        $versionId = $findVersion->fetchColumn();
        if ($versionId === false) {
            if (function_exists('portal_document_version_blob_schema_ready') && portal_document_version_blob_schema_ready($database)) {
                continue;
            }
            $nextVersion->execute([(int) $document['document_id']]);
            $versionNumber = (int) $nextVersion->fetchColumn();
            $insertVersion->execute([
                (int) $document['document_id'],
                $versionNumber,
                $hash,
                (int) $document['file_size_bytes'],
                (string) $document['source_modified_at'],
                (string) $document['relative_path'],
                $actorUserId,
            ]);
            $versionId = (int) $insertVersion->fetchColumn();
        } else {
            $versionId = (int) $versionId;
        }

        $findJob->execute([$versionId]);
        $existingJob = $findJob->fetch();
        if ($existingJob !== false) {
            if (in_array((string) $existingJob['status_code'], ['failed', 'cancelled'], true)) {
                $requeueJob->execute([$actorUserId, (int) $existingJob['preview_job_id']]);
                $queued += $requeueJob->rowCount();
            }
            continue;
        }
        $jobKey = hash('sha256', 'pptx-preview|' . $versionId . '|' . $hash);
        $insertJob->execute([$versionId, $jobKey, $actorUserId]);
        $queued++;
    }
    return $queued;
}

function portal_ensure_markdown_review_state(PDO $database, array $document, ?int $actorUserId): ?array
{
    if (!portal_presentation_schema_available($database)
        || strtolower((string) ($document['extension'] ?? '')) !== 'md') {
        return null;
    }
    $hash = strtolower((string) ($document['content_hash'] ?? ''));
    if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
        return null;
    }

    $database->beginTransaction();
    try {
        $findVersion = $database->prepare(
            'SELECT version_id FROM dbo.document_versions WITH (UPDLOCK, HOLDLOCK)
             WHERE document_id = ? AND source_content_hash = ?'
        );
        $findVersion->execute([(int) $document['document_id'], $hash]);
        $versionId = $findVersion->fetchColumn();
        if ($versionId === false) {
            if (function_exists('portal_document_version_blob_schema_ready') && portal_document_version_blob_schema_ready($database)) {
                $database->rollBack();
                return null;
            }
            $nextVersion = $database->prepare(
                'SELECT ISNULL(MAX(version_number), 0) + 1
                 FROM dbo.document_versions WITH (UPDLOCK, HOLDLOCK) WHERE document_id = ?'
            );
            $nextVersion->execute([(int) $document['document_id']]);
            $insertVersion = $database->prepare(
                'INSERT INTO dbo.document_versions
                    (document_id, version_number, source_content_hash, source_file_size_bytes,
                     source_modified_at, source_relative_path, created_by_user_id)
                 OUTPUT INSERTED.version_id
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertVersion->execute([
                (int) $document['document_id'],
                (int) $nextVersion->fetchColumn(),
                $hash,
                (int) $document['file_size_bytes'],
                (string) $document['source_modified_at'],
                (string) $document['relative_path'],
                $actorUserId,
            ]);
            $versionId = (int) $insertVersion->fetchColumn();
        } else {
            $versionId = (int) $versionId;
        }

        $findRendition = $database->prepare(
            "SELECT TOP (1) rendition_id FROM dbo.presentation_renditions WITH (UPDLOCK, HOLDLOCK)
             WHERE document_version_id = ? AND rendition_kind = 'markdown'
               AND status_code = 'ready' AND is_current = 1
             ORDER BY created_at DESC"
        );
        $findRendition->execute([$versionId]);
        $renditionId = $findRendition->fetchColumn();
        if ($renditionId === false) {
            $insertRendition = $database->prepare(
                "INSERT INTO dbo.presentation_renditions
                    (document_version_id, preview_job_id, rendition_kind, status_code,
                     storage_relative_path, manifest_relative_path, mime_type, artifact_sha256,
                     manifest_sha256, file_size_bytes, page_count, generator_name,
                     generator_version, is_current)
                 OUTPUT INSERTED.rendition_id
                 VALUES (?, NULL, 'markdown', 'ready', ?, NULL, 'text/markdown', ?, NULL, ?, 1,
                         'TWWATER Browser Markdown', '1', 1)"
            );
            $insertRendition->execute([
                $versionId,
                'markdown/' . $hash . '.md',
                $hash,
                (int) $document['file_size_bytes'],
            ]);
            $renditionId = (int) $insertRendition->fetchColumn();
        } else {
            $renditionId = (int) $renditionId;
        }

        $slide = $database->prepare(
            'SELECT slide_id FROM dbo.presentation_slides WITH (UPDLOCK, HOLDLOCK)
             WHERE rendition_id = ? AND slide_number = 1'
        );
        $slide->execute([$renditionId]);
        if ($slide->fetchColumn() === false) {
            $insertSlide = $database->prepare(
                'INSERT INTO dbo.presentation_slides (rendition_id, document_version_id, slide_number, extracted_text)
                 VALUES (?, ?, 1, ?)'
            );
            $insertSlide->execute([$renditionId, $versionId, null]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    return portal_presentation_current_state($database, $document);
}

final class PortalPresentationHttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}

function portal_presentation_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function portal_presentation_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function portal_presentation_json_action(callable $action): never
{
    try {
        $result = $action();
        portal_presentation_json_response(is_array($result) ? $result : ['ok' => true]);
    } catch (PortalPresentationHttpException $exception) {
        portal_presentation_json_response(['ok' => false, 'error' => $exception->getMessage()], $exception->statusCode);
    } catch (Throwable $exception) {
        error_log('TWWATER presentation action failed: ' . $exception->getMessage());
        portal_presentation_json_response(['ok' => false, 'error' => '簡報功能暫時無法使用。'], 500);
    }
}

function portal_presentation_read_json_body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > PORTAL_PRESENTATION_JSON_MAX_BYTES) {
        throw new PortalPresentationHttpException('送出的標注資料過大。', 413);
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new PortalPresentationHttpException('無法讀取送出的資料。', 400);
    }
    $raw = stream_get_contents($stream, PORTAL_PRESENTATION_JSON_MAX_BYTES + 1);
    fclose($stream);
    if ($raw === false || strlen($raw) > PORTAL_PRESENTATION_JSON_MAX_BYTES) {
        throw new PortalPresentationHttpException('送出的標注資料過大。', 413);
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new PortalPresentationHttpException('JSON 格式不正確。', 400);
    }
    if (!is_array($decoded)) {
        throw new PortalPresentationHttpException('JSON 格式不正確。', 400);
    }
    return $decoded;
}

function portal_presentation_assert_csrf_header(): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = portal_csrf_token();
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        throw new PortalPresentationHttpException('安全驗證失敗，請重新整理頁面。', 403);
    }
}

function portal_presentation_assert_method(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
        throw new PortalPresentationHttpException('不允許的請求方法。', 405);
    }
}

function portal_presentation_current_state(PDO $database, array $document): ?array
{
    if (!portal_presentation_schema_available($database)) {
        return null;
    }
    $hash = strtolower((string) ($document['content_hash'] ?? ''));
    if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
        return null;
    }
    $statement = $database->prepare(
        "SELECT TOP (1)
             dv.version_id,
             CONVERT(varchar(36), dv.public_id) AS version_public_id,
             dv.version_number,
             dv.source_content_hash,
             dv.source_file_size_bytes,
             CONVERT(varchar(36), rendition.public_id) AS rendition_public_id,
             rendition.rendition_id,
             rendition.storage_relative_path,
             rendition.artifact_sha256,
             rendition.file_size_bytes AS rendition_file_size_bytes,
             rendition.page_count,
             rendition.generator_name,
             rendition.generator_version,
             job.preview_job_id,
             job.status_code AS job_status_code,
             job.error_code AS job_error_code,
             job.updated_at AS job_updated_at
         FROM dbo.document_versions dv
         OUTER APPLY
         (
             SELECT TOP (1) r.*
             FROM dbo.presentation_renditions r
             WHERE r.document_version_id = dv.version_id
               AND r.rendition_kind = ?
               AND r.status_code = 'ready'
             ORDER BY r.is_current DESC, r.created_at DESC
         ) rendition
         OUTER APPLY
         (
             SELECT TOP (1) j.preview_job_id, j.status_code, j.error_code, j.updated_at
             FROM dbo.presentation_preview_jobs j
             WHERE j.document_version_id = dv.version_id
             ORDER BY j.created_at DESC
         ) job
         WHERE dv.document_id = ? AND dv.source_content_hash = ?
         ORDER BY dv.version_number DESC"
    );
    $statement->execute([portal_review_rendition_kind($document), (int) $document['document_id'], $hash]);
    $state = $statement->fetch();
    return $state === false ? null : $state;
}

function portal_presentation_assert_current_source(array $config, array $document, array $state): void
{
    $expectedHash = strtolower((string) ($state['source_content_hash'] ?? ''));
    if (preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1) {
        throw new PortalPresentationHttpException('此簡報尚未建立可靠版本，不能標注。', 409);
    }
    $path = portal_document_file_path($config, $document);
    $size = filesize($path);
    if ($size === false || $size > PORTAL_DOCUMENT_HASH_MAX_BYTES) {
        throw new PortalPresentationHttpException('簡報過大或無法驗證版本，不能標注。', 409);
    }
    $actualHash = hash_file('sha256', $path);
    if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {
        throw new PortalPresentationHttpException('簡報內容已更新，請先重新索引並重新開啟頁面。', 409);
    }
}

function portal_presentation_fetch_annotations(PDO $database, int $versionId, int $renditionId): array
{
    $statement = $database->prepare(
        "SELECT CONVERT(varchar(36), a.public_id) AS id,
                CONVERT(varchar(36), dv.public_id) AS version_id,
                s.slide_number,
                a.slide_asset_sha256 AS slide_asset_hash,
                a.annotation_type AS type,
                a.x_norm AS x,
                a.y_norm AS y,
                a.width_norm AS width,
                a.height_norm AS height,
                a.geometry_json,
                a.style_json,
                a.comment_text AS comment,
                a.status_code,
                CONVERT(varchar(18), a.rowver, 1) AS row_version,
                a.created_at,
                a.updated_at
         FROM dbo.presentation_annotations a
         INNER JOIN dbo.document_versions dv ON dv.version_id = a.document_version_id
         INNER JOIN dbo.presentation_slides s ON s.slide_id = a.slide_id
         WHERE a.document_version_id = ? AND s.rendition_id = ? AND a.status_code = 'open'
         ORDER BY s.slide_number, a.annotation_id"
    );
    $statement->execute([$versionId, $renditionId]);
    $annotations = [];
    foreach ($statement->fetchAll() as $row) {
        $row['slide_number'] = (int) $row['slide_number'];
        foreach (['x', 'y', 'width', 'height'] as $coordinate) {
            $row[$coordinate] = (float) $row[$coordinate];
        }
        foreach (['geometry_json' => 'geometry', 'style_json' => 'style'] as $source => $target) {
            $decoded = json_decode((string) ($row[$source] ?? '{}'), true);
            $row[$target] = is_array($decoded) ? $decoded : [];
            unset($row[$source]);
        }
        $annotations[] = $row;
    }
    return $annotations;
}

function portal_presentation_manifest_data(PDO $database, array $document, array $user, ?array $state): array
{
    $permissions = [
        'view' => true,
        'annotate' => portal_can_edit_documents($user),
        'create_request' => portal_can_edit_documents($user),
    ];
    $payload = [
        'ok' => true,
        'document' => [
            'id' => (string) $document['public_id'],
            'title' => (string) $document['title'],
            'file_name' => (string) $document['file_name'],
            'extension' => strtolower((string) $document['extension']),
            'source_url' => portal_url('inline', ['id' => (string) $document['public_id']]),
            'source_size_bytes' => (int) $document['file_size_bytes'],
            'content_hash' => strtolower((string) $document['content_hash']),
        ],
        'version' => null,
        'rendition' => null,
        'preview' => ['status' => 'unavailable'],
        'slides' => [],
        'annotations' => [],
        'permissions' => $permissions,
    ];
    if ($state === null) {
        $payload['preview'] = ['status' => 'not_versioned'];
        return $payload;
    }
    $payload['version'] = [
        'id' => (string) $state['version_public_id'],
        'number' => (int) $state['version_number'],
        'content_hash' => (string) $state['source_content_hash'],
    ];
    $jobStatus = (string) ($state['job_status_code'] ?? 'queued');
    $payload['preview'] = [
        'status' => $state['rendition_id'] === null ? $jobStatus : 'ready',
        'error_code' => $state['job_error_code'] === null ? null : (string) $state['job_error_code'],
    ];
    if ($state['rendition_id'] === null) {
        return $payload;
    }
    $documentFormat = strtolower((string) $document['extension']);
    $pdfUrl = $documentFormat === 'pptx'
        ? portal_url('presentation_asset', [
            'id' => (string) $document['public_id'],
            'rendition' => (string) $state['rendition_public_id'],
        ])
        : null;
    $payload['rendition'] = [
        'id' => (string) $state['rendition_public_id'],
        'format' => $documentFormat,
        'pdf_url' => $pdfUrl,
        'asset_hash' => (string) $state['artifact_sha256'],
        'page_count' => (int) $state['page_count'],
        'generator' => trim((string) $state['generator_name'] . ' ' . (string) $state['generator_version']),
    ];
    $slidesStatement = $database->prepare(
        "SELECT slide_id, slide_number, asset_sha256,
                CONVERT(varchar(18), rowver, 1) AS row_version
         FROM dbo.presentation_slides
         WHERE rendition_id = ? AND document_version_id = ?
         ORDER BY slide_number"
    );
    $slidesStatement->execute([(int) $state['rendition_id'], (int) $state['version_id']]);
    foreach ($slidesStatement->fetchAll() as $slide) {
        $payload['slides'][] = [
            'slide_number' => (int) $slide['slide_number'],
            'title' => $documentFormat === 'md'
                ? 'Markdown 文件'
                : '第 ' . (int) $slide['slide_number'] . ' 頁',
            'asset_hash' => $slide['asset_sha256'] === null ? null : (string) $slide['asset_sha256'],
            'pdf_url' => $pdfUrl,
        ];
    }
    $payload['annotations'] = portal_presentation_fetch_annotations(
        $database,
        (int) $state['version_id'],
        (int) $state['rendition_id']
    );
    return $payload;
}

function portal_render_presentation_page(PDO $database, array $config, array $user, string $publicId): void
{
    $document = portal_get_catalog_document($database, $user, $publicId);
    if ($document === null || !portal_reviewable_document_extension((string) $document['extension'])) {
        http_response_code(404);
        portal_render_page('找不到文件', '<section class="panel narrow"><h1>找不到文件</h1><p>文件不存在、沒有閱讀權限，或不支援線上標注。</p></section>', $user);
        return;
    }
    $schemaReady = portal_presentation_schema_available($database);
    $isMarkdown = strtolower((string) $document['extension']) === 'md';
    $state = $schemaReady
        ? ($isMarkdown
            ? portal_ensure_markdown_review_state($database, $document, (int) $user['user_id'])
            : portal_presentation_current_state($database, $document))
        : null;
    $notice = '';
    if (!$schemaReady) {
        $notice = '<div class="presentation-notice">資料庫審閱功能尚未部署，因此標注功能暫停。</div>';
    }
    $root = '<section id="presentation-review-app" class="presentation-review-app panel"'
        . ' data-document-id="' . portal_e((string) $document['public_id']) . '"'
        . ' data-manifest-url="' . portal_e(portal_url('presentation_manifest', ['id' => (string) $document['public_id']])) . '"'
        . ' data-annotation-save-url="' . portal_e(portal_url('presentation_annotation_save', ['id' => (string) $document['public_id']])) . '"'
        . ' data-annotation-delete-url="' . portal_e(portal_url('presentation_annotation_delete', ['id' => (string) $document['public_id']])) . '"'
        . ' data-request-create-url="' . portal_e(portal_url('presentation_request_create', ['id' => (string) $document['public_id']])) . '"'
        . ' data-csrf-token="' . portal_e(portal_csrf_token()) . '"'
        . '><div class="presentation-loading" role="status">正在下載並解析' . ($isMarkdown ? ' Markdown' : ' PPTX') . '…</div></section>';
    $content = '<a class="back-link" href="' . portal_url('view', ['id' => (string) $document['public_id']]) . '">← 返回文件資訊</a>'
        . '<section class="hero presentation-hero"><p class="eyebrow">' . ($isMarkdown ? 'MARKDOWN REVIEW' : 'PRESENTATION REVIEW') . '</p><h1>'
        . portal_e((string) $document['title']) . '</h1><p>'
        . ($isMarkdown
            ? 'Markdown 由瀏覽器安全渲染為可縮放畫布，可直接框選、箭頭、螢光、文字與編號標注；原始檔保持唯讀。'
            : 'PPTX 由瀏覽器直接解析為 SVG／Canvas，無需等待伺服器轉成 PDF；既有投影片對照可繼續用於框選與標注。')
        . '</p></section>'
        . $notice . $root;
    portal_document_audit($database, 'presentation_view', 'accepted', (int) $user['user_id'], (int) $document['document_id']);
    portal_render_page((string) $document['title'] . ($isMarkdown ? '－Markdown 檢視' : '－簡報檢視'), $content, $user, true);
}

function portal_handle_presentation_manifest(PDO $database, array $config, array $user, string $publicId): never
{
    portal_presentation_json_action(static function () use ($database, $config, $user, $publicId): array {
        portal_presentation_assert_method('GET');
        $document = portal_get_catalog_document($database, $user, $publicId);
        if ($document === null || !portal_reviewable_document_extension((string) $document['extension'])) {
            throw new PortalPresentationHttpException('找不到文件或沒有閱讀權限。', 404);
        }
        $state = portal_presentation_schema_available($database)
            ? (strtolower((string) $document['extension']) === 'md'
                ? portal_ensure_markdown_review_state($database, $document, (int) $user['user_id'])
                : portal_presentation_current_state($database, $document))
            : null;
        return portal_presentation_manifest_data($database, $document, $user, $state);
    });
}

function portal_handle_presentation_requeue(PDO $database, array $user, string $publicId): never
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        exit;
    }
    $admin = portal_require_admin($database);
    try {
        portal_assert_csrf();
        $document = portal_get_catalog_document($database, $admin, $publicId);
        if ($document === null || (string) $document['extension'] !== 'pptx') {
            throw new RuntimeException('找不到簡報。');
        }
        $state = portal_presentation_current_state($database, $document);
        if ($state === null) {
            portal_presentation_reconcile_catalog($database, (int) $admin['user_id']);
        } elseif ($state['rendition_id'] === null && $state['preview_job_id'] !== null) {
            $statement = $database->prepare(
                "UPDATE dbo.presentation_preview_jobs
                 SET status_code = 'queued', attempt_count = 0, available_at = SYSUTCDATETIME(),
                     started_at = NULL, completed_at = NULL, lease_owner = NULL, lease_expires_at = NULL,
                     error_code = NULL, error_message = NULL, requested_by_user_id = ?, updated_at = SYSUTCDATETIME()
                 WHERE preview_job_id = ? AND status_code IN ('queued', 'failed', 'cancelled')"
            );
            $statement->execute([(int) $admin['user_id'], (int) $state['preview_job_id']]);
        }
        portal_document_audit(
            $database,
            'presentation_preview_requeue',
            'accepted',
            (int) $admin['user_id'],
            (int) $document['document_id']
        );
        portal_flash_set('success', '簡報已重新排入預覽工作。');
    } catch (Throwable $exception) {
        error_log('TWWATER presentation requeue failed: ' . $exception->getMessage());
        portal_flash_set('error', '簡報無法重新排入預覽工作，請檢查部署狀態。');
    }
    header('Location: ' . portal_url('presentation', ['id' => $publicId]), true, 303);
    exit;
}

function portal_presentation_safe_asset_path(array $config, string $relativePath): string
{
    if (!(bool) ($config['presentation_preview_enabled'] ?? false)) {
        throw new PortalPresentationHttpException('簡報預覽儲存區尚未設定。', 503);
    }
    if ($relativePath === '' || str_contains($relativePath, "\0")
        || preg_match('#(^|[\\/])\.\.?(?:$|[\\/])#', $relativePath) === 1
        || preg_match('/\A[0-9a-f]{2}\/[0-9a-f]{64}\/preview\.pdf\z/', str_replace('\\', '/', strtolower($relativePath))) !== 1) {
        throw new PortalPresentationHttpException('預覽資產路徑無效。', 404);
    }
    $root = realpath((string) $config['presentation_preview_root']);
    if ($root === false || !is_dir($root)) {
        throw new PortalPresentationHttpException('簡報預覽儲存區無法使用。', 503);
    }
    $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    $prefix = strtolower(rtrim($root, '\\/') . DIRECTORY_SEPARATOR);
    if ($candidate === false || !str_starts_with(strtolower($candidate), $prefix) || !is_file($candidate)) {
        throw new PortalPresentationHttpException('找不到預覽資產。', 404);
    }
    return $candidate;
}

function portal_presentation_stream_pdf(string $path, string $etagHash): never
{
    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new PortalPresentationHttpException('預覽資產無法讀取。', 404);
    }
    $etag = '"' . strtolower($etagHash) . '"';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="presentation-preview.pdf"');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=300, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag && !isset($_SERVER['HTTP_RANGE'])) {
        http_response_code(304);
        exit;
    }
    $start = 0;
    $end = $size - 1;
    $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
    $ifRange = trim((string) ($_SERVER['HTTP_IF_RANGE'] ?? ''));
    if ($range !== '' && $ifRange !== '' && !hash_equals($etag, $ifRange)) {
        /* RFC 9110: an If-Range mismatch requires a complete representation,
         * otherwise a client could combine bytes from different renditions. */
        $range = '';
    }
    if ($range !== '') {
        if (str_contains($range, ',') || preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength < 1) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        exit;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false || fseek($handle, $start) !== 0) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new PortalPresentationHttpException('預覽資產無法讀取。', 404);
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1048576, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (connection_aborted()) {
            break;
        }
    }
    fclose($handle);
    exit;
}

function portal_handle_presentation_asset(PDO $database, array $config, array $user, string $publicId, string $renditionPublicId): never
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    try {
        $document = portal_get_catalog_document($database, $user, $publicId);
        $renditionPublicId = portal_valid_public_id($renditionPublicId);
        if ($document === null || (string) $document['extension'] !== 'pptx' || $renditionPublicId === '') {
            throw new PortalPresentationHttpException('找不到簡報預覽。', 404);
        }
        $state = portal_presentation_current_state($database, $document);
        if ($state === null || !hash_equals((string) $state['rendition_public_id'], $renditionPublicId)) {
            throw new PortalPresentationHttpException('找不到目前版本的簡報預覽。', 404);
        }
        $path = portal_presentation_safe_asset_path($config, (string) $state['storage_relative_path']);
        portal_document_audit($database, 'presentation_asset', 'accepted', (int) $user['user_id'], (int) $document['document_id']);
        /* PDF.js may issue parallel byte-range requests. Release the file-based
         * PHP session lock before streaming so those requests do not serialize
         * behind one large response or block other portal actions. */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        portal_presentation_stream_pdf($path, (string) $state['artifact_sha256']);
    } catch (PortalPresentationHttpException $exception) {
        http_response_code($exception->statusCode);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $exception->getMessage();
        exit;
    } catch (Throwable $exception) {
        error_log('TWWATER presentation asset failed: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '簡報預覽暫時無法使用。';
        exit;
    }
}

function portal_presentation_number(mixed $value, string $field): float
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        throw new PortalPresentationHttpException('標注座標格式不正確：' . $field, 422);
    }
    if (!is_numeric((string) $value)) {
        throw new PortalPresentationHttpException('標注座標格式不正確：' . $field, 422);
    }
    $number = (float) $value;
    if (!is_finite($number) || $number < 0 || $number > 1) {
        throw new PortalPresentationHttpException('標注座標超出投影片範圍。', 422);
    }
    return round($number, 8);
}

function portal_presentation_clean_style(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $style = [];
    foreach (['stroke', 'fill', 'textColor'] as $key) {
        $candidate = (string) ($value[$key] ?? '');
        if (preg_match('/\A#[0-9a-fA-F]{6}\z/', $candidate) === 1) {
            $style[$key] = strtolower($candidate);
        }
    }
    if (isset($value['opacity']) && is_numeric((string) $value['opacity'])) {
        $style['opacity'] = max(0.05, min(1.0, round((float) $value['opacity'], 2)));
    }
    if (isset($value['strokeWidth']) && is_numeric((string) $value['strokeWidth'])) {
        $style['strokeWidth'] = max(1, min(12, (int) $value['strokeWidth']));
    }
    if (isset($value['fontSize']) && is_numeric((string) $value['fontSize'])) {
        $style['fontSize'] = max(10, min(48, (int) $value['fontSize']));
    }
    return $style;
}

function portal_presentation_clean_geometry(mixed $value, float $x, float $y, float $width, float $height): array
{
    $geometry = [
        'x' => $x,
        'y' => $y,
        'width' => $width,
        'height' => $height,
    ];
    if (!is_array($value)) {
        return $geometry;
    }
    if (isset($value['rotation']) && is_numeric((string) $value['rotation'])) {
        $geometry['rotation'] = max(-360, min(360, round((float) $value['rotation'], 2)));
    }
    if (array_key_exists('text', $value)) {
        $text = portal_trim_text((string) $value['text'], 500);
        /* Preserve an intentional empty label separately from comment_text. */
        $geometry['text'] = $text;
    }
    if (array_key_exists('number', $value)) {
        $number = filter_var($value['number'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 9999],
        ]);
        if ($number === false) {
            throw new PortalPresentationHttpException('編號標注的編號不正確。', 422);
        }
        $geometry['number'] = (int) $number;
    }
    $points = $value['points'] ?? null;
    if (is_array($points)) {
        if (count($points) > 500) {
            throw new PortalPresentationHttpException('自由畫線節點過多，請簡化標注。', 422);
        }
        $cleanPoints = [];
        foreach ($points as $point) {
            if (!is_array($point) || !array_key_exists('x', $point) || !array_key_exists('y', $point)) {
                throw new PortalPresentationHttpException('標注線段格式不正確。', 422);
            }
            $cleanPoints[] = [
                'x' => portal_presentation_number($point['x'], 'point.x'),
                'y' => portal_presentation_number($point['y'], 'point.y'),
            ];
        }
        $geometry['points'] = $cleanPoints;
    }
    return $geometry;
}

function portal_presentation_normalize_annotation(array $input): array
{
    $type = strtolower(trim((string) ($input['type'] ?? $input['annotation_type'] ?? '')));
    $allowedTypes = ['rectangle', 'ellipse', 'arrow', 'highlight', 'text', 'freehand', 'marker'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new PortalPresentationHttpException('不支援的標注種類。', 422);
    }
    $slideNumber = filter_var($input['slide_number'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10000],
    ]);
    if ($slideNumber === false) {
        throw new PortalPresentationHttpException('投影片頁次不正確。', 422);
    }
    $x = portal_presentation_number($input['x'] ?? null, 'x');
    $y = portal_presentation_number($input['y'] ?? null, 'y');
    $width = portal_presentation_number($input['width'] ?? null, 'width');
    $height = portal_presentation_number($input['height'] ?? null, 'height');
    if ($x + $width > 1.00000001 || $y + $height > 1.00000001
        || ($type !== 'arrow' && ($width <= 0 || $height <= 0))
        || ($type === 'arrow' && $width <= 0 && $height <= 0)) {
        throw new PortalPresentationHttpException('標注範圍超出投影片或沒有有效大小。', 422);
    }
    $comment = portal_trim_text((string) ($input['comment'] ?? $input['comment_text'] ?? ''), 2000);
    if (in_array($type, ['text', 'marker'], true) && $comment === '') {
        throw new PortalPresentationHttpException('文字或編號標注必須填寫說明。', 422);
    }
    $id = portal_valid_public_id((string) ($input['id'] ?? ''));
    $rowVersion = strtoupper(trim((string) ($input['row_version'] ?? '')));
    if ($id !== '' && preg_match('/\A0X[0-9A-F]{16}\z/', $rowVersion) !== 1) {
        throw new PortalPresentationHttpException('標注版本資料已失效，請重新整理。', 409);
    }
    return [
        'id' => $id,
        'row_version' => $rowVersion,
        'slide_number' => (int) $slideNumber,
        'type' => $type,
        'x' => $x,
        'y' => $y,
        'width' => $width,
        'height' => $height,
        'geometry' => portal_presentation_clean_geometry($input['geometry'] ?? [], $x, $y, $width, $height),
        'style' => portal_presentation_clean_style($input['style'] ?? []),
        'comment' => $comment,
    ];
}

function portal_presentation_slide(PDO $database, int $versionId, int $renditionId, int $slideNumber): array
{
    $statement = $database->prepare(
        'SELECT slide_id, asset_sha256 FROM dbo.presentation_slides
         WHERE document_version_id = ? AND rendition_id = ? AND slide_number = ?'
    );
    $statement->execute([$versionId, $renditionId, $slideNumber]);
    $slide = $statement->fetch();
    if ($slide === false) {
        throw new PortalPresentationHttpException('找不到指定投影片，預覽可能已更新。', 409);
    }
    return $slide;
}

function portal_presentation_lock_current_rendition(PDO $database, array $state): void
{
    $statement = $database->prepare(
        "SELECT rendition_id
         FROM dbo.presentation_renditions WITH (UPDLOCK, HOLDLOCK)
         WHERE rendition_id = ? AND document_version_id = ? AND status_code = 'ready'
           AND is_current = 1 AND artifact_sha256 = ?"
    );
    $statement->execute([
        (int) $state['rendition_id'],
        (int) $state['version_id'],
        strtolower((string) $state['artifact_sha256']),
    ]);
    if ($statement->fetchColumn() === false) {
        throw new PortalPresentationHttpException('簡報預覽版本已更新，請重新整理後再操作。', 409);
    }
}

function portal_presentation_assert_payload_version(array $payload, array $document, array $state): void
{
    $versionId = portal_valid_public_id((string) ($payload['version_id'] ?? ''));
    $renditionId = portal_valid_public_id((string) ($payload['rendition_id'] ?? ''));
    $contentHash = strtolower(trim((string) ($payload['content_hash'] ?? '')));
    $renditionHash = strtolower(trim((string) ($payload['rendition_hash'] ?? '')));
    if ($versionId === '' || !hash_equals((string) $state['version_public_id'], $versionId)
        || $renditionId === '' || !hash_equals((string) $state['rendition_public_id'], $renditionId)
        || preg_match('/\A[0-9a-f]{64}\z/', $contentHash) !== 1
        || preg_match('/\A[0-9a-f]{64}\z/', $renditionHash) !== 1
        || !hash_equals(strtolower((string) $state['source_content_hash']), $contentHash)
        || !hash_equals(strtolower((string) $state['artifact_sha256']), $renditionHash)
        || !hash_equals(strtolower((string) $document['content_hash']), $contentHash)) {
        throw new PortalPresentationHttpException('簡報版本已變更，請重新整理後再操作。', 409);
    }
}

function portal_presentation_save_annotations(
    PDO $database,
    array $config,
    array $user,
    array $document,
    array $state,
    array $payload
): array {
    if (!portal_can_edit_documents($user)) {
        throw new PortalPresentationHttpException('目前只有編輯者或管理員可以新增或修改標注。', 403);
    }
    portal_presentation_assert_payload_version($payload, $document, $state);
    portal_presentation_assert_current_source($config, $document, $state);
    $annotationInputs = $payload['annotations'] ?? [];
    $deletedInputs = $payload['deleted_annotation_ids'] ?? [];
    if (!is_array($annotationInputs) || !is_array($deletedInputs)
        || count($annotationInputs) > PORTAL_PRESENTATION_MAX_ANNOTATIONS_PER_REQUEST
        || count($deletedInputs) > PORTAL_PRESENTATION_MAX_ANNOTATIONS_PER_REQUEST) {
        throw new PortalPresentationHttpException('標注清單格式或數量不正確。', 422);
    }
    $normalized = [];
    foreach ($annotationInputs as $annotationInput) {
        if (!is_array($annotationInput)) {
            throw new PortalPresentationHttpException('標注資料格式不正確。', 422);
        }
        $normalized[] = portal_presentation_normalize_annotation($annotationInput);
    }
    $deletions = [];
    foreach ($deletedInputs as $deletedInput) {
        if (!is_array($deletedInput)) {
            throw new PortalPresentationHttpException('刪除標注時缺少版本資料，請重新整理。', 409);
        }
        $id = portal_valid_public_id((string) ($deletedInput['id'] ?? ''));
        $rowVersion = strtoupper(trim((string) ($deletedInput['row_version'] ?? '')));
        if ($id === '' || preg_match('/\A0X[0-9A-F]{16}\z/', $rowVersion) !== 1) {
            throw new PortalPresentationHttpException('刪除標注時版本資料無效。', 409);
        }
        $deletions[] = ['id' => $id, 'row_version' => $rowVersion];
    }

    $insert = $database->prepare(
        "INSERT INTO dbo.presentation_annotations
            (public_id, document_version_id, slide_id, slide_asset_sha256, annotation_type,
             x_norm, y_norm, width_norm, height_norm, geometry_json, style_json, comment_text,
             status_code, created_by_user_id, updated_by_user_id)
         VALUES (CONVERT(uniqueidentifier, ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)"
    );
    $update = $database->prepare(
        "UPDATE dbo.presentation_annotations
         SET slide_id = ?, slide_asset_sha256 = ?, annotation_type = ?,
             x_norm = ?, y_norm = ?, width_norm = ?, height_norm = ?, geometry_json = ?, style_json = ?,
             comment_text = ?, status_code = 'open', updated_by_user_id = ?, updated_at = SYSUTCDATETIME(),
             resolved_by_user_id = NULL, resolved_at = NULL
         WHERE public_id = CONVERT(uniqueidentifier, ?) AND document_version_id = ?
           AND rowver = CONVERT(binary(8), ?, 1) AND status_code <> 'withdrawn'
           AND slide_id IN
               (SELECT slide_id FROM dbo.presentation_slides WHERE rendition_id = ? AND document_version_id = ?)"
    );
    $withdraw = $database->prepare(
        "UPDATE dbo.presentation_annotations
         SET status_code = 'withdrawn', updated_by_user_id = ?, updated_at = SYSUTCDATETIME(),
             resolved_by_user_id = NULL, resolved_at = NULL
         WHERE public_id = CONVERT(uniqueidentifier, ?) AND document_version_id = ?
           AND rowver = CONVERT(binary(8), ?, 1) AND status_code <> 'withdrawn'
           AND slide_id IN
               (SELECT slide_id FROM dbo.presentation_slides WHERE rendition_id = ? AND document_version_id = ?)"
    );
    $database->beginTransaction();
    try {
        portal_presentation_lock_current_rendition($database, $state);
        foreach ($normalized as $annotation) {
            $slide = portal_presentation_slide(
                $database,
                (int) $state['version_id'],
                (int) $state['rendition_id'],
                (int) $annotation['slide_number']
            );
            $geometryJson = json_encode($annotation['geometry'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $styleJson = json_encode($annotation['style'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $parameters = [
                (int) $slide['slide_id'],
                $slide['asset_sha256'] === null ? null : (string) $slide['asset_sha256'],
                (string) $annotation['type'],
                (string) $annotation['x'],
                (string) $annotation['y'],
                (string) $annotation['width'],
                (string) $annotation['height'],
                $geometryJson,
                $styleJson,
                (string) $annotation['comment'],
            ];
            if ((string) $annotation['id'] === '') {
                $publicId = portal_presentation_uuid();
                $insert->execute(array_merge(
                    [$publicId, (int) $state['version_id']],
                    $parameters,
                    [(int) $user['user_id'], (int) $user['user_id']]
                ));
                continue;
            }
            $update->execute(array_merge(
                $parameters,
                [
                    (int) $user['user_id'],
                    (string) $annotation['id'],
                    (int) $state['version_id'],
                    (string) $annotation['row_version'],
                    (int) $state['rendition_id'],
                    (int) $state['version_id'],
                ]
            ));
            if ($update->rowCount() !== 1) {
                throw new PortalPresentationHttpException('有標注已被其他人更新，請重新整理。', 409);
            }
        }
        foreach ($deletions as $deletion) {
            $withdraw->execute([
                (int) $user['user_id'],
                (string) $deletion['id'],
                (int) $state['version_id'],
                (string) $deletion['row_version'],
                (int) $state['rendition_id'],
                (int) $state['version_id'],
            ]);
            if ($withdraw->rowCount() !== 1) {
                throw new PortalPresentationHttpException('有標注已被其他人更新，請重新整理。', 409);
            }
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    portal_document_audit(
        $database,
        'presentation_annotation_save',
        'accepted',
        (int) $user['user_id'],
        (int) $document['document_id'],
        'saved=' . count($normalized) . ';withdrawn=' . count($deletions)
    );
    return [
        'ok' => true,
        'annotations' => portal_presentation_fetch_annotations(
            $database,
            (int) $state['version_id'],
            (int) $state['rendition_id']
        ),
    ];
}

function portal_handle_presentation_annotation_save(
    PDO $database,
    array $config,
    array $user,
    string $publicId
): never {
    portal_presentation_json_action(static function () use ($database, $config, $user, $publicId): array {
        portal_presentation_assert_method('POST');
        portal_presentation_assert_csrf_header();
        $payload = portal_presentation_read_json_body();
        $document = portal_get_catalog_document($database, $user, $publicId);
        if ($document === null || !portal_reviewable_document_extension((string) $document['extension'])) {
            throw new PortalPresentationHttpException('找不到文件或沒有權限。', 404);
        }
        $state = portal_presentation_current_state($database, $document);
        if ($state === null || $state['rendition_id'] === null) {
            throw new PortalPresentationHttpException('文件預覽尚未完成。', 409);
        }
        return portal_presentation_save_annotations($database, $config, $user, $document, $state, $payload);
    });
}

function portal_handle_presentation_annotation_delete(
    PDO $database,
    array $config,
    array $user,
    string $publicId
): never {
    portal_presentation_json_action(static function () use ($database, $config, $user, $publicId): array {
        portal_presentation_assert_method('POST');
        portal_presentation_assert_csrf_header();
        $payload = portal_presentation_read_json_body();
        $payload['annotations'] = [];
        $payload['deleted_annotation_ids'] = [[
            'id' => (string) ($payload['id'] ?? ''),
            'row_version' => (string) ($payload['row_version'] ?? ''),
        ]];
        $document = portal_get_catalog_document($database, $user, $publicId);
        if ($document === null || !portal_reviewable_document_extension((string) $document['extension'])) {
            throw new PortalPresentationHttpException('找不到文件或沒有權限。', 404);
        }
        $state = portal_presentation_current_state($database, $document);
        if ($state === null || $state['rendition_id'] === null) {
            throw new PortalPresentationHttpException('文件預覽尚未完成。', 409);
        }
        return portal_presentation_save_annotations($database, $config, $user, $document, $state, $payload);
    });
}

function portal_presentation_selected_annotations(PDO $database, int $versionId, int $renditionId, array $annotationRefs): array
{
    $normalizedIds = [];
    foreach ($annotationRefs as $reference) {
        if (!is_array($reference)) {
            throw new PortalPresentationHttpException('修改需求缺少標注版本資料，請重新整理。', 409);
        }
        $valid = portal_valid_public_id((string) ($reference['id'] ?? ''));
        $rowVersion = strtoupper(trim((string) ($reference['row_version'] ?? '')));
        if ($valid === '' || isset($normalizedIds[$valid])) {
            throw new PortalPresentationHttpException('修改需求包含無效或重複的標注。', 422);
        }
        if (preg_match('/\A0X[0-9A-F]{16}\z/', $rowVersion) !== 1) {
            throw new PortalPresentationHttpException('修改需求缺少標注版本資料，請重新整理。', 409);
        }
        $normalizedIds[$valid] = [
            'order' => count($normalizedIds),
            'row_version' => $rowVersion,
        ];
    }
    if ($normalizedIds === [] || count($normalizedIds) > PORTAL_PRESENTATION_MAX_ANNOTATIONS_PER_REQUEST) {
        throw new PortalPresentationHttpException('請選擇 1 至 ' . PORTAL_PRESENTATION_MAX_ANNOTATIONS_PER_REQUEST . ' 筆標注。', 422);
    }
    $placeholders = implode(',', array_fill(0, count($normalizedIds), 'CONVERT(uniqueidentifier, ?)'));
    $statement = $database->prepare(
        "SELECT a.annotation_id, CONVERT(varchar(36), a.public_id) AS public_id,
                a.document_version_id, a.slide_id, a.slide_asset_sha256, a.annotation_type,
                a.x_norm, a.y_norm, a.width_norm, a.height_norm,
                a.geometry_json, a.style_json, a.comment_text, s.slide_number,
                CONVERT(varchar(18), a.rowver, 1) AS row_version
         FROM dbo.presentation_annotations a WITH (UPDLOCK, HOLDLOCK)
         INNER JOIN dbo.presentation_slides s ON s.slide_id = a.slide_id
         WHERE a.document_version_id = ? AND s.rendition_id = ? AND a.status_code = 'open'
           AND a.public_id IN ($placeholders)"
    );
    $statement->execute(array_merge([$versionId, $renditionId], array_keys($normalizedIds)));
    $rows = $statement->fetchAll();
    if (count($rows) !== count($normalizedIds)) {
        throw new PortalPresentationHttpException('部分標注已變更或不屬於目前簡報版本。', 409);
    }
    foreach ($rows as $row) {
        $key = strtolower((string) $row['public_id']);
        $actualRowVersion = strtoupper((string) $row['row_version']);
        if (!isset($normalizedIds[$key])
            || !hash_equals((string) $normalizedIds[$key]['row_version'], $actualRowVersion)) {
            throw new PortalPresentationHttpException('部分標注已被其他人更新，請重新整理。', 409);
        }
    }
    usort($rows, static function (array $left, array $right) use ($normalizedIds): int {
        return $normalizedIds[strtolower((string) $left['public_id'])]['order']
            <=> $normalizedIds[strtolower((string) $right['public_id'])]['order'];
    });
    return $rows;
}

function portal_presentation_create_change_request(
    PDO $database,
    array $config,
    array $user,
    array $document,
    array $state,
    array $payload
): array {
    if (!portal_can_edit_documents($user)) {
        throw new PortalPresentationHttpException('目前只有編輯者或管理員可以建立文件修改需求。', 403);
    }
    portal_presentation_assert_payload_version($payload, $document, $state);
    portal_presentation_assert_current_source($config, $document, $state);
    $title = portal_trim_text((string) ($payload['title'] ?? ''), 255);
    $requestText = portal_trim_text((string) ($payload['instruction'] ?? $payload['request_text'] ?? ''), 4000);
    if ($title === '') {
        $title = portal_trim_text('修改：' . (string) $document['title'], 255);
    }
    $annotationRefs = $payload['annotation_refs'] ?? [];
    if (!is_array($annotationRefs)) {
        throw new PortalPresentationHttpException('修改需求的標注清單格式不正確。', 422);
    }
    $submit = filter_var($payload['submit'] ?? false, FILTER_VALIDATE_BOOL);
    $requestPublicId = portal_presentation_uuid();
    $status = $submit ? 'submitted' : 'ready';
    $insertRequest = $database->prepare(
        "INSERT INTO dbo.presentation_change_requests
            (public_id, document_version_id, title, request_text, status_code,
             created_by_user_id, updated_by_user_id, submitted_by_user_id, submitted_at)
         VALUES (CONVERT(uniqueidentifier, ?), ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 1 THEN SYSUTCDATETIME() ELSE NULL END)"
    );
    $findRequest = $database->prepare(
        'SELECT change_request_id FROM dbo.presentation_change_requests WHERE public_id = CONVERT(uniqueidentifier, ?)'
    );
    $insertItem = $database->prepare(
        "INSERT INTO dbo.presentation_change_request_items
            (change_request_id, document_version_id, slide_id, slide_asset_sha256,
             source_annotation_id, item_order, annotation_type_snapshot,
             geometry_json_snapshot, style_json_snapshot, comment_text_snapshot,
             instruction_text, status_code)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'included')"
    );
    $database->beginTransaction();
    try {
        portal_presentation_lock_current_rendition($database, $state);
        $annotations = portal_presentation_selected_annotations(
            $database,
            (int) $state['version_id'],
            (int) $state['rendition_id'],
            $annotationRefs
        );
        $insertRequest->execute([
            $requestPublicId,
            (int) $state['version_id'],
            $title,
            $requestText === '' ? null : $requestText,
            $status,
            (int) $user['user_id'],
            (int) $user['user_id'],
            $submit ? (int) $user['user_id'] : null,
            $submit ? 1 : 0,
        ]);
        $findRequest->execute([$requestPublicId]);
        $requestId = (int) $findRequest->fetchColumn();
        if ($requestId < 1) {
            throw new RuntimeException('Unable to read the created presentation request.');
        }
        foreach ($annotations as $index => $annotation) {
            $instruction = portal_trim_text((string) ($annotation['comment_text'] ?? ''), 4000);
            if ($instruction === '') {
                $instruction = '請依照第 ' . (int) $annotation['slide_number'] . ' 頁的標注範圍調整內容。';
            }
            $insertItem->execute([
                $requestId,
                (int) $state['version_id'],
                (int) $annotation['slide_id'],
                $annotation['slide_asset_sha256'] === null ? null : (string) $annotation['slide_asset_sha256'],
                (int) $annotation['annotation_id'],
                $index + 1,
                (string) $annotation['annotation_type'],
                (string) $annotation['geometry_json'],
                $annotation['style_json'] === null ? null : (string) $annotation['style_json'],
                $annotation['comment_text'] === null ? null : (string) $annotation['comment_text'],
                $instruction,
            ]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    portal_document_audit(
        $database,
        'presentation_request_create',
        'accepted',
        (int) $user['user_id'],
        (int) $document['document_id'],
        'request=' . $requestPublicId . ';items=' . count($annotations)
    );
    return [
        'ok' => true,
        'request' => [
            'id' => $requestPublicId,
            'status' => $status,
            'export_url' => portal_url('presentation_request_export', ['request' => $requestPublicId]),
        ],
    ];
}

function portal_handle_presentation_request_create(
    PDO $database,
    array $config,
    array $user,
    string $publicId
): never {
    portal_presentation_json_action(static function () use ($database, $config, $user, $publicId): array {
        portal_presentation_assert_method('POST');
        portal_presentation_assert_csrf_header();
        $payload = portal_presentation_read_json_body();
        $document = portal_get_catalog_document($database, $user, $publicId);
        if ($document === null || !portal_reviewable_document_extension((string) $document['extension'])) {
            throw new PortalPresentationHttpException('找不到文件或沒有權限。', 404);
        }
        $state = portal_presentation_current_state($database, $document);
        if ($state === null || $state['rendition_id'] === null) {
            throw new PortalPresentationHttpException('文件預覽尚未完成。', 409);
        }
        return portal_presentation_create_change_request($database, $config, $user, $document, $state, $payload);
    });
}

function portal_presentation_change_request(PDO $database, array $user, string $requestPublicId): ?array
{
    $requestPublicId = portal_valid_public_id($requestPublicId);
    if ($requestPublicId === '') {
        return null;
    }
    $sql = "SELECT cr.change_request_id, CONVERT(varchar(36), cr.public_id) AS public_id,
                   cr.document_version_id, cr.title AS request_title, cr.request_text, cr.status_code,
                   cr.created_at, cr.submitted_at,
                   d.document_id, CONVERT(varchar(36), d.public_id) AS document_public_id,
                   d.title AS document_title, d.file_name, d.extension AS document_extension,
                   d.status_code AS document_status,
                   dv.source_content_hash, dv.version_number,
                   preview.rendition_public_id, preview.rendition_hash
            FROM dbo.presentation_change_requests cr
            INNER JOIN dbo.document_versions dv ON dv.version_id = cr.document_version_id
            INNER JOIN dbo.documents d ON d.document_id = dv.document_id
            OUTER APPLY
            (
                SELECT TOP (1)
                       CONVERT(varchar(36), r.public_id) AS rendition_public_id,
                       r.artifact_sha256 AS rendition_hash
                FROM dbo.presentation_change_request_items i
                INNER JOIN dbo.presentation_slides s ON s.slide_id = i.slide_id
                INNER JOIN dbo.presentation_renditions r ON r.rendition_id = s.rendition_id
                WHERE i.change_request_id = cr.change_request_id AND i.status_code = 'included'
                ORDER BY i.item_order
            ) preview
            WHERE cr.public_id = CONVERT(uniqueidentifier, ?)";
    if (!portal_is_admin($user)) {
        if (!portal_can_edit_documents($user)) {
            $sql .= " AND d.status_code = 'published'";
        }
        $sql .= " AND cr.created_by_user_id = ?";
    }
    $statement = $database->prepare($sql);
    $parameters = [$requestPublicId];
    if (!portal_is_admin($user)) {
        $parameters[] = (int) $user['user_id'];
    }
    $statement->execute($parameters);
    $request = $statement->fetch();
    if ($request === false) {
        return null;
    }
    $items = $database->prepare(
        "SELECT i.item_order, i.annotation_type_snapshot, i.geometry_json_snapshot,
                i.style_json_snapshot, i.comment_text_snapshot, i.instruction_text,
                s.slide_number
         FROM dbo.presentation_change_request_items i
         INNER JOIN dbo.presentation_slides s ON s.slide_id = i.slide_id
         WHERE i.change_request_id = ? AND i.status_code = 'included'
         ORDER BY i.item_order"
    );
    $items->execute([(int) $request['change_request_id']]);
    $request['items'] = $items->fetchAll();
    return $request;
}

function portal_presentation_markdown(array $request): string
{
    $isMarkdown = strtolower((string) ($request['document_extension'] ?? 'pptx')) === 'md';
    $lines = [
        '# TWWATER ' . ($isMarkdown ? 'Markdown 文件修改需求' : '簡報修改需求'),
        '',
        '- 需求編號：`' . (string) $request['public_id'] . '`',
        '- 文件：' . str_replace(["\r", "\n"], ' ', (string) $request['file_name']),
        '- 文件標題：' . str_replace(["\r", "\n"], ' ', (string) $request['document_title']),
        '- 原始版本：v' . (int) $request['version_number'],
        '- 原始 SHA-256：`' . (string) $request['source_content_hash'] . '`',
        ...((string) ($request['rendition_public_id'] ?? '') !== ''
            ? [
                '- 預覽版本：`' . (string) $request['rendition_public_id'] . '`',
                '- 預覽 SHA-256：`' . (string) $request['rendition_hash'] . '`',
            ]
            : []),
        '- 需求狀態：`' . (string) $request['status_code'] . '`',
        '',
        '## 整體要求',
        '',
        trim((string) ($request['request_text'] ?? '')) !== ''
            ? trim((string) $request['request_text'])
            : ($isMarkdown
                ? '請依下列標注修改 Markdown 文件，維持未指定區域的原始內容與結構。'
                : '請依下列標注修改簡報，維持未指定區域的原始版面與樣式。'),
        '',
        '## 標注明細',
        '',
    ];
    foreach ((array) $request['items'] as $item) {
        $geometry = json_decode((string) $item['geometry_json_snapshot'], true);
        $geometry = is_array($geometry) ? $geometry : [];
        $x = number_format((float) ($geometry['x'] ?? 0), 6, '.', '');
        $y = number_format((float) ($geometry['y'] ?? 0), 6, '.', '');
        $width = number_format((float) ($geometry['width'] ?? 0), 6, '.', '');
        $height = number_format((float) ($geometry['height'] ?? 0), 6, '.', '');
        $lines[] = '### A' . (int) $item['item_order'] . '－第 ' . (int) $item['slide_number'] . ' 頁';
        $lines[] = '';
        $lines[] = '- 類型：`' . (string) $item['annotation_type_snapshot'] . '`';
        $lines[] = '- 正規化範圍：`x=' . $x . ', y=' . $y . ', w=' . $width . ', h=' . $height . '`';
        if ((string) $item['annotation_type_snapshot'] === 'arrow'
            && isset($geometry['points']) && is_array($geometry['points']) && count($geometry['points']) >= 2) {
            $first = $geometry['points'][0];
            $last = $geometry['points'][count($geometry['points']) - 1];
            $lines[] = '- 箭頭端點：`(' . number_format((float) ($first['x'] ?? 0), 6, '.', '')
                . ', ' . number_format((float) ($first['y'] ?? 0), 6, '.', '') . ') → ('
                . number_format((float) ($last['x'] ?? 0), 6, '.', '') . ', '
                . number_format((float) ($last['y'] ?? 0), 6, '.', '') . ')`';
        }
        if ((string) $item['annotation_type_snapshot'] === 'text' && array_key_exists('text', $geometry)) {
            $lines[] = '- 標注文字：' . str_replace(["\r", "\n"], ' ', (string) $geometry['text']);
        }
        if ((string) $item['annotation_type_snapshot'] === 'marker' && isset($geometry['number'])) {
            $lines[] = '- 標注編號：' . (int) $geometry['number'];
        }
        $lines[] = '- 修改指示：' . str_replace(["\r", "\n"], ' ', (string) $item['instruction_text']);
        $lines[] = '';
    }
    $lines[] = '## 交付要求';
    $lines[] = '';
    $lines[] = '1. 請以指定 SHA-256 所對應的原始 ' . ($isMarkdown ? 'Markdown' : 'PPTX') . ' 為基礎。';
    $lines[] = '2. 產生新檔／新版本，不要覆寫原始檔。';
    $lines[] = $isMarkdown
        ? '3. 回傳新版本 `.md`，並逐項說明 A1、A2…的處理結果。'
        : '3. 回傳修改後 PPTX，並逐項說明 A1、A2…的處理結果。';
    return implode("\r\n", $lines) . "\r\n";
}

function portal_handle_presentation_request_export(PDO $database, array $user, string $requestPublicId): never
{
    try {
        portal_presentation_assert_method('GET');
        $request = portal_presentation_change_request($database, $user, $requestPublicId);
        if ($request === null) {
            throw new PortalPresentationHttpException('找不到修改需求或沒有權限。', 404);
        }
        $markdown = portal_presentation_markdown($request);
        portal_document_audit(
            $database,
            'presentation_request_export',
            'accepted',
            (int) $user['user_id'],
            (int) $request['document_id'],
            'request=' . (string) $request['public_id']
        );
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Length: ' . strlen($markdown));
        $requestPrefix = strtolower((string) ($request['document_extension'] ?? 'pptx')) === 'md'
            ? 'TWWATER-MD-REQ-'
            : 'TWWATER-PPT-REQ-';
        header('Content-Disposition: attachment; filename="' . $requestPrefix . rawurlencode((string) $request['public_id']) . '.md"');
        header('Cache-Control: private, no-store, max-age=0');
        echo $markdown;
        exit;
    } catch (PortalPresentationHttpException $exception) {
        http_response_code($exception->statusCode);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $exception->getMessage();
        exit;
    } catch (Throwable $exception) {
        error_log('TWWATER presentation request export failed: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '修改需求暫時無法匯出。';
        exit;
    }
}
