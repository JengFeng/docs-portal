[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
function Hash([string]$Path){(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-rollback-fence-'+[Guid]::NewGuid().ToString('N'));[void][IO.Directory]::CreateDirectory($root);$source=Join-Path $root 'source.txt';$rollback=Join-Path $root 'rollback.txt';[IO.File]::WriteAllText($source,'newer-version');[IO.File]::WriteAllText($rollback,'previous-version');$newerHash=Hash $source;$candidatePath=Join-Path $root 'candidate.txt';[IO.File]::WriteAllText($candidatePath,'failed-operation-candidate');$candidateHash=Hash $candidatePath;$previousHash=Hash $rollback
try{
 $actual='';try{Restore-TWWaterRollbackIfCurrentCandidate -SourcePath $source -RollbackPath $rollback -ExpectedCandidateHash $candidateHash -ExpectedPreviousHash $previousHash}catch{$actual=$_.Exception.Message}
 Assert ($actual-eq'ROLLBACK_SUPERSEDED') ("Expected fenced rollback rejection, got: $actual")
 Assert ((Hash $source)-eq$newerHash) 'Fenced rollback erased a newer source.'
 Assert (Test-Path -LiteralPath $rollback -PathType Leaf) 'Fenced rollback removed recovery evidence.'
 Write-Host '[OK] Rollback is fenced against a newer committed source.'
}finally{if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
