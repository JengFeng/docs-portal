<?php
declare(strict_types=1);

/*
 * Loopback-only wake endpoint for the presentation preview queue.
 *
 * The scheduled task carries no credential. Its only authority is permission
 * to create a short-lived marker in a dedicated ACL-protected directory. The
 * IIS AppPool atomically consumes that marker and starts the CLI queue with its
 * already configured process environment. No request parameter can select a
 * command, executable, source path, or document.
 */

function portal_presentation_queue_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function portal_presentation_queue_loopback_request(): bool
{
    $remoteAddress = strtolower(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    if (!in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
        return false;
    }
    // Do not trust a loopback reverse proxy to make an internet client local.
    // The scheduler's pinned curl request does not send forwarding headers.
    foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
        if (trim((string) ($_SERVER[$header] ?? '')) !== '') {
            return false;
        }
    }
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    return in_array($host, ['aiwork.ddns.net', 'aiwork.ddns.net:443'], true);
}

function portal_presentation_queue_signal_root(array $config): string
{
    $sessionRoot = realpath((string) ($config['session_save_path'] ?? ''));
    if ($sessionRoot === false || !is_dir($sessionRoot)) {
        throw new RuntimeException('QUEUE_SIGNAL_ROOT_UNAVAILABLE');
    }
    $signalRoot = realpath($sessionRoot . DIRECTORY_SEPARATOR . 'presentation-preview-trigger');
    $sessionPrefix = strtolower(rtrim($sessionRoot, '\\/') . DIRECTORY_SEPARATOR);
    if ($signalRoot === false || !is_dir($signalRoot)
        || !str_starts_with(strtolower(rtrim($signalRoot, '\\/') . DIRECTORY_SEPARATOR), $sessionPrefix)
        || !portal_path_is_outside_application_root($signalRoot)) {
        throw new RuntimeException('QUEUE_SIGNAL_ROOT_UNAVAILABLE');
    }
    return rtrim($signalRoot, '\\/');
}

function portal_presentation_queue_claim_marker(string $signalRoot): array
{
    $pendingPath = $signalRoot . DIRECTORY_SEPARATOR . 'presentation-preview-queue.pending.json';
    if (!is_file($pendingPath)) {
        throw new RuntimeException('QUEUE_SIGNAL_NOT_FOUND');
    }
    $claimPath = $signalRoot . DIRECTORY_SEPARATOR . 'presentation-preview-queue.claimed-'
        . bin2hex(random_bytes(16)) . '.json';
    if (!@rename($pendingPath, $claimPath)) {
        throw new RuntimeException('QUEUE_SIGNAL_ALREADY_CLAIMED');
    }

    try {
        $size = filesize($claimPath);
        if ($size === false || $size < 64 || $size > 2048) {
            throw new RuntimeException('QUEUE_SIGNAL_INVALID');
        }
        $raw = file_get_contents($claimPath);
        if ($raw === false || strlen($raw) !== $size) {
            throw new RuntimeException('QUEUE_SIGNAL_INVALID');
        }
        $signal = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $mode = (string) ($signal['mode'] ?? '');
        $nonce = (string) ($signal['nonce'] ?? '');
        $issuedText = (string) ($signal['issuedUtc'] ?? '');
        if (($signal['kind'] ?? null) !== 'TWWATER_PRESENTATION_QUEUE_WAKE'
            || (int) ($signal['version'] ?? 0) !== 1
            || !in_array($mode, ['health', 'process'], true)
            || preg_match('/\A[0-9a-f]{32}\z/', $nonce) !== 1
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $issuedText) !== 1) {
            throw new RuntimeException('QUEUE_SIGNAL_INVALID');
        }
        $issued = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $issuedText,
            new DateTimeZone('UTC')
        );
        if ($issued === false || $issued->format('Y-m-d\TH:i:s\Z') !== $issuedText) {
            throw new RuntimeException('QUEUE_SIGNAL_INVALID');
        }
        $age = time() - $issued->getTimestamp();
        if ($age < -30 || $age > 600) {
            throw new RuntimeException('QUEUE_SIGNAL_EXPIRED');
        }
        return ['path' => $claimPath, 'mode' => $mode];
    } catch (Throwable $exception) {
        @unlink($claimPath);
        if ($exception instanceof RuntimeException) {
            throw $exception;
        }
        throw new RuntimeException('QUEUE_SIGNAL_INVALID');
    }
}

