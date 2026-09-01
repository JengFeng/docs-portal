[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
function Hash([string]$Path){(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-source-lock-'+[Guid]::NewGuid().ToString('N'));$source=Join-Path $root 'source';$versions=Join-Path $root 'versions';$sessions=Join-Path $root 'sessions';foreach($d in @($source,$versions,$sessions)){[void][IO.Directory]::CreateDirectory($d)}
$sourcePath=Join-Path $source 'asset.txt';$candidate=Join-Path $root 'candidate.txt';[IO.File]::WriteAllText($sourcePath,'current');[IO.File]::WriteAllText($candidate,'candidate');$before=Hash $sourcePath
$lockRoot=Join-Path $versions '.locks';[void][IO.Directory]::CreateDirectory($lockRoot);$normalized=[IO.Path]::GetFullPath($sourcePath).ToLowerInvariant();$sha=[Security.Cryptography.SHA256]::Create();try{$key=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($normalized)))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()};$lockPath=Join-Path $lockRoot ($key+'.lock');$held=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
try{
 $actual='';try{[void](Publish-TWWaterDocumentVersion -SourceRoot $source -VersionRoot $versions -SessionRoot $sessions -DocumentPublicId ([Guid]::NewGuid().ToString()) -RelativePath 'asset.txt' -CandidatePath $candidate -ExpectedCurrentHash $before -Reason publish -ActorReference test)}catch{$actual=$_.Exception.Message}
 Assert ($actual-eq'VERSION_SOURCE_BUSY') ("Expected shared source lock rejection, got: $actual")
 Assert ((Hash $sourcePath)-eq$before) 'Busy source was modified.'
 Write-Host '[OK] Document version engine honors the shared source-path lock.'
}finally{$held.Dispose();if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
