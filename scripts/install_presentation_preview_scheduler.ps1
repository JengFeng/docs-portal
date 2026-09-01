#Requires -Version 5.1
#Requires -RunAsAdministrator

[CmdletBinding()]
param(
    [string] $ProjectRoot = (Split-Path -Parent $PSScriptRoot),

    [string] $PhpPath = 'C:\PHP\8.5.9\php.exe',

    [string] $SessionSavePath = 'C:\TWWATER\runtime\sessions',

    [string] $DocumentRoot = 'C:\TWWATER\document-cache',

    [string] $PresentationPreviewRoot = 'C:\TWWATER\runtime\presentation-previews',

    [string] $PresentationRuntimeRoot = 'C:\TWWATER\runtime\presentation-preview',

    [string] $AppPoolName = 'TWWATER_PortalPool',

    [string] $TaskName = 'TWWATER Presentation Preview Queue',

    [ValidateRange(1, 60)]
    [int] $IntervalMinutes = 2,

    [ValidateRange(15, 120)]
    [int] $HealthCheckTimeoutSeconds = 60,

    [ValidateSet('LocalService', 'LocalSystem')]
    [string] $TaskIdentity = 'LocalService',

    [switch] $ReplaceExisting
)

$ErrorActionPreference = 'Stop'

function Resolve-RegularItem {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][bool] $Container,
        [Parameter(Mandatory)][string] $Label
    )

    $resolved = Resolve-Path -LiteralPath $Path -ErrorAction Stop
    $item = Get-Item -LiteralPath $resolved.ProviderPath -Force
    if ($item.PSIsContainer -ne $Container -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw "$Label must be a regular local path and may not be a reparse point."
    }
    return $item
}

function Test-PathWithin {
    param(
        [Parameter(Mandatory)][string] $Parent,
        [Parameter(Mandatory)][string] $Candidate
    )

    $parentPath = [IO.Path]::GetFullPath($Parent).TrimEnd('\', '/')
    $candidatePath = [IO.Path]::GetFullPath($Candidate).TrimEnd('\', '/')
    return $candidatePath.StartsWith(
        $parentPath + [IO.Path]::DirectorySeparatorChar,
        [StringComparison]::OrdinalIgnoreCase
    )
}

function Assert-SeparateDirectoryTrees {
    param(
        [Parameter(Mandatory)][string] $First,
        [Parameter(Mandatory)][string] $Second,
        [Parameter(Mandatory)][string] $Label
    )

    if ([string]::Equals($First, $Second, [StringComparison]::OrdinalIgnoreCase) -or
        (Test-PathWithin -Parent $First -Candidate $Second) -or
        (Test-PathWithin -Parent $Second -Candidate $First)) {
        throw "$Label directories must not be equal or nested."
    }
}

function ConvertTo-TaskArgument {
    param([Parameter(Mandatory)][string] $Value)

    if ($Value.IndexOfAny([char[]]@('"', "`r", "`n", [char]0)) -ge 0) {
        throw 'A scheduled-task argument contains an invalid character.'
    }
    return '"' + $Value + '"'
}

function Assert-TaskFileAcl {
    param([Parameter(Mandatory)][string[]] $Paths)

    $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $allowedWriteSids = @(
        'S-1-5-18',       # LOCAL SYSTEM
        'S-1-5-32-544',   # BUILTIN\Administrators
        'S-1-3-0',        # CREATOR OWNER
        $currentSid
    )
    $writeMask = [Security.AccessControl.FileSystemRights]::WriteData `
        -bor [Security.AccessControl.FileSystemRights]::AppendData `
        -bor [Security.AccessControl.FileSystemRights]::WriteExtendedAttributes `
        -bor [Security.AccessControl.FileSystemRights]::WriteAttributes `
        -bor [Security.AccessControl.FileSystemRights]::Delete `
        -bor [Security.AccessControl.FileSystemRights]::ChangePermissions `
        -bor [Security.AccessControl.FileSystemRights]::TakeOwnership

    foreach ($path in $Paths) {
        $item = Resolve-RegularItem -Path $path -Container $false -Label 'Task dependency'
        $acl = Get-Acl -LiteralPath $item.FullName
        $rules = $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])
        foreach ($rule in $rules) {
            if ($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) {
                continue
            }
            if (([int64]$rule.FileSystemRights -band [int64]$writeMask) -eq 0) {
                continue
            }
            $sid = $rule.IdentityReference.Value
            if ($allowedWriteSids -notcontains $sid) {
                throw "Task dependency is writable by an untrusted principal: $($item.FullName) [$sid]"
            }
        }
    }
}

