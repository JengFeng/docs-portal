<?php
declare(strict_types=1);

/*
 * Isolated PPTX -> immutable PDF preview worker.
 *
 * This script intentionally has no portal bootstrap, database connection, or
 * secret-bearing configuration.  It is called by the queue processor under
 * the IIS AppPool identity with fixed command-line arguments only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');

const PREVIEW_KIND = 'TWWATER_PRESENTATION_PREVIEW';
const PREVIEW_SCHEMA_VERSION = 1;
const DEFAULT_TIMEOUT_SECONDS = 180;
const DEFAULT_MAX_SOURCE_BYTES = 104857600;
const DEFAULT_LOCK_TIMEOUT_SECONDS = 60;
const MAX_CONVERTER_OUTPUT_BYTES = 65536;
const MAX_MANIFEST_BYTES = 65536;

final class PreviewWorkerException extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class PreviewWorkerSuccess extends RuntimeException
{
    /** @param array<string,mixed> $payload */
    public function __construct(public readonly array $payload)
    {
        parent::__construct('PREVIEW_WORKER_SUCCESS');
    }
}

/** @return never */
function fail(string $code): never
{
    throw new PreviewWorkerException($code);
}

/** @return never */
function emit_payload(array $payload, int $exitCode): never
{
    try {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    } catch (Throwable) {
        fwrite(STDOUT, "{\"status\":\"failed\",\"code\":\"OUTPUT_SERIALIZATION_FAILED\"}\n");
    }
    exit($exitCode);
}

function safe_code(string $value, string $fallback = 'UNEXPECTED_FAILURE'): string
{
    return preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $value) === 1 ? $value : $fallback;
}

/**
 * The deployed paths are local drive paths.  Requiring this form prevents a
 * request from changing the working directory or introducing an UNC path.
 */
function normal_absolute_path(string $path, string $code): string
{
    if ($path === '' || str_contains($path, "\0")) {
        fail($code);
    }
    $path = str_replace('/', '\\', $path);
    if (preg_match('/\A([A-Za-z]:)\\\\(.*)\z/', $path, $match) !== 1) {
        fail($code);
    }

    $segments = preg_split('/\\\\+/', $match[2]) ?: [];
    $normal = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..' || str_contains($segment, ':')) {
            fail($code);
        }
        $normal[] = $segment;
    }
    return strtoupper($match[1]) . '\\' . implode('\\', $normal);
}

function trim_path(string $path): string
{
    return rtrim($path, "\\/");
}

function path_equals(string $first, string $second): bool
{
    return strcasecmp(trim_path($first), trim_path($second)) === 0;
}

function path_is_within(string $parent, string $candidate): bool
{
    $parent = trim_path($parent);
    $candidate = trim_path($candidate);
    return str_starts_with(strtolower($candidate), strtolower($parent . '\\'));
}

/**
 * PHP reports Windows symlinks and junctions as links on supported builds.
 * lstat is retained as a second test so a link is never silently treated as a
 * normal directory or file on a build where filetype differs from is_link.
 */
function is_reparse_point(string $path): bool
{
    if (@is_link($path)) {
        return true;
    }
    $stat = @lstat($path);
    if ($stat === false) {
        return true;
    }
    return (($stat['mode'] ?? 0) & 0170000) === 0120000;
}

function assert_existing_regular_directory(string $path, string $code): string
{
    $lexical = normal_absolute_path($path, $code);
    if (!@is_dir($lexical) || is_reparse_point($lexical)) {
        fail($code);
    }
    $resolved = @realpath($lexical);
    if ($resolved === false || !@is_dir($resolved) || is_reparse_point($resolved)) {
        fail($code);
    }
    return trim_path($resolved);
}

function ensure_regular_directory(string $path, string $code): string
{
    $lexical = normal_absolute_path($path, $code);
    if (@file_exists($lexical) || @is_link($lexical)) {
        return assert_existing_regular_directory($lexical, $code);
    }
    if (!@mkdir($lexical, 0770, true) && !@is_dir($lexical)) {
        fail($code);
    }
    return assert_existing_regular_directory($lexical, $code);
}

function assert_separate_directory_trees(string $first, string $second, string $code): void
{
    if (path_equals($first, $second) || path_is_within($first, $second) || path_is_within($second, $first)) {
        fail($code);
    }
}

