[CmdletBinding()]
param(
    [switch]$SkipHealthCheck,
    [string]$PhpPath,
    [string]$AppPoolName = 'TWWATER_PortalPool'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$originalProcessEnvironment = @{}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Find-EnvironmentVariableElement {
    param(
        [Parameter(Mandatory)]$Collection,
        [Parameter(Mandatory)][string]$Name
    )

    foreach ($element in $Collection) {
        if ($element.ElementTagName -eq 'add' -and [string]::Equals([string]$element.GetAttributeValue('name'), $Name, [StringComparison]::OrdinalIgnoreCase)) {
            return $element
        }
    }
    return $null
}

function Get-AppPoolEnvironmentSettings {
    param([Parameter(Mandatory)][string]$PoolName)

    Import-Module WebAdministration
    $administrationAssembly = Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll'
    if ($null -eq ('Microsoft.Web.Administration.ServerManager' -as [type])) {
        if (-not (Test-Path -LiteralPath $administrationAssembly -PathType Leaf)) {
            throw "IIS administration assembly was not found: $administrationAssembly"
        }
        Add-Type -Path $administrationAssembly
    }

    $serverManager = New-Object -TypeName Microsoft.Web.Administration.ServerManager
    try {
        $configuration = $serverManager.GetApplicationHostConfiguration()
        $appPoolElement = $null
        foreach ($element in $configuration.GetSection('system.applicationHost/applicationPools').GetCollection()) {
            if ($element.ElementTagName -eq 'add' -and [string]::Equals([string]$element.GetAttributeValue('name'), $PoolName, [StringComparison]::OrdinalIgnoreCase)) {
                $appPoolElement = $element
                break
            }
        }
        if ($null -eq $appPoolElement) {
            throw "IIS AppPool was not found: $PoolName"
        }

        $requiredNames = @(
            'PORTAL_DB_HOST',
            'PORTAL_DB_NAME',
            'PORTAL_DB_USERNAME',
            'PORTAL_DB_PASSWORD_B64',
            'PORTAL_DOCUMENT_ROOT',
            'PORTAL_SESSION_SAVE_PATH',
            'PORTAL_RATE_KEY',
            'PORTAL_COOKIE_PATH',
            'PORTAL_DB_ENCRYPT',
            'PORTAL_DB_TRUST_SERVER_CERTIFICATE'
        )
        $settings = @{}
        $environmentVariables = $appPoolElement.GetCollection('environmentVariables')
        foreach ($name in $requiredNames) {
            $environmentElement = Find-EnvironmentVariableElement -Collection $environmentVariables -Name $name
            $value = if ($null -eq $environmentElement) { '' } else { [string]$environmentElement.GetAttributeValue('value') }
            if ([string]::IsNullOrWhiteSpace($value)) {
                throw "Required IIS AppPool setting was not found: $name"
            }
            $settings[$name] = $value
        }
        return ,$settings
    }
    finally {
        $serverManager.Dispose()
    }
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $phpCommand) {
        $PhpPath = $phpCommand.Source
    }
}

if ([string]::IsNullOrWhiteSpace($PhpPath) -or -not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw 'PHP executable was not found. Supply -PhpPath, for example: C:\PHP\8.5.9\php.exe.'
}

$php = (Resolve-Path -LiteralPath $PhpPath).Path

try {
    $appPoolSettings = Get-AppPoolEnvironmentSettings -PoolName $AppPoolName
    foreach ($setting in $appPoolSettings.GetEnumerator()) {
        $existing = Get-Item -Path ("Env:{0}" -f $setting.Key) -ErrorAction SilentlyContinue
        $originalProcessEnvironment[$setting.Key] = [pscustomobject]@{
            Exists = $null -ne $existing
            Value = if ($null -eq $existing) { '' } else { [string]$existing.Value }
        }
        Set-Item -Path ("Env:{0}" -f $setting.Key) -Value ([string]$setting.Value)
    }

    $phpFiles = @(
        (Join-Path $projectRoot 'index.php'),
        (Join-Path $projectRoot 'app\bootstrap.php'),
        (Join-Path $projectRoot 'scripts\create_admin.php'),
        (Join-Path $projectRoot 'scripts\sync_documents.php'),
        (Join-Path $projectRoot 'scripts\check_environment.php')
    )

    foreach ($file in $phpFiles) {
        & $php -l $file
        if ($LASTEXITCODE -ne 0) {
            throw "PHP lint failed: $file"
        }
    }

    if (-not $SkipHealthCheck) {
        & $php (Join-Path $projectRoot 'scripts\check_environment.php')
        if ($LASTEXITCODE -ne 0) {
            throw 'Environment health check failed; web.config was not changed.'
        }
    }

    $activeConfig = Join-Path $projectRoot 'web.config'
    $productionTemplate = Join-Path $projectRoot 'web.config.example'
    $backupConfig = Join-Path $projectRoot 'web.config.pre-production-backup'
    if (-not (Test-Path -LiteralPath $activeConfig) -or -not (Test-Path -LiteralPath $productionTemplate)) {
        throw 'Required web.config file is missing.'
    }
    if (Test-Path -LiteralPath $backupConfig) {
        throw "Backup already exists: $backupConfig. Verify or remove it explicitly before enabling again."
    }

    Copy-Item -LiteralPath $activeConfig -Destination $backupConfig
    Copy-Item -LiteralPath $productionTemplate -Destination $activeConfig -Force
    Restart-WebAppPool -Name $AppPoolName
    Write-Host 'Production web.config enabled and the intended IIS AppPool was recycled. Perform the post-deployment verification.'
}
finally {
    foreach ($name in $originalProcessEnvironment.Keys) {
        $original = $originalProcessEnvironment[$name]
        if ($original.Exists) {
            Set-Item -Path ("Env:{0}" -f $name) -Value $original.Value
        }
        else {
            Remove-Item -Path ("Env:{0}" -f $name) -ErrorAction SilentlyContinue
        }
    }
    Remove-Variable appPoolSettings, originalProcessEnvironment -ErrorAction SilentlyContinue
}
