[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$DocumentRoot,
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$PhpPath = 'C:\PHP\8.5.9\php.exe',
    [string]$AppPoolName = 'TWWATER_PortalPool',
    [string]$DbHost = 'tcp:HKC_02_W01,1433',
    [string]$DbName = 'TWWATER_PORTAL',
    [string]$DbUsername = 'TWWATER_PORTAL_APP',
    [string]$SessionSavePath = 'C:\TWWATER\runtime\sessions',
    [string]$PresentationPreviewRoot = 'C:\TWWATER\runtime\presentation-previews',
    [string]$PresentationRuntimeRoot = 'C:\TWWATER\runtime\presentation-preview',
    [string]$LibreOfficePath = 'C:\Program Files\LibreOffice\program\soffice.com',
    [string]$CookiePath = '/gary/TWWATER/',
    [bool]$TrustServerCertificate = $true
)

$ErrorActionPreference = 'Stop'

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

function New-PortalRateKey {
    $bytes = New-Object byte[] 48
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $random.GetBytes($bytes)
        return [Convert]::ToBase64String($bytes)
    }
    finally {
        $random.Dispose()
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
}

function Find-EnvironmentVariableElement {
    param(
        [Parameter(Mandatory)]$Collection,
        [Parameter(Mandatory)][string]$Name
    )

    foreach ($element in $Collection) {
        if ($element.ElementTagName -ne 'add') {
            continue
        }

        $candidate = [string]$element.GetAttributeValue('name')
        if ([string]::Equals($candidate, $Name, [StringComparison]::OrdinalIgnoreCase)) {
            return $element
        }
    }

    return $null
}

function Set-AppPoolEnvironmentVariable {
    param(
        [Parameter(Mandatory)]$Collection,
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Value
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

function Remove-AppPoolEnvironmentVariable {
    param(
        [Parameter(Mandatory)]$Collection,
        [Parameter(Mandatory)][string]$Name
    )

    $element = Find-EnvironmentVariableElement -Collection $Collection -Name $Name
    if ($null -ne $element) {
        [void] $Collection.Remove($element)
    }
}

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable was not found: $PhpPath"
}

$healthCheck = Join-Path $ProjectRoot 'scripts\check_environment.php'
if (-not (Test-Path -LiteralPath $healthCheck -PathType Leaf)) {
    throw "Health-check script was not found: $healthCheck"
}

foreach ($requiredPath in @($DocumentRoot, $SessionSavePath, $PresentationPreviewRoot, $PresentationRuntimeRoot)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Container)) {
        throw "Required directory was not found: $requiredPath"
    }
}

if (-not (Test-Path -LiteralPath $LibreOfficePath -PathType Leaf)) {
    throw "LibreOffice executable was not found: $LibreOfficePath"
}

if ($TrustServerCertificate) {
    Write-Warning 'SQL encryption remains enabled, but certificate validation is temporarily bypassed. Install a trusted SQL Server certificate and rerun with -TrustServerCertificate:$false before final production approval.'
}

$secureDatabasePassword = Read-Host 'Enter the TWWATER_PORTAL_APP database password locally' -AsSecureString
$databasePassword = $null
$databasePasswordBytes = $null
$diagnosticHmacKeyBytes = $null
$diagnosticHmacKeyBase64 = $null
$diagnosticPasswordHmacBase64 = $null
$settings = $null
$serverManager = $null
$processEnvironmentNames = @()

