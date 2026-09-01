[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $DocumentRoot,

    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $SessionPath,

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

function Get-ExistingDirectory([string] $Path, [string] $Label) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer) {
        throw "$Label must be an existing directory."
    }
    return $item.FullName
}

function Grant-AppPoolDirectoryAccess([string] $Path, [string] $Identity, [string] $Rights, [string] $Label) {
    $grant = "${Identity}:(OI)(CI)$Rights"
    & "$env:windir\System32\icacls.exe" $Path '/grant' $grant '/T' '/C' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not grant $Label access. ICACLS exit code: $LASTEXITCODE"
    }
}

function Get-AppPoolRuntimeIdentity($AppPool, [string] $AppPoolName) {
    $identityType = [string] $AppPool.processModel.identityType
    switch ($identityType) {
        'ApplicationPoolIdentity' { return "IIS AppPool\$AppPoolName" }
        'NetworkService' { return 'NT AUTHORITY\NETWORK SERVICE' }
        'LocalService' { return 'NT AUTHORITY\LOCAL SERVICE' }
        'LocalSystem' { return 'NT AUTHORITY\SYSTEM' }
        'SpecificUser' {
            $userName = [string] $AppPool.processModel.userName
            if ([string]::IsNullOrWhiteSpace($userName)) {
                throw "The $AppPoolName AppPool is configured with SpecificUser but has no user name."
            }
            return $userName
        }
        default {
            throw "Unsupported IIS AppPool identity type: $identityType"
        }
    }
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

Import-Module WebAdministration -ErrorAction Stop
if (-not (Test-Path -LiteralPath "IIS:\AppPools\$AppPoolName")) {
    throw "IIS AppPool was not found: $AppPoolName"
}

$resolvedDocumentRoot = Get-ExistingDirectory -Path $DocumentRoot -Label 'DocumentRoot'
$resolvedSessionPath = Get-ExistingDirectory -Path $SessionPath -Label 'SessionPath'
$appPool = Get-Item -LiteralPath "IIS:\AppPools\$AppPoolName"
$appPoolIdentity = Get-AppPoolRuntimeIdentity -AppPool $appPool -AppPoolName $AppPoolName

# The portal only reads source documents; session files need Create/Modify/Delete.
Grant-AppPoolDirectoryAccess -Path $resolvedDocumentRoot -Identity $appPoolIdentity -Rights 'RX' -Label 'read-only document'
Grant-AppPoolDirectoryAccess -Path $resolvedSessionPath -Identity $appPoolIdentity -Rights 'M' -Label 'session runtime'

Restart-WebAppPool -Name $AppPoolName
Write-Host '[OK] Read-only document access was granted to the configured AppPool runtime identity.'
Write-Host "[OK] Session Modify access was granted and $AppPoolName was recycled."
