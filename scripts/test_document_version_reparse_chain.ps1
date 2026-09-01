[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
function Hash([string]$Path){(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-reparse-chain-'+[Guid]::NewGuid().ToString('N'));$source=Join-Path $root 'source';$outside=Join-Path $root 'outside';$versions=Join-Path $root 'versions';$sessions=Join-Path $root 'sessions';foreach($d in @($source,$outside,$versions,$sessions)){[void][IO.Directory]::CreateDirectory($d)}
$outsideFile=Join-Path $outside 'image.png';$candidate=Join-Path $root 'candidate.png';[IO.File]::WriteAllText($outsideFile,'outside-current');[IO.File]::WriteAllText($candidate,'candidate');$before=Hash $outsideFile;$link=Join-Path $source 'alias';[void](New-Item -ItemType Junction -Path $link -Target $outside)
try{
 $actual='';try{[void](Publish-TWWaterDocumentVersion -SourceRoot $source -VersionRoot $versions -SessionRoot $sessions -DocumentPublicId ([Guid]::NewGuid().ToString()) -RelativePath 'alias/image.png' -CandidatePath $candidate -ExpectedCurrentHash $before -Reason publish -ActorReference test)}catch{$actual=$_.Exception.Message}
 Assert ($actual-eq'PATH_REPARSE_POINT') ("Expected path-chain rejection, got: $actual")
 Assert ((Hash $outsideFile)-eq$before) 'Reparse-chain request changed the target outside SourceRoot.'
 Write-Host '[OK] Document version engine rejects reparse points in the source path chain.'
}finally{if(Test-Path -LiteralPath $link){[IO.Directory]::Delete($link)};if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