function safe_relative_pptx_path(string $value): string
{
    if ($value === '' || str_contains($value, "\0") || preg_match('/\A(?:[A-Za-z]:|[\\\\\/])/', $value) === 1) {
        fail('INVALID_RELATIVE_PATH');
    }
    $segments = preg_split('/[\\\\\/]+/', $value) ?: [];
    if ($segments === []) {
        fail('INVALID_RELATIVE_PATH');
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
            fail('INVALID_RELATIVE_PATH');
        }
    }
    $normal = implode('\\', $segments);
    if (strcasecmp((string) pathinfo($normal, PATHINFO_EXTENSION), 'pptx') !== 0) {
        fail('UNSUPPORTED_SOURCE_TYPE');
    }
    if (str_starts_with((string) pathinfo($normal, PATHINFO_BASENAME), '~$')) {
        fail('OFFICE_TEMPORARY_FILE');
    }
    return $normal;
}

function assert_source_path_chain(string $sourceRoot, string $relativePath): string
{
    $candidate = normal_absolute_path($sourceRoot . '\\' . $relativePath, 'SOURCE_PATH_ESCAPE');
    if (!path_is_within($sourceRoot, $candidate)) {
        fail('SOURCE_PATH_ESCAPE');
    }
    $current = trim_path($sourceRoot);
    foreach (explode('\\', $relativePath) as $segment) {
        $current .= '\\' . $segment;
        if (!@file_exists($current)) {
            fail('SOURCE_NOT_FOUND');
        }
        if (is_reparse_point($current)) {
            fail('SOURCE_REPARSE_POINT');
        }
    }
    $resolved = @realpath($candidate);
    if ($resolved === false || !path_is_within($sourceRoot, $resolved) || is_reparse_point($resolved)) {
        fail('SOURCE_PATH_ESCAPE');
    }
    if (!@is_file($resolved) || @is_dir($resolved)) {
        fail('SOURCE_NOT_FILE');
    }
    return $resolved;
}

/** @return array{sourceSha256:string,sourceSizeBytes:int,sourceLastWriteUtc:string} */
function copy_stable_source_snapshot(string $sourcePath, string $snapshotPath, int $maximumBytes): array
{
    $source = @fopen($sourcePath, 'rb');
    if (!is_resource($source)) {
        fail('SOURCE_SNAPSHOT_FAILED');
    }
    $target = null;
    try {
        $before = @fstat($source);
        if (!is_array($before)) {
            fail('SOURCE_SNAPSHOT_FAILED');
        }
        $sourceSize = (int) ($before['size'] ?? 0);
        if ($sourceSize <= 0 || $sourceSize > $maximumBytes) {
            fail('SOURCE_SIZE_REJECTED');
        }
        $target = @fopen($snapshotPath, 'x+b');
        if (!is_resource($target)) {
            fail('SOURCE_SNAPSHOT_FAILED');
        }
        $hash = hash_init('sha256');
        $copied = 0;
        while (!feof($source)) {
            $chunk = fread($source, 1048576);
            if ($chunk === false) {
                fail('SOURCE_SNAPSHOT_FAILED');
            }
            if ($chunk === '') {
                continue;
            }
            $copied += strlen($chunk);
            if ($copied > $maximumBytes || !hash_update($hash, $chunk) || !write_all($target, $chunk)) {
                fail('SOURCE_SNAPSHOT_FAILED');
            }
        }
        if (!fflush($target)) {
            fail('SOURCE_SNAPSHOT_FAILED');
        }
        $after = @fstat($source);
        if (!is_array($after)
            || $copied !== $sourceSize
            || (int) ($after['size'] ?? -1) !== $sourceSize
            || (int) ($after['mtime'] ?? -1) !== (int) ($before['mtime'] ?? -2)) {
            fail('SOURCE_SNAPSHOT_UNSTABLE');
        }
        $digest = hash_final($hash);
        if (!is_string($digest) || preg_match('/\A[0-9a-f]{64}\z/', $digest) !== 1) {
            fail('SOURCE_SNAPSHOT_FAILED');
        }
        fclose($target);
        $target = null;
        @chmod($snapshotPath, 0444);
        return [
            'sourceSha256' => $digest,
            'sourceSizeBytes' => $sourceSize,
            'sourceLastWriteUtc' => gmdate('Y-m-d\\TH:i:s\\Z', (int) ($before['mtime'] ?? time())),
        ];
    } finally {
        if (is_resource($target)) {
            fclose($target);
        }
        fclose($source);
    }
}

