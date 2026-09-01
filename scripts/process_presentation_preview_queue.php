<?php
declare(strict_types=1);

/*
 * Claims one SQL Server presentation-preview job, invokes the isolated
 * PHP converter, validates its immutable manifest, and records the
 * rendition. It is launched asynchronously by the IIS AppPool after a
 * loopback-only, ACL-protected wake signal. No secret is accepted as an
 * argument or printed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$queueArguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
$queueQuiet = false;
$queueHealthCheck = false;
foreach ($queueArguments as $queueArgument) {
    if ($queueArgument === '--quiet') {
        $queueQuiet = true;
        continue;
    }
    if ($queueArgument === '--health-check') {
        $queueHealthCheck = true;
        continue;
    }
    fwrite(STDERR, '{"status":"failed","code":"INVALID_ARGUMENT"}' . PHP_EOL);
    exit(2);
}

function queue_output(array $payload, int $exitCode = 0): never
{
    global $queueQuiet;
    if (!$queueQuiet) {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }
    exit($exitCode);
}

function queue_safe_root(string $value, string $label): string
{
    if (trim($value) === '') {
        throw new RuntimeException($label . '_UNAVAILABLE');
    }
    $root = realpath($value);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException($label . '_UNAVAILABLE');
    }
    return rtrim($root, '\\/');
}

function queue_claim_job(PDO $database, string $leaseOwner): ?array
{
    $statement = $database->prepare(
        ";WITH candidate AS
         (
             SELECT TOP (1) *
             FROM dbo.presentation_preview_jobs WITH (UPDLOCK, READPAST, ROWLOCK)
             WHERE
               (
                   (status_code = 'queued' AND attempt_count < max_attempts AND available_at <= SYSUTCDATETIME())
                   OR
                   (status_code = 'processing' AND attempt_count <= max_attempts
                    AND lease_expires_at < SYSUTCDATETIME())
               )
             ORDER BY priority DESC, available_at, preview_job_id
         )
         UPDATE candidate
         SET status_code = 'processing',
             attempt_count = CASE
                 WHEN status_code = 'processing' AND attempt_count = max_attempts THEN attempt_count
                 ELSE attempt_count + 1
             END,
             started_at = COALESCE(started_at, SYSUTCDATETIME()),
             completed_at = NULL,
             lease_owner = ?,
             lease_expires_at = DATEADD(MINUTE, 15, SYSUTCDATETIME()),
             error_code = NULL,
             error_message = NULL,
             updated_at = SYSUTCDATETIME()
         OUTPUT INSERTED.preview_job_id, INSERTED.document_version_id,
                INSERTED.attempt_count, INSERTED.max_attempts"
    );
    $statement->execute([$leaseOwner]);
    $job = $statement->fetch();
    return $job === false ? null : $job;
}

function queue_job_source(PDO $database, int $jobId, int $versionId): array
{
    $statement = $database->prepare(
        "SELECT j.preview_job_id, v.version_id, v.source_content_hash,
                v.source_relative_path, v.source_file_size_bytes,
                d.document_id, d.extension
         FROM dbo.presentation_preview_jobs j
         INNER JOIN dbo.document_versions v ON v.version_id = j.document_version_id
         INNER JOIN dbo.documents d ON d.document_id = v.document_id
         WHERE j.preview_job_id = ? AND v.version_id = ?"
    );
    $statement->execute([$jobId, $versionId]);
    $source = $statement->fetch();
    if ($source === false || (string) $source['extension'] !== 'pptx'
        || preg_match('/\A[0-9a-fA-F]{64}\z/', (string) $source['source_content_hash']) !== 1) {
        throw new RuntimeException('JOB_SOURCE_INVALID');
    }
    return $source;
}

function queue_run_worker(string $workerPath, array $arguments): array
{
    $phpCli = realpath(PHP_BINARY);
    if ($phpCli === false || !is_file($phpCli)
        || strtolower((string) pathinfo($phpCli, PATHINFO_BASENAME)) !== 'php.exe') {
        throw new RuntimeException('PHP_CLI_UNAVAILABLE');
    }
    $command = array_merge([
        $phpCli,
        '-d', 'display_errors=0',
        '-d', 'log_errors=0',
        '-f', $workerPath,
        '--',
    ], $arguments);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('WORKER_START_FAILED');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 65537);
    $stderr = stream_get_contents($pipes[2], 65537);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($stdout === false || $stderr === false || strlen($stdout) > 65536 || strlen($stderr) > 65536) {
        throw new RuntimeException('WORKER_OUTPUT_INVALID');
    }
    $summary = null;
    foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, '{')) {
            continue;
        }
        try {
            $decoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $summary = $decoded;
                break;
            }
        } catch (JsonException $exception) {
            continue;
        }
    }
    if ($summary === null) {
        throw new RuntimeException($exitCode === 0 ? 'WORKER_RESULT_INVALID' : 'PREVIEW_CONVERSION_FAILED');
    }
    if ($exitCode !== 0) {
        $code = (string) ($summary['code'] ?? '');
        throw new RuntimeException(
            preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $code) === 1
                ? $code
                : 'PREVIEW_CONVERSION_FAILED'
        );
    }
    return $summary;
}

function queue_read_manifest(string $previewRoot, string $sourceHash): array
{
    $hash = strtolower($sourceHash);
    $relativeGeneration = substr($hash, 0, 2) . '/' . $hash;
    $generationRoot = realpath($previewRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeGeneration));
    $previewPrefix = strtolower(rtrim($previewRoot, '\\/') . DIRECTORY_SEPARATOR);
    if ($generationRoot === false || !str_starts_with(strtolower($generationRoot), $previewPrefix)) {
        throw new RuntimeException('PREVIEW_GENERATION_INVALID');
    }
    $manifestPath = $generationRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $pdfPath = $generationRoot . DIRECTORY_SEPARATOR . 'preview.pdf';
    if (!is_file($manifestPath) || !is_file($pdfPath)) {
        throw new RuntimeException('PREVIEW_ARTIFACT_MISSING');
    }
    $manifestRaw = file_get_contents($manifestPath);
    if ($manifestRaw === false || strlen($manifestRaw) > 65536) {
        throw new RuntimeException('PREVIEW_MANIFEST_INVALID');
    }
    try {
        $manifest = json_decode($manifestRaw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('PREVIEW_MANIFEST_INVALID');
    }
    if (!is_array($manifest)
        || (int) ($manifest['schemaVersion'] ?? 0) !== 1
        || (string) ($manifest['kind'] ?? '') !== 'TWWATER_PRESENTATION_PREVIEW'
        || !hash_equals($hash, strtolower((string) ($manifest['sourceSha256'] ?? '')))
        || (string) ($manifest['pdfFile'] ?? '') !== 'preview.pdf'
        || (int) ($manifest['slideCount'] ?? 0) < 1
        || (int) ($manifest['slideCount'] ?? 0) > 10000) {
        throw new RuntimeException('PREVIEW_MANIFEST_INVALID');
    }
    $pdfHash = hash_file('sha256', $pdfPath);
    $manifestHash = hash_file('sha256', $manifestPath);
    if ($pdfHash === false || $manifestHash === false
        || !hash_equals(strtolower((string) ($manifest['pdfSha256'] ?? '')), strtolower($pdfHash))) {
        throw new RuntimeException('PREVIEW_HASH_MISMATCH');
    }
    return [
        'manifest' => $manifest,
        'pdf_relative_path' => $relativeGeneration . '/preview.pdf',
        'manifest_relative_path' => $relativeGeneration . '/manifest.json',
        'pdf_hash' => strtolower($pdfHash),
        'manifest_hash' => strtolower($manifestHash),
        'pdf_size' => (int) filesize($pdfPath),
    ];
}

function queue_record_success(PDO $database, array $job, array $source, array $artifact, string $leaseOwner): void
{
    $manifest = $artifact['manifest'];
    $database->beginTransaction();
    try {
        /*
         * Lock and verify the claimed row before touching rendition state. A
         * conversion can finish after its lease expired and another worker
         * reclaimed the job; that stale worker must not publish artifacts for
         * the new claimant.
         */
        $claim = $database->prepare(
            "SELECT preview_job_id
             FROM dbo.presentation_preview_jobs WITH (UPDLOCK, HOLDLOCK)
             WHERE preview_job_id = ? AND status_code = 'processing' AND lease_owner = ?"
        );
        $claim->execute([(int) $job['preview_job_id'], $leaseOwner]);
        if ($claim->fetchColumn() === false) {
            throw new RuntimeException('JOB_LEASE_LOST');
        }
        $stale = $database->prepare(
            "UPDATE dbo.presentation_renditions
             SET status_code = 'stale', is_current = 0, updated_at = SYSUTCDATETIME()
             WHERE document_version_id = ? AND rendition_kind = 'pdf'
               AND artifact_sha256 <> ? AND is_current = 1"
        );
        $stale->execute([(int) $source['version_id'], (string) $artifact['pdf_hash']]);
        $find = $database->prepare(
            "SELECT rendition_id FROM dbo.presentation_renditions
             WHERE document_version_id = ? AND rendition_kind = 'pdf' AND artifact_sha256 = ?"
        );
        $find->execute([(int) $source['version_id'], (string) $artifact['pdf_hash']]);
        $renditionId = $find->fetchColumn();
        if ($renditionId === false) {
            $insert = $database->prepare(
                "INSERT INTO dbo.presentation_renditions
                    (document_version_id, preview_job_id, rendition_kind, status_code,
                     storage_relative_path, manifest_relative_path, mime_type, artifact_sha256,
                     manifest_sha256, file_size_bytes, page_count, generator_name,
                     generator_version, is_current)
                 VALUES (?, ?, 'pdf', 'ready', ?, ?, 'application/pdf', ?, ?, ?, ?, ?, ?, 1)"
            );
            $insert->execute([
                (int) $source['version_id'],
                (int) $job['preview_job_id'],
                (string) $artifact['pdf_relative_path'],
                (string) $artifact['manifest_relative_path'],
                (string) $artifact['pdf_hash'],
                (string) $artifact['manifest_hash'],
                (int) $artifact['pdf_size'],
                (int) $manifest['slideCount'],
                portal_trim_text((string) ($manifest['converter'] ?? 'LibreOffice'), 100),
                portal_trim_text((string) ($manifest['converterVersion'] ?? ''), 64),
            ]);
            $find->execute([(int) $source['version_id'], (string) $artifact['pdf_hash']]);
            $renditionId = $find->fetchColumn();
        } else {
            $refresh = $database->prepare(
                "UPDATE dbo.presentation_renditions
                 SET preview_job_id = ?, status_code = 'ready', storage_relative_path = ?,
                     manifest_relative_path = ?, manifest_sha256 = ?, is_current = 1,
                     updated_at = SYSUTCDATETIME()
                 WHERE rendition_id = ?"
            );
            $refresh->execute([
                (int) $job['preview_job_id'],
                (string) $artifact['pdf_relative_path'],
                (string) $artifact['manifest_relative_path'],
                (string) $artifact['manifest_hash'],
                (int) $renditionId,
            ]);
        }
        $renditionId = (int) $renditionId;
        if ($renditionId < 1) {
            throw new RuntimeException('RENDITION_RECORD_FAILED');
        }
        $countSlides = $database->prepare('SELECT COUNT(*) FROM dbo.presentation_slides WHERE rendition_id = ?');
        $countSlides->execute([$renditionId]);
        $existingSlides = (int) $countSlides->fetchColumn();
        $slideCount = (int) $manifest['slideCount'];
        if ($existingSlides === 0) {
            $insertSlide = $database->prepare(
                'INSERT INTO dbo.presentation_slides (rendition_id, document_version_id, slide_number) VALUES (?, ?, ?)'
            );
            for ($slideNumber = 1; $slideNumber <= $slideCount; $slideNumber++) {
                $insertSlide->execute([$renditionId, (int) $source['version_id'], $slideNumber]);
            }
        } elseif ($existingSlides !== $slideCount) {
            throw new RuntimeException('SLIDE_COUNT_CONFLICT');
        }
        $complete = $database->prepare(
            "UPDATE dbo.presentation_preview_jobs
             SET status_code = 'succeeded', completed_at = SYSUTCDATETIME(),
                 lease_owner = NULL, lease_expires_at = NULL, error_code = NULL,
                 error_message = NULL, updated_at = SYSUTCDATETIME()
             WHERE preview_job_id = ? AND status_code = 'processing' AND lease_owner = ?"
        );
        $complete->execute([(int) $job['preview_job_id'], $leaseOwner]);
        if ($complete->rowCount() !== 1) {
            throw new RuntimeException('JOB_LEASE_LOST');
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function queue_record_failure(PDO $database, array $job, string $safeCode, string $leaseOwner): void
{
    $safeCode = preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $safeCode) === 1
        ? $safeCode
        : 'PREVIEW_JOB_FAILED';
    $retry = (int) $job['attempt_count'] < (int) $job['max_attempts'];
    $statement = $database->prepare(
        "UPDATE dbo.presentation_preview_jobs
         SET status_code = ?,
             available_at = CASE WHEN ? = 1 THEN DATEADD(MINUTE, ?, SYSUTCDATETIME()) ELSE available_at END,
             completed_at = CASE WHEN ? = 1 THEN NULL ELSE SYSUTCDATETIME() END,
             lease_owner = NULL, lease_expires_at = NULL,
             error_code = ?, error_message = NULL, updated_at = SYSUTCDATETIME()
         WHERE preview_job_id = ? AND status_code = 'processing' AND lease_owner = ?"
    );
    $delayMinutes = min(60, 2 ** max(0, (int) $job['attempt_count'] - 1));
    $statement->execute([
        $retry ? 'queued' : 'failed',
        $retry ? 1 : 0,
        $delayMinutes,
        $retry ? 1 : 0,
        $safeCode,
        (int) $job['preview_job_id'],
        $leaseOwner,
    ]);
}

