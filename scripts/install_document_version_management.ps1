[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()][string] $VersionRoot = 'C:\TWWATER\document-versions',
    [AllowEmptyString()][string] $CacheRoot = '',
    [ValidateNotNullOrEmpty()][string] $AppPoolName = 'TWWATER_PortalPool',
    [ValidateNotNullOrEmpty()][string] $BridgeTaskName = 'TWWATER Document Bridge'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$cacheAclRepairPath = Join-Path $PSScriptRoot 'cache_acl_repair.ps1'
if (-not (Test-Path -LiteralPath $cacheAclRepairPath -PathType Leaf)) { throw 'Cache ACL repair helper is missing.' }
. $cacheAclRepairPath

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function ConvertTo-Sid {
    param([Parameter(Mandatory)] $Identity)
    if ($Identity -is [Security.Principal.SecurityIdentifier]) { return $Identity }
    $text = [string]$Identity
    if ($text.StartsWith('S-1-', [StringComparison]::OrdinalIgnoreCase)) {
        return New-Object Security.Principal.SecurityIdentifier($text)
    }
    return (New-Object Security.Principal.NTAccount($text)).Translate([Security.Principal.SecurityIdentifier])
}

function Get-AppPoolIdentity {
    param([string] $Name)
    Import-Module WebAdministration -ErrorAction Stop
    $pool = Get-Item -LiteralPath ("IIS:\AppPools\$Name") -ErrorAction Stop
    switch ([string]$pool.processModel.identityType) {
        'ApplicationPoolIdentity' { return "IIS AppPool\$Name" }
        'NetworkService' { return 'NT AUTHORITY\NETWORK SERVICE' }
        'LocalService' { return 'NT AUTHORITY\LOCAL SERVICE' }
        'LocalSystem' { return 'NT AUTHORITY\SYSTEM' }
        'SpecificUser' {
            if ([string]::IsNullOrWhiteSpace([string]$pool.processModel.userName)) { throw 'AppPool specific identity is empty.' }
            return [string]$pool.processModel.userName
        }
        default { throw 'Unsupported AppPool identity type.' }
    }
}

