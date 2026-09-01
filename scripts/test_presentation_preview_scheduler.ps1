#Requires -Version 5.1

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Assert-Contains {
    param(
        [Parameter(Mandatory)][string] $Text,
        [Parameter(Mandatory)][string] $Needle,
        [Parameter(Mandatory)][string] $Label
    )

    if (-not $Text.Contains($Needle)) {
        throw "$Label integration check failed."
    }
}

foreach ($relativePath in @(
    'scripts\signal_presentation_preview_queue.ps1',
    'scripts\install_presentation_preview_scheduler.ps1'
)) {
    $path = Join-Path $projectRoot $relativePath
    $tokens = $null
    $parseErrors = $null
    [void] [Management.Automation.Language.Parser]::ParseFile(
        $path,
        [ref]$tokens,
        [ref]$parseErrors
    )
    if ($parseErrors.Count -ne 0) {
        throw "PowerShell parse failed: $relativePath"
    }
}

$index = Get-Content -LiteralPath (Join-Path $projectRoot 'index.php') -Raw -Encoding utf8
$bootstrap = Get-Content -LiteralPath (Join-Path $projectRoot 'app\bootstrap.php') -Raw -Encoding utf8
$handler = Get-Content -LiteralPath (Join-Path $projectRoot 'app\presentation_queue_trigger.php') -Raw -Encoding utf8
$queue = Get-Content -LiteralPath (Join-Path $projectRoot 'scripts\process_presentation_preview_queue.php') -Raw -Encoding utf8
$worker = Get-Content -LiteralPath (Join-Path $projectRoot 'scripts\presentation_preview_worker.php') -Raw -Encoding utf8
$signal = Get-Content -LiteralPath (Join-Path $projectRoot 'scripts\signal_presentation_preview_queue.ps1') -Raw -Encoding utf8
$phpSignal = Get-Content -LiteralPath (Join-Path $projectRoot 'scripts\signal_presentation_preview_queue.php') -Raw -Encoding utf8
$installer = Get-Content -LiteralPath (Join-Path $projectRoot 'scripts\install_presentation_preview_scheduler.ps1') -Raw -Encoding utf8

Assert-Contains $bootstrap "presentation_queue_trigger.php" 'Bootstrap require'
Assert-Contains $index "'presentation_queue_internal'" 'Route allow-list'
Assert-Contains $index 'portal_handle_presentation_queue_internal($config);' 'Route handler'
$handlerPosition = $index.IndexOf('portal_handle_presentation_queue_internal($config);')
$loginPosition = $index.IndexOf('$user = portal_require_user($database);')
if ($handlerPosition -lt 0 -or $loginPosition -lt 0 -or $handlerPosition -gt $loginPosition) {
    throw 'Internal queue handler must run before portal login is required.'
}

foreach ($needle in @(
    "`$_SERVER['REMOTE_ADDR']",
    "['127.0.0.1', '::1', '::ffff:127.0.0.1']",
    'QUEUE_SIGNAL_EXPIRED',
    '@rename($pendingPath, $claimPath)',
    'bin2hex(random_bytes(16))',
    "['status' => 'accepted']"
)) {
    Assert-Contains $handler $needle 'Loopback marker handler'
}

