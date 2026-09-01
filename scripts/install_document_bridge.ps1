[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()]
    [string] $SourceRoot = 'C:\Users\gisadmin\我的雲端硬碟\115供水監測',

    [ValidateNotNullOrEmpty()]
    [string] $CacheRoot = 'C:\TWWATER\document-cache',

    [ValidateNotNullOrEmpty()]
    [string] $RuntimeRoot = 'C:\TWWATER\runtime\document-bridge',

    [ValidateNotNullOrEmpty()]
    [string] $SessionRoot = 'C:\TWWATER\runtime\sessions',

    [ValidateNotNullOrEmpty()]
    [string] $ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string] $AppPoolName = 'TWWATER_PortalPool',

    [ValidateNotNullOrEmpty()]
    [string] $TaskName = 'TWWATER Document Bridge',

    [ValidateNotNullOrEmpty()]
    [string] $EntryUrl = 'https://aiwork.ddns.net/gary/TWWATER/?action=login',

    [ValidateRange(1, 60)]
    [int] $DebounceSeconds = 3,

    [ValidateRange(30, 86400)]
    [int] $FullReconcileSeconds = 300,

    [ValidateRange(1, 2000)]
    [int] $MaxDocuments = 2000,

    [switch] $DoNotStartTask
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-NormalizedFullPath {
    param([Parameter(Mandatory)][string] $Path)

    return [IO.Path]::GetFullPath($Path).TrimEnd('\', '/')
}

function Assert-RealDirectory {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Label
    )

    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer) {
        throw "$Label must be a directory."
    }
    if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "$Label must not be a Junction, symbolic link, or other reparse point."
    }
    return $item.FullName.TrimEnd('\', '/')
}

function Test-PathIsWithin {
    param(
        [Parameter(Mandatory)][string] $Parent,
        [Parameter(Mandatory)][string] $Candidate
    )

    $normalizedParent = Get-NormalizedFullPath -Path $Parent
    $normalizedCandidate = Get-NormalizedFullPath -Path $Candidate
    $prefix = $normalizedParent + [IO.Path]::DirectorySeparatorChar
    return $normalizedCandidate.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

function Test-PathEquals {
    param(
        [Parameter(Mandatory)][string] $First,
        [Parameter(Mandatory)][string] $Second
    )

    return [string]::Equals(
        (Get-NormalizedFullPath -Path $First),
        (Get-NormalizedFullPath -Path $Second),
        [StringComparison]::OrdinalIgnoreCase
    )
}

function Assert-SafeArgumentValue {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][string] $Value
    )

    if ($Value.IndexOf([char]0) -ge 0 -or $Value.Contains('"')) {
        throw "$Name contains an unsupported character."
    }
}

function Get-AppPoolRuntimeIdentity {
    param([Parameter(Mandatory)][string] $Name)

    Import-Module WebAdministration -ErrorAction Stop
    $poolPath = "IIS:\AppPools\$Name"
    if (-not (Test-Path -LiteralPath $poolPath)) {
        throw "IIS AppPool was not found: $Name"
    }
    $pool = Get-Item -LiteralPath $poolPath
    switch ([string]$pool.processModel.identityType) {
        'ApplicationPoolIdentity' { return "IIS AppPool\$Name" }
        'NetworkService' { return 'NT AUTHORITY\NETWORK SERVICE' }
        'LocalService' { return 'NT AUTHORITY\LOCAL SERVICE' }
        'LocalSystem' { return 'NT AUTHORITY\SYSTEM' }
        'SpecificUser' {
            $userName = [string]$pool.processModel.userName
            if ([string]::IsNullOrWhiteSpace($userName)) {
                throw "The $Name AppPool has no configured user name."
            }
            return $userName
        }
        default { throw "Unsupported IIS AppPool identity type: $($pool.processModel.identityType)" }
    }
}

function ConvertTo-SecurityIdentifier {
    param([Parameter(Mandatory)] $Identity)

    if ($Identity -is [Security.Principal.SecurityIdentifier]) {
        return $Identity
    }
    $identityText = [string]$Identity
    if ($identityText.StartsWith('S-1-', [StringComparison]::OrdinalIgnoreCase)) {
        return New-Object Security.Principal.SecurityIdentifier($identityText)
    }
    return (New-Object Security.Principal.NTAccount($identityText)).Translate([Security.Principal.SecurityIdentifier])
}

