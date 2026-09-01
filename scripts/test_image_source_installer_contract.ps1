[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
function Assert-True{param([bool]$Condition,[string]$Message)if(-not$Condition){throw $Message}}
$installer=Join-Path $PSScriptRoot 'install_image_source_archive.ps1'
Assert-True (Test-Path -LiteralPath $installer -PathType Leaf) 'Image source archive installer is missing.'
$source=Get-Content -LiteralPath $installer -Raw -Encoding UTF8
foreach($token in @('PORTAL_IMAGE_SOURCE_ARCHIVE_ROOT','PORTAL_IMAGE_COMMAND_KEY_PATH','ImageArchiveRoot','ImageSourceRoot','ImageCommandKeyPath','RandomNumberGenerator','image-command.key','C:\web\gary\TWWATER\document-library','Set-ScheduledTask','IIS AppPool\','Get-Acl','Get-ScheduledTask')){Assert-True ($source.Contains($token)) ("Installer contract missing: $token")}
Assert-True ($source.Contains("[switch]`$Activate")) 'Installer must keep activation behind an explicit switch.'
Write-Host '[OK] Image source archive installer contract passed.'