function Set-VersionRootAcl {
    param([string] $Path, [string] $BridgeIdentity, [string] $AppPoolIdentity)
    $owner = ConvertTo-Sid $BridgeIdentity
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($owner)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    $propagation = [Security.AccessControl.PropagationFlags]::None
    $allow = [Security.AccessControl.AccessControlType]::Allow
    foreach ($entry in @(
        [pscustomobject]@{ Identity = $BridgeIdentity; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        [pscustomobject]@{ Identity = 'S-1-5-32-544'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        [pscustomobject]@{ Identity = 'S-1-5-18'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        [pscustomobject]@{ Identity = $AppPoolIdentity; Rights = [Security.AccessControl.FileSystemRights]::ReadAndExecute }
    )) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule((ConvertTo-Sid $entry.Identity), $entry.Rights, $inheritance, $propagation, $allow)
        [void]$acl.AddAccessRule($rule)
    }
    [IO.Directory]::SetAccessControl($Path, $acl)
}

function Get-AppPoolEnvironmentVariable {
    param([Parameter(Mandatory)][string] $PoolName, [Parameter(Mandatory)][string] $Name)
    Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll') -ErrorAction SilentlyContinue
    $manager = New-Object Microsoft.Web.Administration.ServerManager
    try {
        $pool = $manager.ApplicationPools[$PoolName]
        if ($null -eq $pool) { throw 'IIS AppPool was not found.' }
        foreach ($entry in $pool.GetChildElement('environmentVariables').GetCollection()) {
            if ([string]$entry['name'] -ceq $Name) { return [string]$entry['value'] }
        }
        return ''
    }
    finally { $manager.Dispose() }
}

function Set-AppPoolEnvironmentVariable {
    param([string] $PoolName, [string] $Name, [string] $Value)
    $assembly = Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll'
    if ($null -eq ('Microsoft.Web.Administration.ServerManager' -as [type])) { Add-Type -Path $assembly }
    $manager = New-Object Microsoft.Web.Administration.ServerManager
    try {
        $pools = $manager.GetApplicationHostConfiguration().GetSection('system.applicationHost/applicationPools').GetCollection()
        $pool = $null
        foreach ($element in $pools) {
            if ($element.ElementTagName -eq 'add' -and [string]::Equals([string]$element.GetAttributeValue('name'), $PoolName, [StringComparison]::OrdinalIgnoreCase)) { $pool = $element; break }
        }
        if ($null -eq $pool) { throw 'IIS AppPool was not found.' }
        $variables = $pool.GetCollection('environmentVariables')
        $target = $null
        foreach ($variable in $variables) {
            if ($variable.ElementTagName -eq 'add' -and [string]::Equals([string]$variable.GetAttributeValue('name'), $Name, [StringComparison]::OrdinalIgnoreCase)) { $target = $variable; break }
        }
        if ($null -eq $target) {
            $target = $variables.CreateElement('add')
            $target['name'] = $Name
            $target['value'] = $Value
            [void]$variables.Add($target)
        }
        else {
            $target['value'] = $Value
        }
        $manager.CommitChanges()
    }
    finally { $manager.Dispose() }
}

function Set-BridgeTaskQuotedArgument {
    param(
        [Parameter(Mandatory)][string] $Arguments,
        [Parameter(Mandatory)][string] $Token,
        [Parameter(Mandatory)][string] $Value
    )
    if ($Value.Contains('"') -or $Value.IndexOf([char]0) -ge 0) { throw 'Task argument contains an unsupported character.' }
    $pattern = '(?i)(' + [regex]::Escape($Token) + '\s+)"[^"]*"'
    if ([regex]::IsMatch($Arguments, $pattern)) {
        return [regex]::Replace($Arguments, $pattern, { param($match) $match.Groups[1].Value + '"' + $Value + '"' }, 1)
    }
    return $Arguments.TrimEnd() + ' ' + $Token + ' "' + $Value + '"'
}

if (-not (Test-IsAdministrator)) { throw 'Run this installer from an elevated PowerShell session.' }
if (-not (Test-Path -LiteralPath $VersionRoot)) { [void][IO.Directory]::CreateDirectory($VersionRoot) }
$versionItem = Get-Item -LiteralPath $VersionRoot -Force
if (-not $versionItem.PSIsContainer -or [bool]($versionItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'VersionRoot must be a real directory.' }
$VersionRoot = $versionItem.FullName.TrimEnd('\', '/')
if ([string]::IsNullOrWhiteSpace($CacheRoot)) {
    $CacheRoot = Get-AppPoolEnvironmentVariable -PoolName $AppPoolName -Name 'PORTAL_DOCUMENT_ROOT'
}
if ([string]::IsNullOrWhiteSpace($CacheRoot)) { throw 'PORTAL_DOCUMENT_ROOT is not configured for the IIS AppPool.' }
$cacheItem = Get-Item -LiteralPath $CacheRoot -Force -ErrorAction Stop
if (-not $cacheItem.PSIsContainer -or [bool]($cacheItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'CacheRoot must be a real directory.' }
$CacheRoot = $cacheItem.FullName.TrimEnd('\', '/')
if ([string]::Equals($CacheRoot, $VersionRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'CacheRoot and VersionRoot must be separate.' }
$cacheAclRepair = Repair-TWWaterCacheChildAclInheritance -CacheRoot $CacheRoot
$bridgeIdentity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$appPoolIdentity = Get-AppPoolIdentity $AppPoolName
Set-VersionRootAcl -Path $VersionRoot -BridgeIdentity $bridgeIdentity -AppPoolIdentity $appPoolIdentity
Set-AppPoolEnvironmentVariable -PoolName $AppPoolName -Name 'PORTAL_DOCUMENT_VERSION_ROOT' -Value $VersionRoot

$task = Get-ScheduledTask -TaskName $BridgeTaskName -ErrorAction Stop
$bridgeActions = @($task.Actions | Where-Object { [string]$_.Arguments -like '*document_bridge.ps1*' })
if ($bridgeActions.Count -ne 1) { throw 'Existing bridge task does not point to document_bridge.ps1.' }
$bridgeAction = $bridgeActions[0]
$taskArguments = Set-BridgeTaskQuotedArgument -Arguments ([string]$bridgeAction.Arguments) -Token '-CacheRoot' -Value $CacheRoot
$taskArguments = Set-BridgeTaskQuotedArgument -Arguments $taskArguments -Token '-VersionRoot' -Value $VersionRoot
if ([string]::IsNullOrWhiteSpace([string]$bridgeAction.WorkingDirectory)) {
    $newAction = New-ScheduledTaskAction -Execute ([string]$bridgeAction.Execute) -Argument $taskArguments
}
else {
    $newAction = New-ScheduledTaskAction -Execute ([string]$bridgeAction.Execute) -Argument $taskArguments -WorkingDirectory ([string]$bridgeAction.WorkingDirectory)
}
Set-ScheduledTask -TaskName $BridgeTaskName -Action $newAction | Out-Null
Enable-ScheduledTask -TaskName $BridgeTaskName | Out-Null
$currentTaskState = [string]((Get-ScheduledTask -TaskName $BridgeTaskName).State)
if ($currentTaskState -eq 'Running') {
    Stop-ScheduledTask -TaskName $BridgeTaskName
    $stopDeadline = [DateTime]::UtcNow.AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $currentTaskState = [string]((Get-ScheduledTask -TaskName $BridgeTaskName).State)
    } while ($currentTaskState -eq 'Running' -and [DateTime]::UtcNow -lt $stopDeadline)
    if ($currentTaskState -eq 'Running') { throw 'Document bridge task did not stop before restart.' }
}
$taskLaunchUtc = [DateTime]::UtcNow
Start-ScheduledTask -TaskName $BridgeTaskName
$deadline = [DateTime]::UtcNow.AddSeconds(20)
do {
    Start-Sleep -Milliseconds 500
    $taskState = [string]((Get-ScheduledTask -TaskName $BridgeTaskName).State)
} while ($taskState -ne 'Running' -and [DateTime]::UtcNow -lt $deadline)
if ($taskState -ne 'Running') { throw 'Document bridge task did not start.' }
$taskStableDeadline = [DateTime]::UtcNow.AddSeconds(5)
do {
    Start-Sleep -Milliseconds 500
    $taskState = [string]((Get-ScheduledTask -TaskName $BridgeTaskName).State)
    if ($taskState -ne 'Running') {
        $lastResult = [int64](Get-ScheduledTaskInfo -TaskName $BridgeTaskName).LastTaskResult
        throw "Document bridge task exited during startup verification (result $lastResult)."
    }
} while ([DateTime]::UtcNow -lt $taskStableDeadline)

Restart-WebAppPool -Name $AppPoolName
$poolState = [string](Get-WebAppPoolState -Name $AppPoolName).Value
if ($poolState -ne 'Started') { throw 'IIS AppPool did not return to Started.' }

[ordered]@{
    ok = $true
    version_root = $VersionRoot
    cache_root = $CacheRoot
    app_pool_state = $poolState
    bridge_task_state = $taskState
    cache_acl_repaired = [int]$cacheAclRepair.repaired
    iis_access = 'ReadAndExecute'
} | ConvertTo-Json -Compress