function Set-ProtectedRootAcl {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)] $OwnerIdentity,
        [Parameter(Mandatory)][object[]] $Entries
    )

    $ownerSid = ConvertTo-SecurityIdentifier -Identity $OwnerIdentity
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($ownerSid)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    $propagation = [Security.AccessControl.PropagationFlags]::None
    $allow = [Security.AccessControl.AccessControlType]::Allow
    foreach ($entry in $Entries) {
        $sid = ConvertTo-SecurityIdentifier -Identity $entry.Identity
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, $entry.Rights, $inheritance, $propagation, $allow)
        [void]$acl.AddAccessRule($rule)
    }
    [IO.Directory]::SetAccessControl($Path, $acl)
}

function Set-DirectoryAcl {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $BridgeUser,
        [Parameter(Mandatory)][string] $AppPoolIdentity
    )

    # Protect only the cache root. Existing children remain inheritance-enabled
    # and receive these rules from the root; never protect every child with
    # ICACLS /inheritance:r /T because that can strand an unreadable sentinel.
    Set-ProtectedRootAcl -Path $Path -OwnerIdentity $BridgeUser -Entries @(
        @{ Identity = $BridgeUser; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = 'S-1-5-32-544'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = 'S-1-5-18'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = $AppPoolIdentity; Rights = [Security.AccessControl.FileSystemRights]::ReadAndExecute }
    )
}

function Set-RuntimeDirectoryAcl {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $BridgeUser
    )

    Set-ProtectedRootAcl -Path $Path -OwnerIdentity $BridgeUser -Entries @(
        @{ Identity = $BridgeUser; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = 'S-1-5-32-544'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = 'S-1-5-18'; Rights = [Security.AccessControl.FileSystemRights]::FullControl }
    )
}

function Grant-SessionModify {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $BridgeUser,
        [Parameter(Mandatory)][string] $AppPoolIdentity
    )

    Set-ProtectedRootAcl -Path $Path -OwnerIdentity $BridgeUser -Entries @(
        @{ Identity = $BridgeUser; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = 'S-1-5-32-544'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = 'S-1-5-18'; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
        @{ Identity = $AppPoolIdentity; Rights = [Security.AccessControl.FileSystemRights]::Modify }
    )
}

function Find-EnvironmentVariableElement {
    param(
        [Parameter(Mandatory)] $Collection,
        [Parameter(Mandatory)][string] $Name
    )

    foreach ($element in $Collection) {
        if ($element.ElementTagName -ne 'add') {
            continue
        }
        if ([string]::Equals([string]$element.GetAttributeValue('name'), $Name, [StringComparison]::OrdinalIgnoreCase)) {
            return $element
        }
    }
    return $null
}

function Set-PortalDocumentRoot {
    param(
        [Parameter(Mandatory)][string] $PoolName,
        [Parameter(Mandatory)][string] $DocumentRoot,
        [Parameter(Mandatory)][string] $StatePath
    )

    $assembly = Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll'
    if ($null -eq ('Microsoft.Web.Administration.ServerManager' -as [type])) {
        if (-not (Test-Path -LiteralPath $assembly -PathType Leaf)) {
            throw "IIS administration assembly was not found: $assembly"
        }
        Add-Type -Path $assembly
    }
    $manager = New-Object -TypeName Microsoft.Web.Administration.ServerManager
    try {
        $configuration = $manager.GetApplicationHostConfiguration()
        $pools = $configuration.GetSection('system.applicationHost/applicationPools').GetCollection()
        $poolElement = $null
        foreach ($element in $pools) {
            if ($element.ElementTagName -eq 'add' -and [string]::Equals([string]$element.GetAttributeValue('name'), $PoolName, [StringComparison]::OrdinalIgnoreCase)) {
                $poolElement = $element
                break
            }
        }
        if ($null -eq $poolElement) {
            throw "IIS AppPool was not found: $PoolName"
        }
        $variables = $poolElement.GetCollection('environmentVariables')
        $target = Find-EnvironmentVariableElement -Collection $variables -Name 'PORTAL_DOCUMENT_ROOT'
        $previous = if ($null -eq $target) { '' } else { [string]$target.GetAttributeValue('value') }
        if ($null -eq $target) {
            $target = $variables.CreateElement('add')
            $target['name'] = 'PORTAL_DOCUMENT_ROOT'
            [void]$variables.Add($target)
        }
        $target['value'] = $DocumentRoot
        $manager.CommitChanges()
        if (-not (Test-Path -LiteralPath $StatePath -PathType Leaf)) {
            [IO.File]::WriteAllText($StatePath, $previous, (New-Object Text.UTF8Encoding($false)))
        }
        return $previous
    }
    finally {
        $manager.Dispose()
    }
}

