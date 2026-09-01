[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $ProjectRoot,

    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $DocumentRoot,

    [string[]] $AncestorPaths = @(),

    [ValidateNotNullOrEmpty()]
    [string] $AppPoolName = 'TWWATER_PortalPool'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-AppPoolRuntimeIdentity($AppPool, [string] $PoolName) {
    $identityType = [string] $AppPool.processModel.identityType
    switch ($identityType) {
        'ApplicationPoolIdentity' { return "IIS AppPool\$PoolName" }
        'NetworkService' { return 'NT AUTHORITY\NETWORK SERVICE' }
        'LocalService' { return 'NT AUTHORITY\LOCAL SERVICE' }
        'LocalSystem' { return 'NT AUTHORITY\SYSTEM' }
        'SpecificUser' {
            $userName = [string] $AppPool.processModel.userName
            if ([string]::IsNullOrWhiteSpace($userName)) {
                throw "The $PoolName AppPool is configured with SpecificUser but has no user name."
            }
            return $userName
        }
        default { throw "Unsupported IIS AppPool identity type: $identityType" }
    }
}

function Invoke-IcaclsGrant([string] $Path, [string] $Grant, [string[]] $AdditionalArguments = @()) {
    $arguments = @($Path, '/grant', $Grant) + $AdditionalArguments
    & "$env:windir\System32\icacls.exe" @arguments | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "ICACLS failed for the intended path. Exit code: $LASTEXITCODE"
    }
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

$projectItem = Get-Item -LiteralPath $ProjectRoot -Force -ErrorAction Stop
$documentItem = Get-Item -LiteralPath $DocumentRoot -Force -ErrorAction Stop
if (-not $projectItem.PSIsContainer -or -not $documentItem.PSIsContainer) {
    throw 'ProjectRoot and DocumentRoot must both be existing directories.'
}

$projectPath = $projectItem.FullName.TrimEnd('\', '/')
$documentPath = $documentItem.FullName.TrimEnd('\', '/')
$projectPrefix = $projectPath + [IO.Path]::DirectorySeparatorChar
if (-not $documentPath.StartsWith($projectPrefix, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'DocumentRoot must be located beneath ProjectRoot.'
}

$resolvedAncestorPaths = @()
foreach ($ancestorPath in $AncestorPaths) {
    if ([string]::IsNullOrWhiteSpace($ancestorPath)) {
        throw 'AncestorPaths cannot contain an empty path.'
    }
    $ancestorItem = Get-Item -LiteralPath $ancestorPath -Force -ErrorAction Stop
    if (-not $ancestorItem.PSIsContainer) {
        throw "AncestorPath must be an existing directory: $ancestorPath"
    }
    $resolvedAncestor = $ancestorItem.FullName.TrimEnd('\', '/')
    $ancestorPrefix = $resolvedAncestor + [IO.Path]::DirectorySeparatorChar
    if (-not $projectPath.StartsWith($ancestorPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "AncestorPath is not an ancestor of ProjectRoot: $resolvedAncestor"
    }
    if ($resolvedAncestorPaths -notcontains $resolvedAncestor) {
        $resolvedAncestorPaths += $resolvedAncestor
    }
}

Import-Module WebAdministration -ErrorAction Stop
if (-not (Test-Path -LiteralPath "IIS:\AppPools\$AppPoolName")) {
    throw "IIS AppPool was not found: $AppPoolName"
}
$appPool = Get-Item -LiteralPath "IIS:\AppPools\$AppPoolName"
$runtimeIdentity = Get-AppPoolRuntimeIdentity -AppPool $appPool -PoolName $AppPoolName

# Traverse-only on explicitly approved ancestors; no inheritance or listing.
foreach ($ancestorPath in $resolvedAncestorPaths) {
    Invoke-IcaclsGrant -Path $ancestorPath -Grant "${runtimeIdentity}:(X)"
}

# Traverse-only on the project root; no inheritance and no directory listing.
Invoke-IcaclsGrant -Path $projectPath -Grant "${runtimeIdentity}:(X)"

# Read-only access is limited to the intended document subtree.
Invoke-IcaclsGrant -Path $documentPath -Grant "${runtimeIdentity}:(OI)(CI)RX" -AdditionalArguments @('/T', '/C')

Restart-WebAppPool -Name $AppPoolName
Write-Host "[OK] Traverse-only access was granted on $($resolvedAncestorPaths.Count) approved ancestor path(s) and ProjectRoot."
Write-Host '[OK] Read-only access was confirmed on DocumentRoot and the AppPool was recycled.'
