[CmdletBinding()]
param(
  [string]$SiteName='Default Web Site',
  [string]$AppPath='/gary/TWWATER/sync-files',
  [string]$AppPoolName='TWWATER_PortalPool',
  [string]$TaskName='TWWATER_DirectSyncIndex',
  [switch]$ValidateOnly
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$parentRoot = 'C:\web\115' + (-join @([char]0x4F9B,[char]0x6C34,[char]0x76E3,[char]0x6E2C))
$documentRoot = Join-Path $parentRoot 'document-library'
$sessionRoot = 'C:\TWWATER\runtime\sessions'
$expectedParent = [IO.Path]::GetFullPath($parentRoot).TrimEnd('\')
$expectedDocuments = [IO.Path]::GetFullPath($documentRoot).TrimEnd('\')
if(-not(Test-Path -LiteralPath $expectedDocuments -PathType Container)){throw 'DOCUMENT_LIBRARY_MISSING'}
$item=Get-Item -LiteralPath $expectedDocuments -Force
if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'DOCUMENT_LIBRARY_REPARSE_REJECTED'}
if($ValidateOnly){Write-Output 'DOCUMENT_LIBRARY_SOURCE_VALIDATED'; return}
$identity=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if(-not $identity.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'ADMINISTRATOR_REQUIRED'}
New-Item -ItemType Directory -Force -Path $sessionRoot | Out-Null
$sessionItem=Get-Item -LiteralPath $sessionRoot -Force
if($sessionItem.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'SESSION_ROOT_REPARSE_REJECTED'}
& "$env:windir\System32\icacls.exe" $sessionRoot /grant "IIS AppPool\${AppPoolName}:(OI)(CI)M" /T /C | Out-Null
if($LASTEXITCODE -ne 0){throw 'SESSION_ACL_GRANT_FAILED'}
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll') -ErrorAction Stop
$manager=New-Object Microsoft.Web.Administration.ServerManager
try {
  $pool=$manager.ApplicationPools[$AppPoolName]; if($null -eq $pool){throw 'APP_POOL_NOT_FOUND'}
  $variables=$pool.GetChildElement('environmentVariables').GetCollection()
  foreach($pair in @{PORTAL_DOCUMENT_ROOT=$expectedDocuments;PORTAL_DIRECT_SYNC_MODE='1';PORTAL_DIRECT_SYNC_SOURCE_ROOT=$expectedDocuments;PORTAL_SESSION_SAVE_PATH=$sessionRoot}.GetEnumerator()){
    $entry=$null; foreach($candidate in $variables){if([string]$candidate['name'] -ieq $pair.Key){$entry=$candidate;break}}
    if($null -eq $entry){$entry=$variables.CreateElement('add');$entry['name']=$pair.Key;$entry['value']=$pair.Value;[void]$variables.Add($entry)} else {$entry['value']=$pair.Value}
  }
  $site=$manager.Sites[$SiteName];if($null -eq $site){throw 'SITE_NOT_FOUND'}
  $app=$site.Applications[$AppPath]
  if($null -ne $app){
    $physical=[IO.Path]::GetFullPath([string]$app.VirtualDirectories['/'].PhysicalPath).TrimEnd('\')
    if($physical -cne $expectedParent){throw 'UNEXPECTED_STATIC_APPLICATION_ROOT'}
    [void]$site.Applications.Remove($app)
  }
  $manager.CommitChanges()
} finally {$manager.Dispose()}
Import-Module WebAdministration -ErrorAction Stop
Restart-WebAppPool -Name $AppPoolName
& "$env:windir\System32\schtasks.exe" /Run /TN $TaskName | Out-Null
Write-Output 'DOCUMENT_LIBRARY_SOURCE_ACTIVATED'
