[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()]
    [string] $SiteName = 'Default Web Site',

    [ValidatePattern('^/')]
    [string] $ApplicationPath = '/gary/TWWATER',

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

if (-not (Test-IsAdministrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

Import-Module WebAdministration -ErrorAction Stop
$application = Get-WebApplication -Site $SiteName |
    Where-Object { $_.Path -eq $ApplicationPath } |
    Select-Object -First 1
if ($null -eq $application) {
    throw "IIS application was not found: $SiteName$ApplicationPath"
}
if ([string] $application.ApplicationPool -ne $AppPoolName) {
    throw "The application is assigned to '$($application.ApplicationPool)', not '$AppPoolName'."
}

$location = "$SiteName$ApplicationPath"
$filter = 'system.webServer/security/authentication/anonymousAuthentication'

# An empty userName selects the application's own AppPool identity. Do not use
# the shared IUSR account, which would require broad file-system permissions.
Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter $filter -Name 'enabled' -Value 'True'
Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter $filter -Name 'userName' -Value ''
Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter $filter -Name 'password' -Value ''

Restart-WebAppPool -Name $AppPoolName
Write-Host "[OK] Anonymous requests for $ApplicationPath now use its dedicated AppPool identity."
Write-Host "[OK] $AppPoolName was recycled."