function write_all($stream, string $value): bool
{
    $offset = 0;
    $length = strlen($value);
    while ($offset < $length) {
        $written = @fwrite($stream, substr($value, $offset));
        if (!is_int($written) || $written < 1) {
            return false;
        }
        $offset += $written;
    }
    return true;
}

function pptx_slide_count(string $snapshotPath): int
{
    if (!class_exists('ZipArchive')) {
        fail('PPTX_ZIP_UNAVAILABLE');
    }
    $archive = new ZipArchive();
    $open = @$archive->open($snapshotPath, ZipArchive::RDONLY);
    if ($open !== true) {
        fail('PPTX_STRUCTURE_INVALID');
    }
    try {
        $slides = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (is_string($name) && preg_match('/\Appt\/slides\/slide[1-9][0-9]*\.xml\z/', $name) === 1) {
                $slides++;
            }
        }
        if ($slides < 1 || $slides > 10000) {
            fail('PPTX_STRUCTURE_INVALID');
        }
        return $slides;
    } finally {
        $archive->close();
    }
}

/** @return resource */
function acquire_generation_lock(string $lockPath, int $timeoutSeconds)
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $lock = @fopen($lockPath, 'c+b');
        if (is_resource($lock)) {
            if (@flock($lock, LOCK_EX | LOCK_NB)) {
                return $lock;
            }
            fclose($lock);
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    fail('GENERATION_LOCK_TIMEOUT');
}

function pdf_header_valid(string $path): bool
{
    if (!@is_file($path) || is_reparse_point($path)) {
        return false;
    }
    $stream = @fopen($path, 'rb');
    if (!is_resource($stream)) {
        return false;
    }
    try {
        $stat = @fstat($stream);
        if (!is_array($stat) || (int) ($stat['size'] ?? 0) < 8) {
            return false;
        }
        return fread($stream, 5) === '%PDF-';
    } finally {
        fclose($stream);
    }
}

function file_sha256(string $path, string $code): string
{
    if (!@is_file($path) || is_reparse_point($path)) {
        fail($code);
    }
    $hash = @hash_file('sha256', $path);
    if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
        fail($code);
    }
    return $hash;
}

/** @return array<string,mixed>|null */
function existing_generation(string $generationRoot, string $sourceSha256): ?array
{
    if (!@file_exists($generationRoot) && !@is_link($generationRoot)) {
        return null;
    }
    if (!@is_dir($generationRoot) || is_reparse_point($generationRoot)) {
        fail('GENERATION_CONFLICT');
    }
    $manifestPath = $generationRoot . '\\manifest.json';
    $pdfPath = $generationRoot . '\\preview.pdf';
    if (!@is_file($manifestPath) || !@is_file($pdfPath)
        || is_reparse_point($manifestPath) || is_reparse_point($pdfPath)) {
        fail('GENERATION_CONFLICT');
    }
    $raw = @file_get_contents($manifestPath, false, null, 0, MAX_MANIFEST_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > MAX_MANIFEST_BYTES) {
        fail('GENERATION_CONFLICT');
    }
    try {
        $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('GENERATION_CONFLICT');
    }
    if (!is_array($manifest)
        || (string) ($manifest['kind'] ?? '') !== PREVIEW_KIND
        || (int) ($manifest['schemaVersion'] ?? 0) !== PREVIEW_SCHEMA_VERSION
        || !hash_equals($sourceSha256, strtolower((string) ($manifest['sourceSha256'] ?? '')))
        || (string) ($manifest['pdfFile'] ?? '') !== 'preview.pdf'
        || (int) ($manifest['slideCount'] ?? 0) < 1
        || (int) ($manifest['slideCount'] ?? 0) > 10000
        || preg_match('/\A[0-9a-f]{64}\z/', (string) ($manifest['pdfSha256'] ?? '')) !== 1
        || !pdf_header_valid($pdfPath)) {
        fail('GENERATION_CONFLICT');
    }
    $actual = file_sha256($pdfPath, 'GENERATION_CONFLICT');
    if (!hash_equals(strtolower((string) $manifest['pdfSha256']), $actual)) {
        fail('GENERATION_CONFLICT');
    }
    return $manifest;
}

