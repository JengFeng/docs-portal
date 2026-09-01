[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $SourceDocumentRoot,

    [ValidateNotNullOrEmpty()]
    [string] $JunctionPath = 'C:\TWWATER\documents',

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

function Find-EnvironmentVariableElement {
    param(
        [Parameter(Mandatory)] $Collection,
        [Parameter(Mandatory)] [string] $Name
    )

    foreach ($element in $Collection) {
        if ($element.ElementTagName -eq 'add' -and [string]::Equals([string] $element.GetAttributeValue('name'), $Name, [StringComparison]::OrdinalIgnoreCase)) {
            return $element
        }
    }
    return $null
}

function Set-AppPoolEnvironmentVariable {
    param(
        [Parameter(Mandatory)] $Collection,
        [Parameter(Mandatory)] [string] $Name,
        [Parameter(Mandatory)] [AllowEmptyString()] [string] $Value
    )

    $element = Find-EnvironmentVariableElement -Collection $Collection -Name $Name
    if ($null -eq $element) {
        $element = $Collection.CreateElement('add')
        $element['name'] = $Name
        $element['value'] = $Value
        [void] $Collection.Add($element)
        return
    }
    $element['value'] = $Value
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

$source = Get-Item -LiteralPath $SourceDocumentRoot -Force -ErrorAction Stop
if (-not $source.PSIsContainer) {
    throw 'SourceDocumentRoot must be an existing directory.'
}
$sourcePath = $source.FullName.TrimEnd('\', '/')

$parentPath = Split-Path -Parent $JunctionPath
if ([string]::IsNullOrWhiteSpace($parentPath) -or -not (Test-Path -LiteralPath $parentPath -PathType Container)) {
    throw "The JunctionPath parent directory does not exist: $parentPath"
}

if (Test-Path -LiteralPath $JunctionPath) {
    $existing = Get-Item -LiteralPath $JunctionPath -Force -ErrorAction Stop
    $isJunction = $existing.PSIsContainer -and [bool] ($existing.Attributes -band [IO.FileAttributes]::ReparsePoint)
    if (-not $isJunction) {
        throw "JunctionPath already exists and is not a junction: $JunctionPath"
    }
    $existingTarget = ([string] $existing.Target).TrimEnd('\', '/')
    if (-not [string]::Equals($existingTarget, $sourcePath, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'JunctionPath already points to a different target. No existing path was changed.'
    }
}
else {
    New-Item -ItemType Junction -Path $JunctionPath -Target $sourcePath -ErrorAction Stop | Out-Null
}

Import-Module WebAdministration -ErrorAction Stop
$administrationAssembly = Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll'
if ($null -eq ('Microsoft.Web.Administration.ServerManager' -as [type])) {
    if (-not (Test-Path -LiteralPath $administrationAssembly -PathType Leaf)) {
        throw "IIS administration assembly was not found: $administrationAssembly"
    }
    Add-Type -Path $administrationAssembly
}

$serverManager = $null
try {
    $serverManager = New-Object -TypeName Microsoft.Web.Administration.ServerManager
    $configuration = $serverManager.GetApplicationHostConfiguration()
    $applicationPools = $configuration.GetSection('system.applicationHost/applicationPools').GetCollection()
    $appPoolElement = $null
    foreach ($element in $applicationPools) {
        if ($element.ElementTagName -eq 'add' -and [string]::Equals([string] $element.GetAttributeValue('name'), $AppPoolName, [StringComparison]::OrdinalIgnoreCase)) {
            $appPoolElement = $element
            break
        }
    }
    if ($null -eq $appPoolElement) {
        throw "IIS AppPool was not found: $AppPoolName"
    }

    $environmentVariables = $appPoolElement.GetCollection('environmentVariables')
    Set-AppPoolEnvironmentVariable -Collection $environmentVariables -Name 'PORTAL_DOCUMENT_ROOT' -Value $JunctionPath
    $serverManager.CommitChanges()
}
finally {
    if ($null -ne $serverManager) {
        $serverManager.Dispose()
    }
}

Restart-WebAppPool -Name $AppPoolName
Write-Host '[OK] A local junction now points to the original document source; no files were copied.'
Write-Host "[OK] PORTAL_DOCUMENT_ROOT was updated and $AppPoolName was recycled."
