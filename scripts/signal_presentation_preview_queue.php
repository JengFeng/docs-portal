<?php
declare(strict_types=1);

/*
 * Credentialless Task Scheduler wake-up for the presentation preview queue.
 *
 * This deliberately does not load the portal bootstrap or application
 * environment. It can only create the fixed ACL-protected marker and make a
 * certificate-validated loopback HTTPS request to the fixed internal route.
 * IIS claims the marker and launches the real queue under the AppPool identity.
 */

const QUEUE_WAKE_ENDPOINT = 'https://aiwork.ddns.net/gary/TWWATER/?action=presentation_queue_internal';
const QUEUE_WAKE_HOST = 'aiwork.ddns.net';
const QUEUE_WAKE_PORT = 443;
const QUEUE_WAKE_MARKER = 'presentation-preview-queue.pending.json';

function queue_wake_emit(string $status, string $code = '', int $exitCode = 0): never
{
    $payload = ['status' => $status];
    if ($code !== '') {
        $payload['code'] = $code;
    }
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($exitCode);
}

function queue_wake_throw(string $code): never
{
    if (preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $code) !== 1) {
        $code = 'QUEUE_WAKE_FAILED';
    }
    throw new RuntimeException($code);
}

function queue_wake_safe_code(Throwable $exception): string
{
    $code = $exception instanceof RuntimeException ? $exception->getMessage() : '';
    return preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $code) === 1
        ? $code
        : 'QUEUE_WAKE_FAILED';
}

function queue_wake_write_all($stream, string $value): bool
{
    $offset = 0;
    $length = strlen($value);
    while ($offset < $length) {
        $written = fwrite($stream, substr($value, $offset));
        if ($written === false || $written <= 0) {
            return false;
        }
        $offset += $written;
    }
    return true;
}

function queue_wake_arguments(array $arguments): array
{
    $signalRoot = null;
    $resultPath = null;
    $healthCheck = false;

    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = (string) $arguments[$index];
        if ($argument === '--health-check') {
            if ($healthCheck) {
                queue_wake_throw('INVALID_ARGUMENT');
            }
            $healthCheck = true;
            continue;
        }
        if ($argument === '--signal-root' || $argument === '--result-path') {
            if ($index + 1 >= $count) {
                queue_wake_throw('INVALID_ARGUMENT');
            }
            $value = (string) $arguments[++$index];
            if ($value === '' || str_contains($value, "\0")) {
                queue_wake_throw('INVALID_ARGUMENT');
            }
            if ($argument === '--signal-root') {
                if ($signalRoot !== null) {
                    queue_wake_throw('INVALID_ARGUMENT');
                }
                $signalRoot = $value;
            } else {
                if ($resultPath !== null) {
                    queue_wake_throw('INVALID_ARGUMENT');
                }
                $resultPath = $value;
            }
            continue;
        }
        queue_wake_throw('INVALID_ARGUMENT');
    }

    if ($signalRoot === null) {
        queue_wake_throw('INVALID_ARGUMENT');
    }
    if ($resultPath !== null && !$healthCheck) {
        queue_wake_throw('INVALID_ARGUMENT');
    }
    return [$signalRoot, $resultPath, $healthCheck];
}

function queue_wake_regular_directory(string $requestedRoot): string
{
    if (is_link($requestedRoot)) {
        queue_wake_throw('SIGNAL_ROOT_INVALID');
    }
    $root = realpath($requestedRoot);
    if ($root === false || !is_dir($root) || is_link($root)) {
        queue_wake_throw('SIGNAL_ROOT_INVALID');
    }
    $root = rtrim($root, "\\/");
    if (strtolower(basename(str_replace('\\', '/', $root))) !== 'presentation-preview-trigger') {
        queue_wake_throw('SIGNAL_ROOT_INVALID');
    }
    return $root;
}