function portal_presentation_queue_runtime_paths(): array
{
    $projectRoot = realpath(dirname(__DIR__));
    $phpDirectory = realpath(dirname(PHP_BINARY));
    if ($projectRoot === false || $phpDirectory === false) {
        throw new RuntimeException('QUEUE_RUNTIME_UNAVAILABLE');
    }
    $phpCli = realpath($phpDirectory . DIRECTORY_SEPARATOR . 'php.exe');
    $queue = realpath($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR
        . 'process_presentation_preview_queue.php');
    if ($phpCli === false || $queue === false || !is_file($phpCli) || !is_file($queue)) {
        throw new RuntimeException('QUEUE_RUNTIME_UNAVAILABLE');
    }
    return [
        'project' => $projectRoot,
        'php' => $phpCli,
        'queue' => $queue,
    ];
}

function portal_presentation_queue_terminate_process_tree(int $processId): void
{
    if ($processId < 1) {
        return;
    }
    $systemRoot = trim((string) getenv('SystemRoot'));
    $taskKill = $systemRoot === '' ? false : realpath($systemRoot . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'taskkill.exe');
    if ($taskKill === false || !is_file($taskKill)) {
        return;
    }
    $pipes = [];
    $process = @proc_open([$taskKill, '/PID', (string) $processId, '/T', '/F'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return;
    }
    fclose($pipes[0]);
    foreach ([1, 2] as $index) {
        stream_get_contents($pipes[$index], 4096);
        fclose($pipes[$index]);
    }
    @proc_close($process);
}

function portal_presentation_queue_run_process(
    array $command,
    string $workingDirectory,
    int $timeoutSeconds = 30,
    int $outputLimit = 8192
): array
{
    if ($timeoutSeconds < 1 || $timeoutSeconds > 300 || $outputLimit < 256 || $outputLimit > 65536) {
        throw new RuntimeException('QUEUE_PROCESS_ARGUMENT_INVALID');
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('QUEUE_PROCESS_START_FAILED');
    }
    $stdout = '';
    $stderr = '';
    $exitCode = -1;
    try {
        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);
            if ($stdoutChunk === false || $stderrChunk === false) {
                throw new RuntimeException('QUEUE_PROCESS_OUTPUT_INVALID');
            }
            $stdout .= $stdoutChunk;
            $stderr .= $stderrChunk;
            if (strlen($stdout) > $outputLimit || strlen($stderr) > $outputLimit) {
                throw new RuntimeException('QUEUE_PROCESS_OUTPUT_INVALID');
            }
            $state = proc_get_status($process);
            if ($state['running'] !== true) {
                $exitCode = (int) $state['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                @proc_terminate($process);
                portal_presentation_queue_terminate_process_tree((int) ($state['pid'] ?? 0));
                throw new RuntimeException('QUEUE_PROCESS_TIMEOUT');
            }
            usleep(50000);
        }
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdoutTail = stream_get_contents($pipes[1], $outputLimit + 1 - strlen($stdout));
        $stderrTail = stream_get_contents($pipes[2], $outputLimit + 1 - strlen($stderr));
        if ($stdoutTail === false || $stderrTail === false) {
            throw new RuntimeException('QUEUE_PROCESS_OUTPUT_INVALID');
        }
        $stdout .= $stdoutTail;
        $stderr .= $stderrTail;
        if (strlen($stdout) > $outputLimit || strlen($stderr) > $outputLimit) {
            throw new RuntimeException('QUEUE_PROCESS_OUTPUT_INVALID');
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        unset($pipes[1], $pipes[2]);
        $closedExitCode = proc_close($process);
        $process = null;
        if ($exitCode < 0) {
            $exitCode = (int) $closedExitCode;
        }
        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            $state = @proc_get_status($process);
            @proc_terminate($process);
            portal_presentation_queue_terminate_process_tree((int) ($state['pid'] ?? 0));
            @proc_close($process);
        }
    }
}