try {
    $databasePassword = ConvertFrom-LocalSecureString -Value $secureDatabasePassword
    if ([string]::IsNullOrWhiteSpace($databasePassword)) {
        throw 'The database password cannot be empty.'
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
    $applicationPools = $configuration.GetSection('system.applicationHost/applicationPools').GetCollection()
    $appPoolElement = $null

    foreach ($element in $applicationPools) {
        if ($element.ElementTagName -ne 'add') {
            continue
        }
        if ([string]::Equals([string]$element.GetAttributeValue('name'), $AppPoolName, [StringComparison]::OrdinalIgnoreCase)) {
            $appPoolElement = $element
            break
        }
    }

    if ($null -eq $appPoolElement) {
        throw "IIS AppPool was not found: $AppPoolName"
    }

    $environmentVariables = $appPoolElement.GetCollection('environmentVariables')
    $existingRateKeyElement = Find-EnvironmentVariableElement -Collection $environmentVariables -Name 'PORTAL_RATE_KEY'
    $existingRateKey = if ($null -eq $existingRateKeyElement) { '' } else { [string]$existingRateKeyElement.GetAttributeValue('value') }
    $rateKey = if ([string]::IsNullOrWhiteSpace($existingRateKey) -or $existingRateKey.Length -lt 32) { New-PortalRateKey } else { $existingRateKey }
    $trustValue = if ($TrustServerCertificate) { '1' } else { '0' }
    $databasePasswordBytes = [Text.Encoding]::UTF8.GetBytes($databasePassword)
    $databasePasswordBase64 = [Convert]::ToBase64String($databasePasswordBytes)
    $diagnosticHmacKeyBytes = New-Object byte[] 32
    $diagnosticRandom = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $diagnosticRandom.GetBytes($diagnosticHmacKeyBytes)
    }
    finally {
        $diagnosticRandom.Dispose()
    }
    $diagnosticHmacKeyBase64 = [Convert]::ToBase64String($diagnosticHmacKeyBytes)
    $diagnosticHmac = New-Object Security.Cryptography.HMACSHA256
    try {
        $diagnosticHmac.Key = $diagnosticHmacKeyBytes
        $diagnosticPasswordHmacBase64 = [Convert]::ToBase64String($diagnosticHmac.ComputeHash($databasePasswordBytes))
    }
    finally {
        $diagnosticHmac.Dispose()
    }

    $settings = [ordered]@{
        PORTAL_DB_HOST                     = $DbHost
        PORTAL_DB_NAME                     = $DbName
        PORTAL_DB_USERNAME                 = $DbUsername
        PORTAL_DB_PASSWORD_B64             = $databasePasswordBase64
        PORTAL_DOCUMENT_ROOT               = $DocumentRoot
        PORTAL_SESSION_SAVE_PATH           = $SessionSavePath
        PORTAL_PRESENTATION_PREVIEW_ROOT   = $PresentationPreviewRoot
        PORTAL_PRESENTATION_RUNTIME_ROOT   = $PresentationRuntimeRoot
        PORTAL_LIBREOFFICE_PATH            = $LibreOfficePath
        PORTAL_RATE_KEY                    = $rateKey
        PORTAL_COOKIE_PATH                 = $CookiePath
        PORTAL_DB_ENCRYPT                  = '1'
        PORTAL_DB_TRUST_SERVER_CERTIFICATE = $trustValue
    }

    Write-Host "Testing SQL endpoint: $DbHost / database: $DbName / login: $DbUsername"

    $connectionBuilder = $null
    $credentialSecurePassword = $null
    $sqlCredential = $null
    $sqlConnection = $null
    try {
        $connectionBuilder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
        $connectionBuilder['Data Source'] = $DbHost
        $connectionBuilder['Initial Catalog'] = $DbName
        $connectionBuilder['Encrypt'] = $true
        $connectionBuilder['TrustServerCertificate'] = $TrustServerCertificate
        $connectionBuilder['Connect Timeout'] = 10
        $credentialSecurePassword = $secureDatabasePassword.Copy()
        $credentialSecurePassword.MakeReadOnly()
        $sqlCredential = [System.Data.SqlClient.SqlCredential]::new($DbUsername, $credentialSecurePassword)
        $sqlConnection = New-Object -TypeName System.Data.SqlClient.SqlConnection -ArgumentList $connectionBuilder.ConnectionString
        $sqlConnection.Credential = $sqlCredential
        $sqlConnection.Open()
        Write-Host '[OK]   .NET SqlClient SQL authentication'
    }
    catch {
        $connectionFailure = $_.Exception
        while ($null -ne $connectionFailure.InnerException) {
            $connectionFailure = $connectionFailure.InnerException
        }
        if ($connectionFailure -is [System.Data.SqlClient.SqlException]) {
            $firstSqlError = $connectionFailure.Errors[0]
            Write-Host ("[FAIL] .NET SqlClient SQL authentication [driver={0}, state={1}, class={2}]" -f $firstSqlError.Number, $firstSqlError.State, $firstSqlError.Class)
        }
        else {
            Write-Host '[FAIL] .NET SqlClient SQL authentication [driver=unavailable]'
        }
    }
    finally {
        if ($null -ne $sqlConnection) {
            $sqlConnection.Dispose()
            $sqlConnection = $null
        }
        if ($null -ne $connectionBuilder) {
            $connectionBuilder.Clear()
            $connectionBuilder = $null
        }
        $sqlCredential = $null
        if ($null -ne $credentialSecurePassword) {
            $credentialSecurePassword.Dispose()
            $credentialSecurePassword = $null
        }
    }

    foreach ($setting in $settings.GetEnumerator()) {
        Set-Item -Path ("Env:{0}" -f $setting.Key) -Value ([string]$setting.Value)
        $processEnvironmentNames += $setting.Key
    }

    Set-Item -Path 'Env:PORTAL_DIAGNOSTIC_HMAC_KEY_B64' -Value $diagnosticHmacKeyBase64
    Set-Item -Path 'Env:PORTAL_DIAGNOSTIC_PASSWORD_HMAC_B64' -Value $diagnosticPasswordHmacBase64
    $processEnvironmentNames += 'PORTAL_DIAGNOSTIC_HMAC_KEY_B64'
    $processEnvironmentNames += 'PORTAL_DIAGNOSTIC_PASSWORD_HMAC_B64'

    & $PhpPath $healthCheck
    if ($LASTEXITCODE -ne 0) {
        throw 'Environment health check failed. No secret values were displayed.'
    }

    Remove-AppPoolEnvironmentVariable -Collection $environmentVariables -Name 'PORTAL_DB_PASSWORD'
    foreach ($setting in $settings.GetEnumerator()) {
        Set-AppPoolEnvironmentVariable -Collection $environmentVariables -Name $setting.Key -Value ([string]$setting.Value)
    }
    $serverManager.CommitChanges()
    $serverManager.Dispose()
    $serverManager = $null

    Restart-WebAppPool -Name $AppPoolName
    Write-Host 'Health check passed. Settings were saved only to the TWWATER IIS AppPool and the AppPool was recycled.'
}
finally {
    if ($null -ne $serverManager) {
        $serverManager.Dispose()
    }

    foreach ($name in $processEnvironmentNames) {
        Remove-Item -Path ("Env:{0}" -f $name) -ErrorAction SilentlyContinue
    }

    if ($null -ne $databasePasswordBytes) {
        [Array]::Clear($databasePasswordBytes, 0, $databasePasswordBytes.Length)
    }
    if ($null -ne $diagnosticHmacKeyBytes) {
        [Array]::Clear($diagnosticHmacKeyBytes, 0, $diagnosticHmacKeyBytes.Length)
    }

    Remove-Variable databasePassword, databasePasswordBytes, databasePasswordBase64, diagnosticHmacKeyBytes, diagnosticHmacKeyBase64, diagnosticPasswordHmacBase64, diagnosticRandom, diagnosticHmac, rateKey, existingRateKey, settings, secureDatabasePassword, trustValue, connectionBuilder, credentialSecurePassword, sqlCredential, sqlConnection, connectionFailure, firstSqlError -ErrorAction SilentlyContinue
}
