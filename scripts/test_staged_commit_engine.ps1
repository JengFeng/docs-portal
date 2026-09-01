$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'staged_commit_engine.ps1')

$Root = Join-Path $env:LOCALAPPDATA ('Temp\twwater-staged-commit-test-' + [guid]::NewGuid().ToString('N'))
$SourceRoot = Join-Path $Root 'source'
$StagingRoot = Join-Path $Root 'staging'
$CommitRoot = Join-Path $Root 'commit-channel'
$VersionRoot = Join-Path $Root 'versions'
$RequestRoot = Join-Path $CommitRoot 'requests'
$ResultRoot = Join-Path $CommitRoot 'results'
New-Item -ItemType Directory -Path $SourceRoot, $StagingRoot, $CommitRoot, $VersionRoot, $RequestRoot, $ResultRoot -Force | Out-Null

function Write-JsonAtomic([string] $Path, [hashtable] $Payload) {
    $Temp = $Path + '.tmp'
    $Payload | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $Temp -Encoding UTF8
    Move-Item -LiteralPath $Temp -Destination $Path -Force
}

try {
    $OperationId = [guid]::NewGuid().ToString('D')
    $Workspace = Join-Path $StagingRoot $OperationId
    New-Item -ItemType Directory -Path $Workspace -Force | Out-Null
    $Candidate = Join-Path $Workspace 'source.txt'
    [IO.File]::WriteAllText($Candidate, 'candidate-v1', [Text.UTF8Encoding]::new($false))
    $Hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $Candidate).Hash.ToLowerInvariant()
    Write-JsonAtomic (Join-Path $RequestRoot ($OperationId + '.json')) @{
        schema_version = 1
        operation_id = $OperationId
        target_relative_path = 'WebsiteDocuments/test.txt'
        extension = 'txt'
        candidate_sha256 = $Hash
        expected_current_sha256 = $null
    }

    [void](Invoke-TWWaterStagedCommitRequests -SourceRoot $SourceRoot -StagingRoot $StagingRoot -CommitRoot $CommitRoot -VersionRoot $VersionRoot -AllowedRelativeRoot 'WebsiteDocuments')
    $Target = Join-Path $SourceRoot 'WebsiteDocuments\test.txt'
    if (-not (Test-Path -LiteralPath $Target -PathType Leaf)) { throw 'New staged candidate was not committed.' }
    if ([IO.File]::ReadAllText($Target) -ne 'candidate-v1') { throw 'Committed content mismatch.' }
    $Result = Get-Content -Raw -LiteralPath (Join-Path $ResultRoot ($OperationId + '.json')) | ConvertFrom-Json
    if ($Result.status -ne 'local-drive-written' -or $Result.actual_sha256 -ne $Hash -or $Result.candidate_sha256 -ne $Hash -or $Result.target_relative_path -ne 'WebsiteDocuments/test.txt') { throw 'Success result invalid.' }

    $ReplaceId = [guid]::NewGuid().ToString('D')
    $ReplaceWorkspace = Join-Path $StagingRoot $ReplaceId
    New-Item -ItemType Directory -Path $ReplaceWorkspace -Force | Out-Null
    $ReplaceCandidate = Join-Path $ReplaceWorkspace 'source.txt'
    [IO.File]::WriteAllText($ReplaceCandidate, 'candidate-v2', [Text.UTF8Encoding]::new($false))
    $ReplaceHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ReplaceCandidate).Hash.ToLowerInvariant()
    Write-JsonAtomic (Join-Path $RequestRoot ($ReplaceId + '.json')) @{
        schema_version = 1; operation_id = $ReplaceId; target_relative_path = 'WebsiteDocuments/test.txt'; extension = 'txt'
        candidate_sha256 = $ReplaceHash; expected_current_sha256 = $Hash
    }
    [void](Invoke-TWWaterStagedCommitRequests -SourceRoot $SourceRoot -StagingRoot $StagingRoot -CommitRoot $CommitRoot -VersionRoot $VersionRoot -AllowedRelativeRoot 'WebsiteDocuments')
    if ([IO.File]::ReadAllText($Target) -ne 'candidate-v2') { throw 'Expected-hash replacement failed.' }
    $Backup = Join-Path $VersionRoot ('staged-commits\' + $ReplaceId + '\test.txt')
    if (-not (Test-Path -LiteralPath $Backup -PathType Leaf) -or [IO.File]::ReadAllText($Backup) -ne 'candidate-v1') { throw 'Atomic replacement backup missing or invalid.' }

    $ConflictId = [guid]::NewGuid().ToString('D')
    $ConflictWorkspace = Join-Path $StagingRoot $ConflictId
    New-Item -ItemType Directory -Path $ConflictWorkspace -Force | Out-Null
    $ConflictCandidate = Join-Path $ConflictWorkspace 'source.txt'
    [IO.File]::WriteAllText($ConflictCandidate, 'candidate-v3', [Text.UTF8Encoding]::new($false))
    $ConflictHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ConflictCandidate).Hash.ToLowerInvariant()
    Write-JsonAtomic (Join-Path $RequestRoot ($ConflictId + '.json')) @{
        schema_version = 1
        operation_id = $ConflictId
        target_relative_path = 'WebsiteDocuments/test.txt'
        extension = 'txt'
        candidate_sha256 = $ConflictHash
        expected_current_sha256 = ('0' * 64)
    }
    [void](Invoke-TWWaterStagedCommitRequests -SourceRoot $SourceRoot -StagingRoot $StagingRoot -CommitRoot $CommitRoot -VersionRoot $VersionRoot -AllowedRelativeRoot 'WebsiteDocuments')
    if ([IO.File]::ReadAllText($Target) -ne 'candidate-v2') { throw 'Conflict overwrote current source.' }
    $ConflictResult = Get-Content -Raw -LiteralPath (Join-Path $ResultRoot ($ConflictId + '.json')) | ConvertFrom-Json
    if ($ConflictResult.status -ne 'conflict') { throw 'Conflict result was not reported.' }
    $ImageId = [guid]::NewGuid().ToString('D')
    $ImageWorkspace = Join-Path $StagingRoot $ImageId
    New-Item -ItemType Directory -Path $ImageWorkspace -Force | Out-Null
    $ImageCandidate = Join-Path $ImageWorkspace 'source.png'
    [IO.File]::WriteAllText($ImageCandidate, 'unsigned-image-bytes', [Text.UTF8Encoding]::new($false))
    $ImageHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ImageCandidate).Hash.ToLowerInvariant()
    Write-JsonAtomic (Join-Path $RequestRoot ($ImageId + '.json')) @{schema_version=1;operation_id=$ImageId;target_relative_path='WebsiteDocuments/image.png';extension='png';candidate_sha256=$ImageHash;expected_current_sha256=$null}
    [void](Invoke-TWWaterStagedCommitRequests -SourceRoot $SourceRoot -StagingRoot $StagingRoot -CommitRoot $CommitRoot -VersionRoot $VersionRoot -AllowedRelativeRoot 'WebsiteDocuments')
    if(Test-Path -LiteralPath (Join-Path $SourceRoot 'WebsiteDocuments\image.png')){throw 'Unsigned staged image request mutated source.'}
    $ImageResult=Get-Content -Raw -LiteralPath (Join-Path $ResultRoot ($ImageId+'.json'))|ConvertFrom-Json
    if($ImageResult.status-ne'failed'-or$ImageResult.error_code-ne'IMAGE_ASSET_REQUIRES_SIGNED_IMAGE_PIPELINE'){throw 'Unsigned staged image request did not fail closed.'}

    Write-Output '[OK] Staged commit engine contracts passed.'
}
finally {
    if (Test-Path -LiteralPath $Root) { Remove-Item -LiteralPath $Root -Recurse -Force }
}