function portal_presentation_queue_health(array $paths): array
{
    $result = portal_presentation_queue_run_process(
        [$paths['php'], '-d', 'max_execution_time=60', '-f', $paths['queue'], '--', '--health-check'],
        $paths['project'],
        30
    );
    $payload = null;
    foreach (array_reverse(preg_split('/\R/', trim((string) $result['stdout'])) ?: []) as $line) {
        if ($line === '' || !str_starts_with($line, '{')) {
            continue;
        }
        try {
            $candidate = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (is_array($candidate)) {
                $payload = $candidate;
                break;
            }
        } catch (JsonException $exception) {
            continue;
        }
    }
    if ((int) $result['exit_code'] === 0 && ($payload['status'] ?? null) === 'ready') {
        return ['status' => 'ready'];
    }
    $code = is_array($payload) && preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', (string) ($payload['code'] ?? '')) === 1
        ? (string) $payload['code']
        : 'QUEUE_HEALTH_FAILED';
    return ['status' => 'failed', 'code' => $code];
}

function portal_presentation_queue_launch(array $paths): void
{
    // This route is callable only through the authenticated localhost marker
    // protocol. Keeping the request open lets the IIS AppPool retain its
    // protected environment while the PHP worker runs; no browser request is
    // used for conversion and no detached child survives without supervision.
    ignore_user_abort(true);
    @set_time_limit(300);
    $result = portal_presentation_queue_run_process(
        [$paths['php'], '-d', 'max_execution_time=240', '-f', $paths['queue'], '--'],
        $paths['project'],
        270
    );
    if ((int) $result['exit_code'] !== 0) {
        foreach (array_reverse(preg_split('/\R/', trim((string) $result['stdout'])) ?: []) as $line) {
            try {
                $payload = json_decode(trim($line), true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                continue;
            }
            $code = is_array($payload) ? (string) ($payload['code'] ?? '') : '';
            if (preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $code) === 1) {
                throw new RuntimeException($code);
            }
        }
        throw new RuntimeException('QUEUE_LAUNCH_FAILED');
    }
    foreach (array_reverse(preg_split('/\R/', trim((string) $result['stdout'])) ?: []) as $line) {
        try {
            $payload = json_decode(trim($line), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            continue;
        }
        if (is_array($payload) && in_array((string) ($payload['status'] ?? ''), ['succeeded', 'idle', 'busy'], true)) {
            return;
        }
    }
    throw new RuntimeException('QUEUE_LAUNCH_RESULT_INVALID');
}

function portal_handle_presentation_queue_internal(array $config): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_presentation_queue_loopback_request()) {
        portal_presentation_queue_json(404, ['status' => 'not_found']);
    }

    $claim = null;
    try {
        $signalRoot = portal_presentation_queue_signal_root($config);
        $claim = portal_presentation_queue_claim_marker($signalRoot);
        $paths = portal_presentation_queue_runtime_paths();
        if ($claim['mode'] === 'health') {
            $health = portal_presentation_queue_health($paths);
            @unlink((string) $claim['path']);
            $claim = null;
            portal_presentation_queue_json(
                $health['status'] === 'ready' ? 200 : 503,
                $health
            );
        }
        portal_presentation_queue_launch($paths);
        @unlink((string) $claim['path']);
        $claim = null;
        portal_presentation_queue_json(202, ['status' => 'accepted']);
    } catch (Throwable $exception) {
        if (is_array($claim) && isset($claim['path'])) {
            @unlink((string) $claim['path']);
        }
        $safeCode = $exception instanceof RuntimeException
            && preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'QUEUE_TRIGGER_FAILED';
        error_log('TWWATER presentation queue trigger failed: ' . $safeCode);
        portal_presentation_queue_json(503, ['status' => 'failed', 'code' => $safeCode]);
    }
}