function Set-TriggerDirectoryAcl {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][Security.Principal.SecurityIdentifier] $AppPoolSid,
        [Parameter(Mandatory)][Security.Principal.SecurityIdentifier] $TaskSid
    )

    $administrators = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')
    $system = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit `
        -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    $propagation = [Security.AccessControl.PropagationFlags]::None
    $allow = [Security.AccessControl.AccessControlType]::Allow

    $security = New-Object Security.AccessControl.DirectorySecurity
    $security.SetAccessRuleProtection($true, $false)
    $security.SetOwner($administrators)
    $entries = @(
        [pscustomobject]@{ Sid = $administrators; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        [pscustomobject]@{ Sid = $system; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        [pscustomobject]@{ Sid = $AppPoolSid; Rights = [Security.AccessControl.FileSystemRights]::Modify }
    )
    if ($TaskSid.Value -notin @($administrators.Value, $system.Value, $AppPoolSid.Value)) {
        $entries += [pscustomobject]@{
            Sid = $TaskSid
            Rights = [Security.AccessControl.FileSystemRights]::Modify
        }
    }
    foreach ($entry in $entries) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule(
            $entry.Sid, $entry.Rights, $inheritance, $propagation, $allow
        )
        [void] $security.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $security
}

function Grant-LocalServiceRead {
    param([Parameter(Mandatory)][string] $Path)

    $acl = Get-Acl -LiteralPath $Path
    $localService = New-Object Security.Principal.SecurityIdentifier('S-1-5-19')
    $rule = New-Object Security.AccessControl.FileSystemAccessRule(
        $localService,
        [Security.AccessControl.FileSystemRights]::ReadAndExecute,
        [Security.AccessControl.AccessControlType]::Allow
    )
    [void] $acl.SetAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Grant-AppPoolDirectoryAccess {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][Security.Principal.SecurityIdentifier] $AppPoolSid,
        [Parameter(Mandatory)][Security.AccessControl.FileSystemRights] $Rights
    )

    $acl = Get-Acl -LiteralPath $Path
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit `
        -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    $rule = New-Object Security.AccessControl.FileSystemAccessRule(
        $AppPoolSid,
        $Rights,
        $inheritance,
        [Security.AccessControl.PropagationFlags]::None,
        [Security.AccessControl.AccessControlType]::Allow
    )
    [void] $acl.SetAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function New-QueueTaskAction {
    param(
        [Parameter(Mandatory)][string] $PhpPath,
        [Parameter(Mandatory)][string] $SignalScript,
        [Parameter(Mandatory)][string] $SignalRoot,
        [string] $ResultPath = '',
        [switch] $HealthCheck
    )

    $parts = @(
        '-n', '-d', 'display_errors=0', '-d', 'log_errors=0',
        '-f', (ConvertTo-TaskArgument $SignalScript), '--',
        '--signal-root', (ConvertTo-TaskArgument $SignalRoot)
    )
    if ($HealthCheck) {
        $parts += '--health-check'
    }
    if (-not [string]::IsNullOrWhiteSpace($ResultPath)) {
        $parts += @('--result-path', (ConvertTo-TaskArgument $ResultPath))
    }
    return New-ScheduledTaskAction `
        -Execute $PhpPath `
        -Argument ($parts -join ' ') `
        -WorkingDirectory $ProjectRoot
}

function New-ProtectedValidationResult {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Code,
        [Parameter(Mandatory)][Security.Principal.SecurityIdentifier] $TaskSid
    )

    if ($Code -notmatch '\A[A-Z][A-Z0-9_]{2,63}\z') {
        throw 'The initial validation result code is invalid.'
    }
    $payload = [ordered]@{
        status = 'failed'
        code = $Code
    }
    $json = $payload | ConvertTo-Json -Compress
    $encoding = New-Object Text.UTF8Encoding($false)
    $bytes = $encoding.GetBytes($json)
    $administrators = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')
    $system = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
    $allow = [Security.AccessControl.AccessControlType]::Allow
    $security = New-Object Security.AccessControl.FileSecurity
    $security.SetAccessRuleProtection($true, $false)
    $security.SetOwner($administrators)
    $entries = @(
        [pscustomobject]@{ Sid = $administrators; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        [pscustomobject]@{ Sid = $system; Rights = [Security.AccessControl.FileSystemRights]::FullControl }
    )
    if ($TaskSid.Value -notin @($administrators.Value, $system.Value)) {
        $entries += [pscustomobject]@{
            Sid = $TaskSid
            Rights = [Security.AccessControl.FileSystemRights]::Read `
                -bor [Security.AccessControl.FileSystemRights]::Write `
                -bor [Security.AccessControl.FileSystemRights]::Synchronize
        }
    }
    foreach ($entry in $entries) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule(
            $entry.Sid,
            $entry.Rights,
            $allow
        )
        [void] $security.AddAccessRule($rule)
    }

    $stream = $null
    try {
        # Create the file and its protected DACL atomically before the task is
        # started. The PHP signal can then open only this preapproved result.
        $validationHandleRights = [Security.AccessControl.FileSystemRights]::Read `
            -bor [Security.AccessControl.FileSystemRights]::Write `
            -bor [Security.AccessControl.FileSystemRights]::Synchronize
        $stream = New-Object IO.FileStream(
            $Path,
            [IO.FileMode]::CreateNew,
            $validationHandleRights,
            [IO.FileShare]::ReadWrite,
            4096,
            [IO.FileOptions]::WriteThrough,
            $security
        )
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush($true)
    }
    finally {
        [Array]::Clear($bytes, 0, $bytes.Length)
        if ($null -ne $stream) {
            $stream.Dispose()
        }
    }
}

if ($TaskName -notmatch '\A[A-Za-z0-9 _.-]{3,120}\z') {
    throw 'TaskName contains an unsupported character.'
}
if ($AppPoolName -notmatch '\A[A-Za-z0-9_.-]{1,64}\z') {
    throw 'AppPoolName is invalid.'
}

$projectItem = Resolve-RegularItem -Path $ProjectRoot -Container $true -Label 'Project root'
$ProjectRoot = $projectItem.FullName.TrimEnd('\')
$sessionItem = Resolve-RegularItem -Path $SessionSavePath -Container $true -Label 'Session save path'
$SessionSavePath = $sessionItem.FullName.TrimEnd('\')
$documentItem = Resolve-RegularItem -Path $DocumentRoot -Container $true -Label 'Document root'
$previewItem = Resolve-RegularItem -Path $PresentationPreviewRoot -Container $true -Label 'Presentation preview root'
$runtimeItem = Resolve-RegularItem -Path $PresentationRuntimeRoot -Container $true -Label 'Presentation runtime root'
foreach ($dataRoot in @($SessionSavePath, $documentItem.FullName, $previewItem.FullName, $runtimeItem.FullName)) {
    Assert-SeparateDirectoryTrees -First $ProjectRoot -Second $dataRoot -Label 'Application and data'
}
Assert-SeparateDirectoryTrees -First $documentItem.FullName -Second $previewItem.FullName -Label 'Document and preview'
Assert-SeparateDirectoryTrees -First $documentItem.FullName -Second $runtimeItem.FullName -Label 'Document and preview runtime'
Assert-SeparateDirectoryTrees -First $previewItem.FullName -Second $runtimeItem.FullName -Label 'Preview and preview runtime'

$signalScript = Join-Path $ProjectRoot 'scripts\signal_presentation_preview_queue.php'
$taskDependencies = @(
    $signalScript,
    $PhpPath,
    (Join-Path $ProjectRoot 'scripts\signal_presentation_preview_queue.ps1'),
    (Join-Path $ProjectRoot 'scripts\process_presentation_preview_queue.php'),
    (Join-Path $ProjectRoot 'scripts\presentation_preview_worker.php'),
    (Join-Path $ProjectRoot 'app\bootstrap.php'),
    (Join-Path $ProjectRoot 'app\presentation.php'),
    (Join-Path $ProjectRoot 'app\presentation_queue_trigger.php'),
    (Join-Path $ProjectRoot 'index.php')
)
Assert-TaskFileAcl -Paths $taskDependencies

try {
    $appPoolAccount = New-Object Security.Principal.NTAccount("IIS APPPOOL\$AppPoolName")
    $appPoolSid = $appPoolAccount.Translate([Security.Principal.SecurityIdentifier])
}
catch {
    throw "The IIS AppPool virtual identity could not be resolved: $AppPoolName"
}

if ($TaskIdentity -eq 'LocalSystem') {
    $taskUserId = 'NT AUTHORITY\SYSTEM'
    $taskSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
    $taskIdentityLabel = 'LOCAL SYSTEM'
}
else {
    $taskUserId = 'NT AUTHORITY\LOCAL SERVICE'
    $taskSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-19')
    $taskIdentityLabel = 'LOCAL SERVICE'
}

$signalRoot = Join-Path $SessionSavePath 'presentation-preview-trigger'
if (-not (Test-Path -LiteralPath $signalRoot)) {
    [void] [IO.Directory]::CreateDirectory($signalRoot)
}
$signalRootItem = Resolve-RegularItem -Path $signalRoot -Container $true -Label 'Presentation trigger directory'
Set-TriggerDirectoryAcl `
    -Path $signalRootItem.FullName `
    -AppPoolSid $appPoolSid `
    -TaskSid $taskSid
# LibreOffice is intentionally launched as the AppPool identity, never as
# SYSTEM/LOCAL SERVICE. Grant only the filesystem rights needed by conversion.
Grant-AppPoolDirectoryAccess `
    -Path $documentItem.FullName `
    -AppPoolSid $appPoolSid `
    -Rights ([Security.AccessControl.FileSystemRights]::ReadAndExecute)
Grant-AppPoolDirectoryAccess `
    -Path $previewItem.FullName `
    -AppPoolSid $appPoolSid `
    -Rights ([Security.AccessControl.FileSystemRights]::Modify)
Grant-AppPoolDirectoryAccess `
    -Path $runtimeItem.FullName `
    -AppPoolSid $appPoolSid `
    -Rights ([Security.AccessControl.FileSystemRights]::Modify)
if ($TaskIdentity -eq 'LocalService') {
    Grant-LocalServiceRead -Path $ProjectRoot
    Grant-LocalServiceRead -Path (Join-Path $ProjectRoot 'scripts')
    Grant-LocalServiceRead -Path $signalScript
}

Import-Module ScheduledTasks
$existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($null -ne $existingTask -and -not $ReplaceExisting) {
    throw "Scheduled task already exists. Rerun with -ReplaceExisting after reviewing it: $TaskName"
}

$phpItem = Resolve-RegularItem -Path $PhpPath -Container $false -Label 'PHP CLI executable'
$PhpPath = $phpItem.FullName
$null = & $PhpPath -n -l $signalScript 2>&1
if ($LASTEXITCODE -ne 0) {
    throw 'PHP wake script syntax check failed.'
}
$principal = New-ScheduledTaskPrincipal `
    -UserId $taskUserId `
    -LogonType ServiceAccount `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries `
    -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 6)

# Prove that the selected task identity can create the marker and that the loopback request
# reaches the intended IIS AppPool, whose inherited environment passes the
# non-mutating PHP/SQL/LibreOffice health check.
$validationId = [Guid]::NewGuid().ToString('N')
$validationTaskName = $TaskName + ' validation ' + $validationId.Substring(0, 8)
$validationResultPath = Join-Path $signalRootItem.FullName ("presentation-preview-validation-$validationId.json")

try {
    # Precreate the protected result so an early task-identity failure leaves a
    # deterministic stage code instead of an ambiguous missing-file result.
    New-ProtectedValidationResult `
        -Path $validationResultPath `
        -Code 'TASK_NOT_STARTED' `
        -TaskSid $taskSid
    $healthAction = New-QueueTaskAction `
        -PhpPath $PhpPath `
        -SignalScript $signalScript `
        -SignalRoot $signalRootItem.FullName `
        -ResultPath $validationResultPath `
        -HealthCheck
    $validationSettings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 2)
    $validationTask = New-ScheduledTask `
        -Action $healthAction `
        -Principal $principal `
        -Settings $validationSettings `
        -Description 'One-time TWWATER presentation queue loopback health check.'
    [void] (Register-ScheduledTask -TaskName $validationTaskName -InputObject $validationTask)
    $before = (Get-ScheduledTaskInfo -TaskName $validationTaskName).LastRunTime
    Start-ScheduledTask -TaskName $validationTaskName
    $deadline = [DateTime]::UtcNow.AddSeconds($HealthCheckTimeoutSeconds)
    do {
        Start-Sleep -Milliseconds 250
        $state = (Get-ScheduledTask -TaskName $validationTaskName).State
        $info = Get-ScheduledTaskInfo -TaskName $validationTaskName
        $completed = $info.LastRunTime -gt $before -and $state -ne 'Running'
    } while (-not $completed -and [DateTime]::UtcNow -lt $deadline)

    if (-not $completed) {
        throw ("The $taskIdentityLabel loopback health check timed out.")
    }
    $validationStatus = ''
    $validationCode = 'VALIDATION_RESULT_MISSING'
    if (Test-Path -LiteralPath $validationResultPath) {
        $validationResultItem = Get-Item -LiteralPath $validationResultPath -Force
        if (-not $validationResultItem.PSIsContainer -and
            (($validationResultItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0) -and
            $validationResultItem.Length -ge 24 -and $validationResultItem.Length -le 512) {
            try {
                $validationEncoding = New-Object Text.UTF8Encoding($false, $true)
                $validationJson = [IO.File]::ReadAllText($validationResultItem.FullName, $validationEncoding)
                $validationPayload = $validationJson | ConvertFrom-Json -ErrorAction Stop
                $validationProperties = @($validationPayload.PSObject.Properties.Name)
                $candidateStatus = [string] $validationPayload.status
                $candidateCode = [string] $validationPayload.code
                if ($validationProperties.Count -eq 2 -and
                    $validationProperties -contains 'status' -and
                    $validationProperties -contains 'code' -and
                    $candidateStatus -in @('ready', 'failed') -and
                    $candidateCode -match '\A[A-Z][A-Z0-9_]{2,63}\z') {
                    $validationStatus = $candidateStatus
                    $validationCode = $candidateCode
                }
                else {
                    $validationCode = 'VALIDATION_RESULT_INVALID'
                }
            }
            catch {
                $validationCode = 'VALIDATION_RESULT_INVALID'
            }
        }
        else {
            $validationCode = 'VALIDATION_RESULT_INVALID'
        }
    }
    if ([int64]$info.LastTaskResult -ne 0) {
        throw ('The {0} loopback health check failed [{1}] (Task result {2}).' -f $taskIdentityLabel, $validationCode, $info.LastTaskResult)
    }
    if ($validationStatus -ne 'ready' -or $validationCode -ne 'READY') {
        throw ('The {0} loopback health check returned an invalid result [{1}].' -f $taskIdentityLabel, $validationCode)
    }
}
finally {
    $validationTaskForCleanup = Get-ScheduledTask -TaskName $validationTaskName -ErrorAction SilentlyContinue
    if ($null -ne $validationTaskForCleanup -and $validationTaskForCleanup.State -eq 'Running') {
        Stop-ScheduledTask -TaskName $validationTaskName -ErrorAction SilentlyContinue
        $cleanupDeadline = [DateTime]::UtcNow.AddSeconds(5)
        do {
            Start-Sleep -Milliseconds 100
            $validationTaskForCleanup = Get-ScheduledTask -TaskName $validationTaskName -ErrorAction SilentlyContinue
        } while ($null -ne $validationTaskForCleanup -and
            $validationTaskForCleanup.State -eq 'Running' -and
            [DateTime]::UtcNow -lt $cleanupDeadline)
    }
    Unregister-ScheduledTask -TaskName $validationTaskName -Confirm:$false -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $validationResultPath) {
        Remove-Item -LiteralPath $validationResultPath -Force -ErrorAction SilentlyContinue
    }
}

$action = New-QueueTaskAction `
    -PhpPath $PhpPath `
    -SignalScript $signalScript `
    -SignalRoot $signalRootItem.FullName
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$task = New-ScheduledTask `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Wakes the TWWATER IIS AppPool presentation preview queue over loopback HTTPS. Contains no credential.'

[void] (Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force:$ReplaceExisting)

Write-Output "[OK] Scheduled task installed: $TaskName"
Write-Output "[OK] Identity: $taskUserId; interval: $IntervalMinutes minute(s)."
Write-Output '[OK] No database password, portal credential, token, or cookie was stored in the task or its arguments.'