function file_uri(string $path): string
{
    $path = str_replace('\\', '/', normal_absolute_path($path, 'PROFILE_ROOT_INVALID'));
    $parts = explode('/', $path);
    foreach ($parts as $index => $part) {
        $parts[$index] = rawurlencode($part);
    }
    if (isset($parts[0])) {
        $parts[0] = str_replace('%3A', ':', $parts[0]);
    }
    return 'file:///' . implode('/', $parts);
}

/** @return array{exitCode:int,timedOut:bool,outputExceeded:bool} */
function run_limited_process(array $command, string $workingDirectory, int $timeoutSeconds): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptorSpec, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        fail('LIBREOFFICE_START_FAILED');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = microtime(true);
    $outputSize = 0;
    $timedOut = false;
    $outputExceeded = false;
    $exitCode = -1;
    try {
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready !== false) {
                foreach ($read as $stream) {
                    $chunk = @fread($stream, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $outputSize += strlen($chunk);
                    }
                }
            }
            $status = proc_get_status($process);
            if (!is_array($status)) {
                $timedOut = true;
                break;
            }
            if ($outputSize > MAX_CONVERTER_OUTPUT_BYTES) {
                $outputExceeded = true;
                break;
            }
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) - $started > $timeoutSeconds) {
                $timedOut = true;
                break;
            }
        }
        if ($timedOut || $outputExceeded) {
            $status = proc_get_status($process);
            $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
            if ($pid > 0) {
                terminate_owned_process_tree($pid);
            }
            @proc_terminate($process, 9);
        }
    } finally {
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                $tail = @stream_get_contents($pipes[$index], MAX_CONVERTER_OUTPUT_BYTES + 1);
                if (is_string($tail)) {
                    $outputSize += strlen($tail);
                    if ($outputSize > MAX_CONVERTER_OUTPUT_BYTES) {
                        $outputExceeded = true;
                    }
                }
                fclose($pipes[$index]);
            }
        }
        $closeCode = @proc_close($process);
        if ($exitCode < 0 && is_int($closeCode)) {
            $exitCode = $closeCode;
        }
    }
    return ['exitCode' => $exitCode, 'timedOut' => $timedOut, 'outputExceeded' => $outputExceeded];
}