function Wait-PortalIndex {
    param(
        [Parameter(Mandatory)][string] $Url,
        [Parameter(Mandatory)][string] $SessionPath,
        [ValidateRange(5, 120)][int] $TimeoutSeconds = 45
    )

    $pendingPath = Join-Path $SessionPath 'document-bridge.pending'
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    $lastWakeFailure = ''
    while ([DateTime]::UtcNow -lt $deadline) {
        if (-not (Test-Path -LiteralPath $pendingPath -PathType Leaf)) {
            Write-Host '[OK] The portal consumed the bridge generation and completed its document index.'
            return
        }
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -Method Get -TimeoutSec 20
            $lastWakeFailure = "HTTP $([int]$response.StatusCode)"
        }
        catch {
            $lastWakeFailure = $_.Exception.GetType().Name
        }
        Start-Sleep -Seconds 2
    }
    throw "The portal did not consume the pending document generation within $TimeoutSeconds seconds. Last wake result: $lastWakeFailure"
}

function ConvertTo-TaskQuotedArgument {
    param([Parameter(Mandatory)][string] $Value)

    return '"' + $Value.Replace('"', '""') + '"'
}

function Register-BridgeTask {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][string] $UserId,
        [Parameter(Mandatory)][string] $PowerShellExecutable,
        [Parameter(Mandatory)][string] $BridgeScript,
        [Parameter(Mandatory)][hashtable] $Parameters
    )

    $existing = Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
    if ($null -ne $existing) {
        $existingUserSid = ConvertTo-SecurityIdentifier -Identity ([string]$existing.Principal.UserId)
        $requestedUserSid = ConvertTo-SecurityIdentifier -Identity $UserId
        if (-not $existingUserSid.Equals($requestedUserSid)) {
            throw "Scheduled task '$Name' belongs to another user and was not changed."
        }
    }
    $tokens = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (ConvertTo-TaskQuotedArgument -Value $BridgeScript))
    foreach ($key in @('SourceRoot', 'CacheRoot', 'RuntimeRoot', 'SessionRoot', 'EntryUrl', 'DebounceSeconds', 'FullReconcileSeconds', 'MaxDocuments')) {
        $tokens += "-$key"
        $tokens += ConvertTo-TaskQuotedArgument -Value ([string]$Parameters[$key])
    }
    $action = New-ScheduledTaskAction -Execute $PowerShellExecutable -Argument ($tokens -join ' ')
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $UserId
    $principal = New-ScheduledTaskPrincipal -UserId $UserId -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
}

function Assert-BridgeTaskRunning {
    param(
        [Parameter(Mandatory)][string] $Name,
        [ValidateRange(3, 30)][int] $TimeoutSeconds = 12
    )

    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        $task = Get-ScheduledTask -TaskName $Name -ErrorAction Stop
        if ([string]$task.State -eq 'Running') {
            Write-Host "[OK] Scheduled task '$Name' is running continuously."
            return
        }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)

    $info = Get-ScheduledTaskInfo -TaskName $Name -ErrorAction Stop
    throw "Scheduled task '$Name' exited instead of remaining active. State: $($task.State); LastTaskResult: $($info.LastTaskResult)."
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