function queue_wake_open_result(?string $requestedPath, string $signalRoot)
{
    if ($requestedPath === null) {
        return null;
    }
    if (is_link($requestedPath)) {
        queue_wake_throw('RESULT_PATH_INVALID');
    }
    $resultPath = realpath($requestedPath);
    $expectedPrefix = strtolower($signalRoot . DIRECTORY_SEPARATOR);
    if ($resultPath === false
        || is_link($resultPath)
        || !is_file($resultPath)
        || strtolower(dirname($resultPath) . DIRECTORY_SEPARATOR) !== $expectedPrefix
        || preg_match('/\Apresentation-preview-validation-[0-9a-f]{32}\.json\z/', basename($resultPath)) !== 1) {
        queue_wake_throw('RESULT_PATH_INVALID');
    }
    $stream = @fopen($resultPath, 'r+b');
    if ($stream === false) {
        queue_wake_throw('RESULT_PATH_UNAVAILABLE');
    }
    $size = filesize($resultPath);
    $initial = $size === false || $size < 24 || $size > 512 ? false : stream_get_contents($stream);
    if ($initial === false || strlen($initial) !== $size) {
        fclose($stream);
        queue_wake_throw('RESULT_PATH_INVALID');
    }
    try {
        $payload = json_decode($initial, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fclose($stream);
        queue_wake_throw('RESULT_PATH_INVALID');
    }
    if (!is_array($payload)
        || array_keys($payload) !== ['status', 'code']
        || $payload['status'] !== 'failed'
        || $payload['code'] !== 'TASK_NOT_STARTED') {
        fclose($stream);
        queue_wake_throw('RESULT_PATH_INVALID');
    }
    return $stream;
}

function queue_wake_write_result($stream, string $status, string $code): void
{
    if (!is_resource($stream)) {
        return;
    }
    if (!in_array($status, ['ready', 'failed'], true)
        || preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $code) !== 1) {
        queue_wake_throw('RESULT_WRITE_FAILED');
    }
    $json = json_encode(['status' => $status, 'code' => $code], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (rewind($stream) !== true
        || ftruncate($stream, 0) !== true
        || !queue_wake_write_all($stream, $json)
        || fflush($stream) !== true) {
        queue_wake_throw('RESULT_WRITE_FAILED');
    }
}

function queue_wake_write_marker(string $signalRoot, bool $healthCheck): void
{
    $markerPath = $signalRoot . DIRECTORY_SEPARATOR . QUEUE_WAKE_MARKER;
    if (is_link($markerPath)) {
        queue_wake_throw('MARKER_PATH_INVALID');
    }
    $stream = @fopen($markerPath, 'x+b');
    if ($stream === false) {
        if ($healthCheck) {
            queue_wake_throw('HEALTH_MARKER_ALREADY_PENDING');
        }
        if (!is_file($markerPath) || is_link($markerPath)) {
            queue_wake_throw('MARKER_PATH_INVALID');
        }
        return;
    }
    try {
        $payload = json_encode([
            'kind' => 'TWWATER_PRESENTATION_QUEUE_WAKE',
            'version' => 1,
            'mode' => $healthCheck ? 'health' : 'process',
            'nonce' => bin2hex(random_bytes(16)),
            'issuedUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!queue_wake_write_all($stream, $payload) || fflush($stream) !== true) {
            queue_wake_throw('MARKER_WRITE_FAILED');
        }
    } finally {
        fclose($stream);
    }
}

function queue_wake_safe_endpoint_code(string $response): string
{
    if ($response === '' || strlen($response) > 4096) {
        return '';
    }
    try {
        $payload = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return '';
    }
    $code = is_array($payload) ? (string) ($payload['code'] ?? '') : '';
    return preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $code) === 1 ? $code : '';
}

function queue_wake_request(bool $healthCheck): void
{
    $systemRoot = trim((string) getenv('SystemRoot'));
    $curlPath = $systemRoot === '' ? false : realpath($systemRoot . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'curl.exe');
    if ($curlPath === false || !is_file($curlPath) || is_link($curlPath)) {
        queue_wake_throw('CURL_UNAVAILABLE');
    }
    $maxTime = $healthCheck ? '30' : '300';
    $command = [
        $curlPath,
        '--silent', '--show-error', '--fail-with-body',
        '--connect-timeout', '10', '--max-time', $maxTime,
        '--request', 'POST',
        '--header', 'Cache-Control: no-store',
        '--header', 'Content-Length: 0',
        '--noproxy', '*',
        '--proto', '=https', '--proto-redir', '=https',
        '--resolve', QUEUE_WAKE_HOST . ':' . QUEUE_WAKE_PORT . ':127.0.0.1',
        QUEUE_WAKE_ENDPOINT,
    ];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        queue_wake_throw('CURL_START_FAILED');
    }
    fclose($pipes[0]);
    $response = stream_get_contents($pipes[1], 4097);
    $error = stream_get_contents($pipes[2], 4097);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($response === false || $error === false || strlen($response) > 4096 || strlen($error) > 4096) {
        queue_wake_throw('CURL_OUTPUT_INVALID');
    }
    if ($exitCode !== 0) {
        $endpointCode = queue_wake_safe_endpoint_code(trim($response));
        queue_wake_throw($endpointCode !== '' ? $endpointCode : 'CURL_EXIT_' . max(0, min(255, (int) $exitCode)));
    }
    try {
        $payload = json_decode(trim($response), true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        queue_wake_throw('LOOPBACK_RESPONSE_INVALID');
    }
    $expected = $healthCheck ? ['status' => 'ready'] : ['status' => 'accepted'];
    if ($payload !== $expected) {
        queue_wake_throw('LOOPBACK_RESPONSE_INVALID');
    }
}

if (PHP_SAPI !== 'cli') {
    queue_wake_emit('failed', 'CLI_ONLY', 1);
}

$resultStream = null;
try {
    [$requestedRoot, $requestedResultPath, $healthCheck] = queue_wake_arguments($argv);
    $signalRoot = queue_wake_regular_directory($requestedRoot);
    $resultStream = queue_wake_open_result($requestedResultPath, $signalRoot);
    queue_wake_write_result($resultStream, 'failed', 'SIGNAL_STARTED');
    queue_wake_write_result($resultStream, 'failed', 'SIGNAL_PREFLIGHT_READY');
    queue_wake_write_marker($signalRoot, $healthCheck);
    queue_wake_write_result($resultStream, 'failed', 'MARKER_WRITTEN');
    queue_wake_request($healthCheck);
    queue_wake_write_result($resultStream, 'ready', 'READY');
    if (is_resource($resultStream)) {
        fclose($resultStream);
    }
    queue_wake_emit($healthCheck ? 'ready' : 'accepted');
} catch (Throwable $exception) {
    $safeCode = queue_wake_safe_code($exception);
    if (is_resource($resultStream)) {
        try {
            queue_wake_write_result($resultStream, 'failed', $safeCode);
        } catch (Throwable $ignored) {
            // The task result remains non-zero if the protected diagnostic file cannot be updated.
        }
        fclose($resultStream);
    }
    queue_wake_emit('failed', $safeCode, 1);
}