function terminate_owned_process_tree(int $pid): void
{
    $taskKill = getenv('SystemRoot');
    $taskKill = is_string($taskKill) && $taskKill !== ''
        ? rtrim($taskKill, '\\/') . '\\System32\\taskkill.exe'
        : 'C:\\Windows\\System32\\taskkill.exe';
    if (!@is_file($taskKill)) {
        return;
    }
    $pipes = [];
    $process = @proc_open([$taskKill, '/PID', (string) $pid, '/T', '/F'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return;
    }
    foreach ($pipes as $index => $pipe) {
        if (is_resource($pipe)) {
            if ($index !== 0) {
                @stream_get_contents($pipe, 4096);
            }
            fclose($pipe);
        }
    }
    @proc_close($process);
}

function write_private_file(string $path, string $contents, string $code, bool $makeReadOnly = true): void
{
    $stream = @fopen($path, 'xb');
    if (!is_resource($stream)) {
        fail($code);
    }
    try {
        if (!write_all($stream, $contents) || !fflush($stream)) {
            fail($code);
        }
    } finally {
        fclose($stream);
    }
    if ($makeReadOnly) {
        @chmod($path, 0444);
    }
}

function safe_remove_tree(?string $path, ?string $allowedParent): void
{
    if ($path === null || $allowedParent === null || !path_is_within($allowedParent, $path)) {
        return;
    }
    if (!@file_exists($path) && !@is_link($path)) {
        return;
    }
    if (is_reparse_point($path)) {
        return;
    }
    if (@is_dir($path)) {
        $children = @scandir($path);
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    safe_remove_tree($path . '\\' . $child, $allowedParent);
                }
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function write_safe_failure_diagnostic(?string $runtimeRoot, string $jobId, string $code, string $stage, ?int $libreOfficeExitCode, Throwable $exception): void
{
    if ($runtimeRoot === null) {
        return;
    }
    try {
        $errors = ensure_regular_directory($runtimeRoot . '\\errors', 'ERROR_LOG_DIRECTORY_INVALID');
        $payload = [
            'schemaVersion' => 1,
            'kind' => 'TWWATER_PRESENTATION_PREVIEW_FAILURE',
            'jobId' => $jobId,
            'code' => safe_code($code),
            'stage' => preg_replace('/[^a-z_]/', '', $stage) ?: 'unknown',
            'exceptionType' => substr((new ReflectionClass($exception))->getShortName(), 0, 80),
            'libreOfficeExitCode' => $libreOfficeExitCode,
            'failedUtc' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ];
        $temporary = $errors . '\\.' . $jobId . '.tmp';
        $destination = $errors . '\\' . $jobId . '.json';
        write_private_file($temporary, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'ERROR_LOG_WRITE_FAILED', false);
        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
        }
    } catch (Throwable) {
        // Diagnostics must not replace the safe worker failure.
    }
}

/** @return array{sourceRoot:string,relativePath:string,previewRoot:string,runtimeRoot:string,expectedSourceSha256:string,libreOfficePath:string,timeoutSeconds:int,maxSourceBytes:int,lockTimeoutSeconds:int} */
function parse_arguments(array $argv): array
{
    $values = [];
    $emitJsonSeen = false;
    $valueOptions = [
        '--source-root' => 'sourceRoot',
        '--relative-path' => 'relativePath',
        '--preview-root' => 'previewRoot',
        '--runtime-root' => 'runtimeRoot',
        '--expected-source-sha256' => 'expectedSourceSha256',
        '--libreoffice-path' => 'libreOfficePath',
        '--timeout-seconds' => 'timeoutSeconds',
        '--max-source-bytes' => 'maxSourceBytes',
        '--lock-timeout-seconds' => 'lockTimeoutSeconds',
    ];
    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = $argv[$index];
        if ($argument === '--' && $index === 1) {
            continue;
        }
        if ($argument === '--emit-json') {
            if ($emitJsonSeen) {
                fail('INVALID_ARGUMENT');
            }
            $emitJsonSeen = true;
            continue;
        }
        if (!isset($valueOptions[$argument]) || $index + 1 >= $count) {
            fail('INVALID_ARGUMENT');
        }
        $key = $valueOptions[$argument];
        $value = $argv[++$index];
        if (isset($values[$key]) || !is_string($value) || $value === '' || str_starts_with($value, '--')) {
            fail('INVALID_ARGUMENT');
        }
        $values[$key] = $value;
    }
    foreach (['sourceRoot', 'relativePath', 'previewRoot', 'runtimeRoot', 'libreOfficePath'] as $required) {
        if (!isset($values[$required])) {
            fail('INVALID_ARGUMENT');
        }
    }
    $expected = strtolower((string) ($values['expectedSourceSha256'] ?? ''));
    if ($expected !== '' && preg_match('/\A[0-9a-f]{64}\z/', $expected) !== 1) {
        fail('INVALID_ARGUMENT');
    }
    $timeout = isset($values['timeoutSeconds']) ? filter_var($values['timeoutSeconds'], FILTER_VALIDATE_INT) : DEFAULT_TIMEOUT_SECONDS;
    $maxBytes = isset($values['maxSourceBytes']) ? filter_var($values['maxSourceBytes'], FILTER_VALIDATE_INT) : DEFAULT_MAX_SOURCE_BYTES;
    $lockTimeout = isset($values['lockTimeoutSeconds']) ? filter_var($values['lockTimeoutSeconds'], FILTER_VALIDATE_INT) : DEFAULT_LOCK_TIMEOUT_SECONDS;
    if (!is_int($timeout) || $timeout < 10 || $timeout > 1800
        || !is_int($maxBytes) || $maxBytes < 1048576 || $maxBytes > 1073741824
        || !is_int($lockTimeout) || $lockTimeout < 1 || $lockTimeout > 600) {
        fail('INVALID_ARGUMENT');
    }
    return [
        'sourceRoot' => (string) $values['sourceRoot'],
        'relativePath' => (string) $values['relativePath'],
        'previewRoot' => (string) $values['previewRoot'],
        'runtimeRoot' => (string) $values['runtimeRoot'],
        'expectedSourceSha256' => $expected,
        'libreOfficePath' => (string) $values['libreOfficePath'],
        'timeoutSeconds' => $timeout,
        'maxSourceBytes' => $maxBytes,
        'lockTimeoutSeconds' => $lockTimeout,
    ];
}

