[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
function Hash([string]$Path){(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-candidate-fence-'+[Guid]::NewGuid().ToString('N'));$source=Join-Path $root 'source';$candidateRoot=Join-Path $root 'candidate';$versions=Join-Path $root 'versions';$sessions=Join-Path $root 'sessions';foreach($d in @($source,$candidateRoot,$versions,$sessions)){[void][IO.Directory]::CreateDirectory($d)}
try{$sourcePath=Join-Path $source 'image.png';$candidate=Join-Path $candidateRoot 'image.png';[IO.File]::WriteAllText($sourcePath,'source');[IO.File]::WriteAllText($candidate,'validated');$sourceHash=Hash $sourcePath;$validatedHash=Hash $candidate;[IO.File]::WriteAllText($candidate,'swapped-after-validation');$actual='';try{[void](Publish-TWWaterDocumentVersion -SourceRoot $source -VersionRoot $versions -SessionRoot $sessions -DocumentPublicId ([Guid]::NewGuid().ToString()) -RelativePath 'image.png' -CandidatePath $candidate -CandidateRoot $candidateRoot -ExpectedCandidateHash $validatedHash -ExpectedCurrentHash $sourceHash -Reason publish -ActorReference test)}catch{$actual=$_.Exception.Message};Assert ($actual-eq'IMAGE_OUTPUT_HASH_CONFLICT') ("Expected IMAGE_OUTPUT_HASH_CONFLICT, got $actual");Assert ((Hash $sourcePath)-eq$sourceHash) 'Candidate swap changed source.';Write-Host '[OK] Shared source lock revalidates candidate root and expected hash.'}finally{if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
