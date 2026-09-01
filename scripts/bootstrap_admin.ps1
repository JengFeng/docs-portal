[CmdletBinding()]
param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$PhpPath = 'C:\PHP\8.5.9\php.exe',
    [string]$AppPoolName = 'TWWATER_PortalPool'
)

$ErrorActionPreference = 'Stop'
$processEnvironmentNames = @()
$passwordBytes = $null
$passwordConfirmBytes = $null
$serverManager = $null

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function ConvertFrom-LocalSecureString {
    param([Parameter(Mandatory)][Security.SecureString]$Value)

    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
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

function Test-ByteArraysEqual {
    param(
        [Parameter(Mandatory)][byte[]]$Left,
        [Parameter(Mandatory)][byte[]]$Right
    )

    if ($Left.Length -ne $Right.Length) {
        return $false
    }

    $difference = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $difference = $difference -bor ($Left[$index] -bxor $Right[$index])
    }
    return $difference -eq 0
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable was not found: $PhpPath"
}

$createAdmin = Join-Path $ProjectRoot 'scripts\create_admin.php'
if (-not (Test-Path -LiteralPath $createAdmin -PathType Leaf)) {
    throw "Create-admin script was not found: $createAdmin"
}

$username = (Read-Host 'Portal administrator username (a-z, 0-9, dot, underscore, hyphen; example: gary.gis.fcu.edu.tw)').Trim().ToLowerInvariant()
$displayName = (Read-Host 'Portal administrator display name').Trim()
if ($username -notmatch '\A[a-z0-9][a-z0-9._-]{2,127}\z') {
    throw 'The portal administrator username format is invalid. Do not use @ or spaces.'
}
if ($displayName.Length -eq 0 -or $displayName.Length -gt 100) {
    throw 'The portal administrator display name must contain 1 to 100 characters.'
}
$securePassword = Read-Host 'Portal administrator password (12+ chars)' -AsSecureString
$securePasswordConfirm = Read-Host 'Confirm portal administrator password' -AsSecureString

try {
    $password = ConvertFrom-LocalSecureString -Value $securePassword
    $passwordConfirm = ConvertFrom-LocalSecureString -Value $securePasswordConfirm
    $passwordBytes = [Text.Encoding]::UTF8.GetBytes($password)
    $passwordConfirmBytes = [Text.Encoding]::UTF8.GetBytes($passwordConfirm)
    if (-not (Test-ByteArraysEqual -Left $passwordBytes -Right $passwordConfirmBytes)) {
        throw 'The administrator password confirmation did not match.'
    }
    if ($password.Length -lt 12) {
        throw 'The administrator password must contain at least 12 characters.'
    }

    Import-Module WebAdministration
    $administrationAssembly = Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll'
    if ($null -eq ('Microsoft.Web.Administration.ServerManager' -as [type])) {
        if (-not (Test-Path -LiteralPath $administrationAssembly -PathType Leaf)) {
            throw "IIS administration assembly was not found: $administrationAssembly"
        }
        Add-Type -Path $administrationAssembly
    }
    $serverManager = New-Object -TypeName Microsoft.Web.Administration.ServerManager
    $configuration = $serverManager.GetApplicationHostConfiguration()
    $appPoolElement = $null
    foreach ($element in $configuration.GetSection('system.applicationHost/applicationPools').GetCollection()) {
        if ($element.ElementTagName -eq 'add' -and [string]::Equals([string]$element.GetAttributeValue('name'), $AppPoolName, [StringComparison]::OrdinalIgnoreCase)) {
            $appPoolElement = $element
            break
        }
    }
    if ($null -eq $appPoolElement) {
        throw "IIS AppPool was not found: $AppPoolName"
    }

    $environmentVariables = $appPoolElement.GetCollection('environmentVariables')
    $requiredNames = @(
        'PORTAL_DB_HOST',
        'PORTAL_DB_NAME',
        'PORTAL_DB_USERNAME',
        'PORTAL_DB_PASSWORD_B64',
        'PORTAL_DB_ENCRYPT',
        'PORTAL_DB_TRUST_SERVER_CERTIFICATE'
    )
    foreach ($name in $requiredNames) {
        $environmentElement = Find-EnvironmentVariableElement -Collection $environmentVariables -Name $name
        $value = if ($null -eq $environmentElement) { '' } else { [string]$environmentElement.GetAttributeValue('value') }
        if ([string]::IsNullOrWhiteSpace($value)) {
            throw "Required IIS AppPool setting was not found: $name"
        }
        Set-Item -Path ("Env:{0}" -f $name) -Value $value
        $processEnvironmentNames += $name
    }

    Set-Item -Path 'Env:PORTAL_BOOTSTRAP_USERNAME' -Value $username
    Set-Item -Path 'Env:PORTAL_BOOTSTRAP_DISPLAY_NAME' -Value $displayName
    Set-Item -Path 'Env:PORTAL_BOOTSTRAP_PASSWORD_B64' -Value ([Convert]::ToBase64String($passwordBytes))
    $processEnvironmentNames += 'PORTAL_BOOTSTRAP_USERNAME'
    $processEnvironmentNames += 'PORTAL_BOOTSTRAP_DISPLAY_NAME'
    $processEnvironmentNames += 'PORTAL_BOOTSTRAP_PASSWORD_B64'

    Write-Host 'Creating the initial portal administrator. No passwords are displayed or saved by this script.'
    & $PhpPath $createAdmin
    if ($LASTEXITCODE -ne 0) {
        throw 'Initial portal administrator creation failed.'
    }
    Write-Host 'Initial portal administrator created successfully.'
}
finally {
    if ($null -ne $serverManager) {
        $serverManager.Dispose()
    }
    foreach ($name in $processEnvironmentNames) {
        Remove-Item -Path ("Env:{0}" -f $name) -ErrorAction SilentlyContinue
    }
    if ($null -ne $passwordBytes) {
        [Array]::Clear($passwordBytes, 0, $passwordBytes.Length)
    }
    if ($null -ne $passwordConfirmBytes) {
        [Array]::Clear($passwordConfirmBytes, 0, $passwordConfirmBytes.Length)
    }
    if ($null -ne $securePassword) {
        $securePassword.Dispose()
    }
    if ($null -ne $securePasswordConfirm) {
        $securePasswordConfirm.Dispose()
    }
    Remove-Variable password, passwordConfirm, passwordBytes, passwordConfirmBytes, securePassword, securePasswordConfirm, serverManager -ErrorAction SilentlyContinue
}
