[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
function Hash([string]$Path){(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-generic-image-block-'+[Guid]::NewGuid().ToString('N'))
$source=Join-Path $root 'source';$versions=Join-Path $root 'versions';$sessions=Join-Path $root 'sessions';foreach($d in @($source,$versions,$sessions)){[void][IO.Directory]::CreateDirectory($d)}
$id=[Guid]::NewGuid().ToString();$relative='asset.png';$sourcePath=Join-Path $source $relative;$candidate=Join-Path $root 'candidate.png';[IO.File]::WriteAllText($sourcePath,'current');[IO.File]::WriteAllText($candidate,'candidate');$before=Hash $sourcePath
try{
 $actual='';try{& (Join-Path $PSScriptRoot 'publish_document_version.ps1') -DocumentPublicId $id -RelativePath $relative -CandidatePath $candidate -ExpectedCurrentHash $before -SourceRoot $source -VersionRoot $versions -SessionRoot $sessions}catch{$actual=$_.Exception.Message}
 Assert ($actual-eq'IMAGE_ASSET_REQUIRES_SIGNED_IMAGE_PIPELINE') ("Expected generic image block, got: $actual")
 Assert ((Hash $sourcePath)-eq$before) 'Generic document CLI changed an image source.'
 Assert (-not(Get-ChildItem -LiteralPath $versions -Force|Select-Object -First 1)) 'Generic document CLI archived an image.'
 Write-Host '[OK] Generic document CLI rejects image assets before mutation.'
}finally{if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