$jobId = str_repeat('0', 32);
$stage = 'startup';
$runtimeRoot = null;
$previewRoot = null;
$jobRoot = null;
$stagingRoot = null;
$lock = null;
$libreOfficeExitCode = null;
$resultPayload = ['status' => 'failed', 'code' => 'UNEXPECTED_FAILURE', 'jobId' => $jobId];
$resultExitCode = 1;

try {
    $jobId = bin2hex(random_bytes(16));
    $arguments = parse_arguments($argv);
    $stage = 'validate_paths';
    $relativePath = safe_relative_pptx_path($arguments['relativePath']);
    $sourceRoot = assert_existing_regular_directory($arguments['sourceRoot'], 'SOURCE_ROOT_INVALID');
    $previewRoot = ensure_regular_directory($arguments['previewRoot'], 'PREVIEW_ROOT_INVALID');
    $runtimeRoot = ensure_regular_directory($arguments['runtimeRoot'], 'RUNTIME_ROOT_INVALID');
    assert_separate_directory_trees($sourceRoot, $previewRoot, 'SOURCE_PREVIEW_OVERLAP');
    assert_separate_directory_trees($sourceRoot, $runtimeRoot, 'SOURCE_RUNTIME_OVERLAP');
    assert_separate_directory_trees($previewRoot, $runtimeRoot, 'PREVIEW_RUNTIME_OVERLAP');
    $sourcePath = assert_source_path_chain($sourceRoot, $relativePath);

    $stage = 'resolve_converter';
    $libreOfficePath = normal_absolute_path($arguments['libreOfficePath'], 'LIBREOFFICE_NOT_FOUND');
    if (!@is_file($libreOfficePath) || @is_dir($libreOfficePath) || is_reparse_point($libreOfficePath)
        || !in_array(strtolower((string) pathinfo($libreOfficePath, PATHINFO_BASENAME)), ['soffice.com', 'soffice.exe'], true)) {
        fail('LIBREOFFICE_NOT_FOUND');
    }
    $libreOfficeResolved = @realpath($libreOfficePath);
    if ($libreOfficeResolved === false || is_reparse_point($libreOfficeResolved)) {
        fail('LIBREOFFICE_NOT_FOUND');
    }
    $converterVersion = 'unknown';
    // Product version is intentionally nonessential metadata.  Do not execute
    // a second converter process merely to discover it.

    $stage = 'snapshot_source';
    $jobsRoot = ensure_regular_directory($runtimeRoot . '\\jobs', 'JOBS_ROOT_INVALID');
    $jobRoot = ensure_regular_directory($jobsRoot . '\\' . $jobId, 'JOB_ROOT_INVALID');
    $snapshotPath = $jobRoot . '\\presentation.pptx';
    $snapshot = copy_stable_source_snapshot($sourcePath, $snapshotPath, $arguments['maxSourceBytes']);
    $sourceSha256 = $snapshot['sourceSha256'];
    if ($arguments['expectedSourceSha256'] !== '' && !hash_equals($arguments['expectedSourceSha256'], $sourceSha256)) {
        fail('SOURCE_VERSION_MISMATCH');
    }
    $slideCount = pptx_slide_count($snapshotPath);

    $stage = 'acquire_generation_lock';
    $locksRoot = ensure_regular_directory($previewRoot . '\\.locks', 'LOCK_ROOT_INVALID');
    $lock = acquire_generation_lock($locksRoot . '\\' . $sourceSha256 . '.lock', $arguments['lockTimeoutSeconds']);
    $hashPrefix = ensure_regular_directory($previewRoot . '\\' . substr($sourceSha256, 0, 2), 'GENERATION_PREFIX_INVALID');
    $generationRoot = $hashPrefix . '\\' . $sourceSha256;
    $existing = existing_generation($generationRoot, $sourceSha256);
    if ($existing !== null) {
        throw new PreviewWorkerSuccess([
            'status' => 'existing',
            'jobId' => $jobId,
            'sourceSha256' => $sourceSha256,
            'slideCount' => (int) $existing['slideCount'],
            'previewGeneration' => substr($sourceSha256, 0, 2) . '/' . $sourceSha256,
            'manifestFile' => 'manifest.json',
            'pdfFile' => 'preview.pdf',
        ]);
    }

    $stage = 'convert_presentation';
    $profileRoot = ensure_regular_directory($jobRoot . '\\libreoffice-profile', 'PROFILE_ROOT_INVALID');
    $conversionRoot = ensure_regular_directory($jobRoot . '\\conversion-output', 'CONVERSION_ROOT_INVALID');
    $processResult = run_limited_process([
        $libreOfficeResolved,
        '--headless',
        '--nologo',
        '--nodefault',
        '--nolockcheck',
        '--norestore',
        '-env:UserInstallation=' . file_uri($profileRoot),
        '--convert-to',
        'pdf:impress_pdf_Export',
        '--outdir',
        $conversionRoot,
        $snapshotPath,
    ], $jobRoot, $arguments['timeoutSeconds']);
    $libreOfficeExitCode = $processResult['exitCode'];
    if ($processResult['timedOut']) {
        fail('LIBREOFFICE_TIMEOUT');
    }
    if ($processResult['outputExceeded']) {
        fail('LIBREOFFICE_OUTPUT_LIMIT');
    }
    if ($libreOfficeExitCode !== 0) {
        fail('LIBREOFFICE_CONVERSION_FAILED');
    }
    $convertedPdf = $conversionRoot . '\\presentation.pdf';
    if (!pdf_header_valid($convertedPdf)) {
        fail('PDF_OUTPUT_INVALID');
    }

    $stage = 'publish_generation';
    $stagingParent = ensure_regular_directory($previewRoot . '\\.staging', 'STAGING_ROOT_INVALID');
    $stagingRoot = ensure_regular_directory($stagingParent . '\\' . $jobId, 'STAGING_GENERATION_INVALID');
    $stagedPdf = $stagingRoot . '\\preview.pdf';
    if (!@copy($convertedPdf, $stagedPdf) || !pdf_header_valid($stagedPdf)) {
        fail('STAGING_ARTIFACT_INVALID');
    }
    @chmod($stagedPdf, 0444);
    $pdfSha256 = file_sha256($stagedPdf, 'STAGING_ARTIFACT_INVALID');
    $pdfSize = @filesize($stagedPdf);
    if (!is_int($pdfSize) && !is_float($pdfSize)) {
        fail('STAGING_ARTIFACT_INVALID');
    }
    $manifest = [
        'schemaVersion' => PREVIEW_SCHEMA_VERSION,
        'kind' => PREVIEW_KIND,
        'sourceRelativePath' => str_replace('\\', '/', $relativePath),
        'sourceSha256' => $sourceSha256,
        'sourceSizeBytes' => $snapshot['sourceSizeBytes'],
        'sourceLastWriteUtc' => $snapshot['sourceLastWriteUtc'],
        'pdfSha256' => $pdfSha256,
        'pdfSizeBytes' => (int) $pdfSize,
        'slideCount' => $slideCount,
        'converter' => 'LibreOffice',
        'converterVersion' => $converterVersion,
        'createdUtc' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'pdfFile' => 'preview.pdf',
    ];
    write_private_file($stagingRoot . '\\manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'STAGING_MANIFEST_INVALID');
    if (!@rename($stagingRoot, $generationRoot)) {
        fail('GENERATION_PUBLISH_FAILED');
    }
    $stagingRoot = null;

    throw new PreviewWorkerSuccess([
        'status' => 'created',
        'jobId' => $jobId,
        'sourceSha256' => $sourceSha256,
        'slideCount' => $slideCount,
        'previewGeneration' => substr($sourceSha256, 0, 2) . '/' . $sourceSha256,
        'manifestFile' => 'manifest.json',
        'pdfFile' => 'preview.pdf',
    ]);
} catch (PreviewWorkerSuccess $success) {
    $resultPayload = $success->payload;
    $resultExitCode = 0;
} catch (Throwable $exception) {
    $code = $exception instanceof PreviewWorkerException ? $exception->safeCode : 'UNEXPECTED_FAILURE';
    write_safe_failure_diagnostic($runtimeRoot, $jobId, $code, $stage, $libreOfficeExitCode, $exception);
    $resultPayload = ['status' => 'failed', 'code' => safe_code($code), 'jobId' => $jobId];
} finally {
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    // Lock files deliberately remain as inert regular files.  Removing a lock
    // pathname after unlocking can split the lock domain for a waiting worker.
    safe_remove_tree($stagingRoot, $previewRoot);
    safe_remove_tree($jobRoot, $runtimeRoot);
}

emit_payload($resultPayload, $resultExitCode);
