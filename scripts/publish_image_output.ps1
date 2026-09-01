[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$ManifestPath,
    [ValidateNotNullOrEmpty()][string]$ActorReference='hermes-agent'
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$engine=Join-Path $PSScriptRoot 'image_source_version_engine.ps1'
if(-not(Test-Path -LiteralPath $engine -PathType Leaf)){throw 'Image source version engine was not found.'}
. $engine
$sourceRoot='C:\web\gary\TWWATER\document-library'
$previewRoot='C:\TWWATER\runtime\pre-upload-previews'
$archiveRoot='C:\TWWATER\runtime\image-source-archive'
$sessionRoot='C:\TWWATER\runtime\sessions'
$commandKeyPath='C:\TWWATER\runtime\image-source-archive\.keys\image-command.key'
$entryUrl='https://aiwork.ddns.net/gary/TWWATER/'
$repaired=Repair-TWWaterPreparedImagePublishes -ImageSourceRoot $sourceRoot -ImageArchiveRoot $archiveRoot -SessionRoot $sessionRoot
if($repaired-gt0){try{[void](Invoke-WebRequest -UseBasicParsing -Uri $entryUrl -TimeoutSec 20)}catch{}}
$result=Publish-TWWaterImageOutputFromManifest -ManifestPath $ManifestPath -SourceRoot $sourceRoot -PreviewRoot $previewRoot -ArchiveRoot $archiveRoot -SessionRoot $sessionRoot -CommandKeyPath $commandKeyPath -ActorReference $ActorReference
$reindexRequested=$false
$reindexConfirmed=$false
try{
    $pending=Join-Path $sessionRoot 'document-bridge.pending'
    $approvalMarker=Join-Path (Join-Path $sessionRoot 'document-version-approved') ($result.DocumentPublicId+'-'+$result.CurrentHash+'.json')
    if(-not(Test-Path -LiteralPath $pending -PathType Leaf)){
        $stream=[IO.File]::Open($pending,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::Read)
        try{$bytes=[Text.Encoding]::ASCII.GetBytes($result.OperationId+"`n");$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true)}finally{$stream.Dispose()}
    }
    [void](Invoke-WebRequest -Uri 'https://aiwork.ddns.net/gary/TWWATER/' -UseBasicParsing -Method Get -TimeoutSec 20)
    $reindexRequested=$true
    $reindexConfirmed=(-not(Test-Path -LiteralPath $pending -PathType Leaf))-and(-not(Test-Path -LiteralPath $approvalMarker -PathType Leaf))
}catch{
    # The durable pending marker remains for the next authenticated or anonymous
    # Portal entry request. A wake failure must not roll back a committed image.
}
[ordered]@{ok=$true;operation_id=$result.OperationId;previous_hash=$result.PreviousHash;current_hash=$result.CurrentHash;source_path=$result.SourcePath;archived_artifact_path=$result.ArchivedArtifactPath;reindex_requested=$reindexRequested;reindex_confirmed=$reindexConfirmed}|ConvertTo-Json -Compress