foreach ($entry in @{
        SourceRoot = $SourceRoot; CacheRoot = $CacheRoot; RuntimeRoot = $RuntimeRoot; SessionRoot = $SessionRoot;
        ProjectRoot = $ProjectRoot; AppPoolName = $AppPoolName; TaskName = $TaskName; EntryUrl = $EntryUrl
    }.GetEnumerator()) {
    Assert-SafeArgumentValue -Name $entry.Key -Value ([string]$entry.Value)
}

$SourceRoot = Assert-RealDirectory -Path $SourceRoot -Label 'SourceRoot'
$SessionRoot = Assert-RealDirectory -Path $SessionRoot -Label 'SessionRoot'
$ProjectRoot = Assert-RealDirectory -Path $ProjectRoot -Label 'ProjectRoot'
if (Test-PathIsWithin -Parent $ProjectRoot -Candidate $CacheRoot) {
    throw 'CacheRoot must remain outside the website application root.'
}
if ($(Test-PathEquals -First $SourceRoot -Second $CacheRoot) -or $(Test-PathIsWithin -Parent $SourceRoot -Candidate $CacheRoot) -or $(Test-PathIsWithin -Parent $CacheRoot -Candidate $SourceRoot)) {
    throw 'SourceRoot and CacheRoot must be separate, non-nested directories.'
}

$bridgeScript = Join-Path $ProjectRoot 'scripts\document_bridge.ps1'
if (-not (Test-Path -LiteralPath $bridgeScript -PathType Leaf)) {
    throw "Document bridge script was not found: $bridgeScript"
}
$powerShellExecutable = Join-Path $env:windir 'System32\WindowsPowerShell\v1.0\powershell.exe'
if (-not (Test-Path -LiteralPath $powerShellExecutable -PathType Leaf)) {
    throw "PowerShell executable was not found: $powerShellExecutable"
}

$bridgeUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$appPoolIdentity = Get-AppPoolRuntimeIdentity -Name $AppPoolName
Write-Host "[INFO] Bridge task identity: $bridgeUser"
Write-Host "[INFO] IIS read-only identity: $appPoolIdentity"

# Phase 1: create and populate a new physical cache before IIS is pointed at it.
& $powerShellExecutable -NoProfile -ExecutionPolicy Bypass -File $bridgeScript `
    -SourceRoot $SourceRoot `
    -CacheRoot $CacheRoot `
    -RuntimeRoot $RuntimeRoot `
    -SessionRoot $SessionRoot `
    -EntryUrl $EntryUrl `
    -DebounceSeconds $DebounceSeconds `
    -FullReconcileSeconds $FullReconcileSeconds `
    -MaxDocuments $MaxDocuments `
    -Once -SkipWake -SkipSignal
if ($LASTEXITCODE -ne 0) {
    throw "Initial document bridge copy failed. IIS configuration was not changed. Exit code: $LASTEXITCODE"
}

$CacheRoot = Assert-RealDirectory -Path $CacheRoot -Label 'CacheRoot'
$RuntimeRoot = Assert-RealDirectory -Path $RuntimeRoot -Label 'RuntimeRoot'
Set-DirectoryAcl -Path $CacheRoot -BridgeUser $bridgeUser -AppPoolIdentity $appPoolIdentity
Set-RuntimeDirectoryAcl -Path $RuntimeRoot -BridgeUser $bridgeUser
Grant-SessionModify -Path $SessionRoot -BridgeUser $bridgeUser -AppPoolIdentity $appPoolIdentity

# Validate the completed cache through a new Windows PowerShell process after
# ACL hardening. IIS is not newly switched unless this independent validation
# can still read the sentinel and reconcile the cache.
& $powerShellExecutable -NoProfile -ExecutionPolicy Bypass -File $bridgeScript `
    -SourceRoot $SourceRoot `
    -CacheRoot $CacheRoot `
    -RuntimeRoot $RuntimeRoot `
    -SessionRoot $SessionRoot `
    -EntryUrl $EntryUrl `
    -DebounceSeconds $DebounceSeconds `
    -FullReconcileSeconds $FullReconcileSeconds `
    -MaxDocuments $MaxDocuments `
    -Once -SkipWake -SkipSignal