Assert-Contains $queue "PHP_SAPI !== 'cli'" 'CLI-only queue'
Assert-Contains $queue "--health-check" 'Queue health check'
Assert-Contains $queue "--quiet" 'Detached queue output suppression'
Assert-Contains $queue 'LOCK_EX | LOCK_NB' 'Single-converter lock'
Assert-Contains $queue "presentation_preview_worker.php" 'PHP preview worker'
Assert-Contains $queue "'--libreoffice-path'" 'PHP worker LibreOffice argument'
Assert-Contains $queue 'queue_run_worker($workerPath, $workerArguments)' 'PHP worker invocation'
Assert-Contains $queue "attempt_count = CASE" 'Bounded stale final-attempt reclaim'
Assert-Contains $queue "WHEN status_code = 'processing' AND attempt_count = max_attempts THEN attempt_count" 'Final stale claim must not violate the attempt-count constraint'
if ($queue.Contains("'powershell.exe'")) {
    throw 'The presentation queue must not launch PowerShell.'
}
Assert-Contains $worker 'proc_open($command' 'Direct LibreOffice process launch'
Assert-Contains $worker "'--convert-to'," 'LibreOffice PDF conversion arguments'
Assert-Contains $handler "[`$paths['php'], '-d', 'max_execution_time=60', '-f', `$paths['queue'], '--', '--health-check']" 'PHP CLI health argument order'
Assert-Contains $signal "'--resolve' `$resolve" 'Pinned loopback TLS request'
Assert-Contains $signal "'--noproxy' '*'" 'Loopback proxy bypass'
Assert-Contains $signal "'--header' 'Content-Length: 0'" 'Explicit empty POST length'
Assert-Contains $signal "'--fail-with-body'" 'Safe endpoint failure body capture'
Assert-Contains $signal 'Get-SafeEndpointCode -ResponseText $responseText' 'Safe endpoint code parser'
Assert-Contains $signal 'Write-SafeHealthResult -Stream $healthResultStream' 'Protected health result writer'
Assert-Contains $signal "'CURL_EXIT_' + [string] `$curlExitCode" 'Safe curl exit diagnosis'
Assert-Contains $signal '[IO.FileMode]::Open' 'Precreated validation result update'
Assert-Contains $signal '[IO.FileAccess]::ReadWrite' 'Held validation result handle'
Assert-Contains $signal '[IO.FileShare]::ReadWrite' 'Shared validation result update handle'
Assert-Contains $signal "[string]`$initialResult.code -ne 'TASK_NOT_STARTED'" 'Strict validation placeholder check'
Assert-Contains $signal "-Code 'SIGNAL_STARTED'" 'Signal process start diagnosis'
Assert-Contains $signal "-Code 'SIGNAL_PREFLIGHT_READY'" 'Signal preflight diagnosis'
Assert-Contains $signal "-Code 'MARKER_WRITTEN'" 'Signal marker diagnosis'
Assert-Contains $signal 'ResultPath is permitted only for a health check.' 'Health-only validation result'
Assert-Contains $phpSignal "PHP_SAPI !== 'cli'" 'PHP wake CLI-only guard'
Assert-Contains $phpSignal "const QUEUE_WAKE_ENDPOINT = 'https://aiwork.ddns.net/gary/TWWATER/?action=presentation_queue_internal';" 'Fixed PHP wake endpoint'
Assert-Contains $phpSignal "'--noproxy', '*'" 'PHP wake proxy bypass'
Assert-Contains $phpSignal "'--resolve', QUEUE_WAKE_HOST . ':' . QUEUE_WAKE_PORT . ':127.0.0.1'" 'PHP wake pinned loopback route'
Assert-Contains $phpSignal "'Content-Length: 0'" 'PHP wake explicit empty POST length'
Assert-Contains $phpSignal "'--proto', '=https', '--proto-redir', '=https'" 'PHP wake HTTPS-only curl policy'
Assert-Contains $phpSignal "['status' => 'ready']" 'PHP wake health response contract'
Assert-Contains $phpSignal "['status' => 'accepted']" 'PHP wake process response contract'
Assert-Contains $phpSignal "queue_wake_write_result(`$resultStream, 'failed', 'SIGNAL_STARTED')" 'PHP wake signal stage'
Assert-Contains $phpSignal "queue_wake_write_result(`$resultStream, 'failed', 'MARKER_WRITTEN')" 'PHP wake marker stage'
Assert-Contains $installer "[ValidateSet('LocalService', 'LocalSystem')]" 'Explicit task identity choice'
Assert-Contains $installer "`$taskUserId = 'NT AUTHORITY\LOCAL SERVICE'" 'Least-privilege task identity default'
Assert-Contains $installer "`$taskUserId = 'NT AUTHORITY\SYSTEM'" 'COMODO-compatible task identity option'
Assert-Contains $installer 'Assert-TaskFileAcl -Paths $taskDependencies' 'SYSTEM-task script ACL guard'
Assert-Contains $installer "'VALIDATION_RESULT_MISSING'" 'Missing validation result diagnosis'
Assert-Contains $installer '-ResultPath $validationResultPath' 'Protected validation result handoff'
Assert-Contains $installer "-Code 'TASK_NOT_STARTED'" 'Precreated validation stage result'
Assert-Contains $installer '[IO.FileMode]::CreateNew' 'Validation result exclusive creation'
Assert-Contains $installer 'Security.AccessControl.FileSecurity' 'Validation result protected DACL'
Assert-Contains $installer "'-n', '-d', 'display_errors=0', '-d', 'log_errors=0'" 'PHP task hardening arguments'
Assert-Contains $installer '-PhpPath $PhpPath' 'PHP CLI task action'
Assert-Contains $installer "presentation_preview_worker.php" 'PHP worker deployment dependency'
Assert-Contains $installer "Stop-ScheduledTask -TaskName `$validationTaskName" 'Validation timeout cleanup'

foreach ($source in @($signal, $phpSignal, $installer)) {
    if ($source -match 'PORTAL_DB_PASSWORD|PORTAL_BOOTSTRAP_PASSWORD|Discord.{0,20}(token|password)') {
        throw 'A wake-up/task script unexpectedly references a secret-bearing setting.'
    }
}

Write-Output '[OK] Presentation preview scheduler static integration checks passed.'
