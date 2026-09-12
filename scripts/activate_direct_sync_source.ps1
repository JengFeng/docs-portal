[CmdletBinding()]
param(
  [string]$SiteName='Default Web Site',
  [string]$AppPath='/gary/TWWATER/sync-files',
  [string]$AppPoolName='TWWATER_PortalPool',
  [string]$SourceRoot='',
  [string]$TaskName='TWWATER_DirectSyncIndex',
  [switch]$ValidateOnly
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
if(-not [string]::IsNullOrWhiteSpace($SourceRoot)){throw 'PARENT_SOURCE_ROOT_RETIRED_USE_DOCUMENT_LIBRARY'}
& (Join-Path $PSScriptRoot 'activate_document_library_source.ps1') -SiteName $SiteName -AppPath $AppPath -AppPoolName $AppPoolName -TaskName $TaskName -ValidateOnly:$ValidateOnly