$job = null;
try {
    $config = portal_config();
    if (!$config['ready']) {
        throw new RuntimeException('PORTAL_ENVIRONMENT_NOT_READY');
    }
    if (!portal_presentation_schema_available($database = portal_open_database($config))) {
        throw new RuntimeException('PRESENTATION_SCHEMA_NOT_READY');
    }
    $sourceRoot = queue_safe_root((string) $config['document_root'], 'SOURCE_ROOT');
    $previewRoot = queue_safe_root((string) ($config['presentation_preview_root'] ?? ''), 'PREVIEW_ROOT');
    $runtimeValue = getenv('PORTAL_PRESENTATION_RUNTIME_ROOT');
    $runtimeConfigured = $runtimeValue === false ? '' : trim((string) $runtimeValue);
    if ($runtimeConfigured === '') {
        $runtimeConfigured = dirname((string) $config['session_save_path']) . DIRECTORY_SEPARATOR . 'presentation-preview';
    }
    $runtimeRoot = queue_safe_root($runtimeConfigured, 'RUNTIME_ROOT');
    if (!is_readable($sourceRoot)) {
        throw new RuntimeException('SOURCE_ROOT_NOT_READABLE');
    }
    if (!is_writable($previewRoot)) {
        throw new RuntimeException('PREVIEW_ROOT_NOT_WRITABLE');
    }
    if (!is_writable($runtimeRoot)) {
        throw new RuntimeException('RUNTIME_ROOT_NOT_WRITABLE');
    }
    $workerPath = __DIR__ . DIRECTORY_SEPARATOR . 'presentation_preview_worker.php';
    if (!is_file($workerPath)) {
        throw new RuntimeException('WORKER_SCRIPT_MISSING');
    }
    $libreOfficeValue = getenv('PORTAL_LIBREOFFICE_PATH');
    $libreOfficePath = $libreOfficeValue === false ? '' : trim((string) $libreOfficeValue);
    if ($libreOfficePath === '' || !is_file($libreOfficePath)) {
        throw new RuntimeException('LIBREOFFICE_NOT_FOUND');
    }
    if ($queueHealthCheck) {
        queue_output(['status' => 'ready']);
    }

    $lockPath = $runtimeRoot . DIRECTORY_SEPARATOR . 'presentation-preview-queue.lock';
    $queueLock = fopen($lockPath, 'c+b');
    if ($queueLock === false) {
        throw new RuntimeException('QUEUE_LOCK_UNAVAILABLE');
    }
    if (!flock($queueLock, LOCK_EX | LOCK_NB)) {
        fclose($queueLock);
        queue_output(['status' => 'busy']);
    }
    register_shutdown_function(static function () use ($queueLock): void {
        flock($queueLock, LOCK_UN);
        fclose($queueLock);
    });

    $leaseOwner = portal_trim_text((string) gethostname() . ':presentation-preview:' . getmypid(), 128);
    $job = queue_claim_job($database, $leaseOwner);
    if ($job === null) {
        queue_output(['status' => 'idle']);
    }
    $source = queue_job_source($database, (int) $job['preview_job_id'], (int) $job['document_version_id']);
    $workerArguments = [
        '--source-root', $sourceRoot,
        '--relative-path', (string) $source['source_relative_path'],
        '--preview-root', $previewRoot,
        '--runtime-root', $runtimeRoot,
        '--expected-source-sha256', strtolower((string) $source['source_content_hash']),
        '--emit-json',
    ];
    array_push($workerArguments, '--libreoffice-path', $libreOfficePath);
    $summary = queue_run_worker($workerPath, $workerArguments);
    if (!hash_equals(strtolower((string) $source['source_content_hash']), strtolower((string) ($summary['sourceSha256'] ?? '')))) {
        throw new RuntimeException('WORKER_SOURCE_MISMATCH');
    }
    $artifact = queue_read_manifest($previewRoot, (string) $source['source_content_hash']);
    queue_record_success($database, $job, $source, $artifact, $leaseOwner);
    queue_output([
        'status' => 'succeeded',
        'job_id' => (int) $job['preview_job_id'],
        'slide_count' => (int) $artifact['manifest']['slideCount'],
    ]);
} catch (Throwable $exception) {
    $code = preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $exception->getMessage()) === 1
        ? $exception->getMessage()
        : 'PREVIEW_JOB_FAILED';
    if ($job !== null && isset($database) && $database instanceof PDO) {
        try {
            queue_record_failure($database, $job, $code, $leaseOwner);
        } catch (Throwable $recordException) {
            $code = 'PREVIEW_JOB_STATE_FAILED';
        }
    }
    queue_output(['status' => 'failed', 'code' => $code], 1);
}