if ($LASTEXITCODE -ne 0) {
    throw "Post-ACL document cache validation failed. No new IIS document-root change was made. Exit code: $LASTEXITCODE"
}

$previousRootPath = Join-Path $RuntimeRoot 'previous-portal-document-root.txt'
Import-Module WebAdministration -ErrorAction Stop
$immediatePreviousRoot = $null
$portalRootChanged = $false
try {
    $immediatePreviousRoot = Set-PortalDocumentRoot -PoolName $AppPoolName -DocumentRoot $CacheRoot -StatePath $previousRootPath
    $portalRootChanged = $true
    Restart-WebAppPool -Name $AppPoolName

    # Phase 2: with IIS now reading only the complete cache, create a pending
    # generation and wake the existing portal endpoint to run the normal index.
    & $powerShellExecutable -NoProfile -ExecutionPolicy Bypass -File $bridgeScript `
        -SourceRoot $SourceRoot `
        -CacheRoot $CacheRoot `
        -RuntimeRoot $RuntimeRoot `
        -SessionRoot $SessionRoot `
        -EntryUrl $EntryUrl `
        -DebounceSeconds $DebounceSeconds `
        -FullReconcileSeconds $FullReconcileSeconds `
        -MaxDocuments $MaxDocuments `
        -Once
    if ($LASTEXITCODE -ne 0) {
        throw "Document bridge cache was activated, but its first portal index trigger failed. Check the bridge log. Exit code: $LASTEXITCODE"
    }
    Wait-PortalIndex -Url $EntryUrl -SessionPath $SessionRoot
}
catch {
    $deploymentError = $_
    if ($portalRootChanged) {
        if ([string]::IsNullOrWhiteSpace([string]$immediatePreviousRoot)) {
            throw "Document bridge deployment failed and the prior IIS document root was empty, so automatic rollback could not be completed. Original error: $($deploymentError.Exception.Message)"
        }
        try {
            [void](Set-PortalDocumentRoot -PoolName $AppPoolName -DocumentRoot ([string]$immediatePreviousRoot) -StatePath $previousRootPath)
            foreach ($markerName in @('document-bridge.pending', 'document-bridge.syncing')) {
                $markerPath = Join-Path $SessionRoot $markerName
                if (Test-Path -LiteralPath $markerPath -PathType Leaf) {
                    Remove-Item -LiteralPath $markerPath -Force -ErrorAction Stop
                }
            }
            Restart-WebAppPool -Name $AppPoolName
            Write-Warning "Deployment failed; PORTAL_DOCUMENT_ROOT was restored to $immediatePreviousRoot."
        }
        catch {
            throw "Document bridge deployment and automatic rollback both failed. Deployment error: $($deploymentError.Exception.Message) Rollback error: $($_.Exception.Message)"
        }
    }
    throw $deploymentError
}

$taskParameters = @{
    SourceRoot = $SourceRoot
    CacheRoot = $CacheRoot
    RuntimeRoot = $RuntimeRoot
    SessionRoot = $SessionRoot
    EntryUrl = $EntryUrl
    DebounceSeconds = $DebounceSeconds
    FullReconcileSeconds = $FullReconcileSeconds
    MaxDocuments = $MaxDocuments
}
Register-BridgeTask -Name $TaskName -UserId $bridgeUser -PowerShellExecutable $powerShellExecutable -BridgeScript $bridgeScript -Parameters $taskParameters
if (-not $DoNotStartTask) {
    Start-ScheduledTask -TaskName $TaskName
    Assert-BridgeTaskRunning -Name $TaskName
}

Write-Host '[OK] A physical IIS-readable document cache was created and populated.'
Write-Host "[OK] PORTAL_DOCUMENT_ROOT now points to $CacheRoot and $AppPoolName was recycled."
Write-Host "[OK] Scheduled task '$TaskName' is registered for $bridgeUser at logon without storing a password."
if (-not $DoNotStartTask) {
    Write-Host '[OK] The bridge task was started; it will watch the source directory after its initial reconciliation.'
}
Write-Host "[INFO] The prior Junction was not deleted. Its previous path was recorded at $previousRootPath"
