Set-StrictMode -Version Latest

function Write-TWWaterStagedCommitJson {
    param([Parameter(Mandatory=$true)][string] $Path, [Parameter(Mandatory=$true)][hashtable] $Payload)
    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
    $temporary = $Path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    $json = $Payload | ConvertTo-Json -Depth 8 -Compress
    [IO.File]::WriteAllText($temporary, $json, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $Path -Force
}

function Test-TWWaterStagedCommitReparsePoint {
    param([Parameter(Mandatory=$true)][string] $Path)
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    return ((Get-Item -LiteralPath $Path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0
}

function Invoke-TWWaterStagedCommitRequests {
    param(
        [Parameter(Mandatory=$true)][string] $SourceRoot,
        [Parameter(Mandatory=$true)][string] $StagingRoot,
        [Parameter(Mandatory=$true)][string] $CommitRoot,
        [Parameter(Mandatory=$true)][string] $VersionRoot,
        [Parameter(Mandatory=$true)][string] $AllowedRelativeRoot
    )

    $sourceFull = [IO.Path]::GetFullPath($SourceRoot).TrimEnd('\')
    $stagingFull = [IO.Path]::GetFullPath($StagingRoot).TrimEnd('\')
    $commitFull = [IO.Path]::GetFullPath($CommitRoot).TrimEnd('\')
    $versionFull = [IO.Path]::GetFullPath($VersionRoot).TrimEnd('\')
    foreach ($root in @($sourceFull, $stagingFull, $commitFull, $versionFull)) {
        if (-not (Test-Path -LiteralPath $root -PathType Container) -or (Test-TWWaterStagedCommitReparsePoint $root)) {
            throw "Required root is unavailable or unsafe: $root"
        }
    }
    if ($AllowedRelativeRoot -eq '' -or $AllowedRelativeRoot -match '[\\/:*?"<>|]' -or $AllowedRelativeRoot -in @('.', '..')) {
        throw 'Allowed relative root is invalid.'
    }

    $requestRoot = Join-Path $commitFull 'requests'
    $resultRoot = Join-Path $commitFull 'results'
    foreach ($channel in @($requestRoot, $resultRoot)) {
        if (-not (Test-Path -LiteralPath $channel -PathType Container) -or (Test-TWWaterStagedCommitReparsePoint $channel)) {
            throw "Commit channel is unavailable or unsafe: $channel"
        }
    }
    Get-ChildItem -LiteralPath $resultRoot -Filter '*.json' -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTimeUtc -lt [DateTime]::UtcNow.AddDays(-30) } |
        Remove-Item -Force -ErrorAction SilentlyContinue
    $processed = 0
    foreach ($requestPath in @(Get-ChildItem -LiteralPath $requestRoot -Filter '*.json' -File -ErrorAction SilentlyContinue | Sort-Object Name)) {
        $operationId = [IO.Path]::GetFileNameWithoutExtension($requestPath.Name).ToLowerInvariant()
        $result = @{
            schema_version = 1
            operation_id = $operationId
            status = 'failed'
            actual_sha256 = $null
            candidate_sha256 = $null
            target_relative_path = $null
            expected_current_sha256 = $null
            error_code = 'COMMIT_FAILED'
            completed_at_utc = [DateTime]::UtcNow.ToString('o')
        }
        try {
            $guidValue = [guid]::Empty
            if (-not [guid]::TryParseExact($operationId, 'D', [ref] $guidValue)) { throw 'Invalid operation id.' }
            if ($requestPath.Length -lt 20 -or $requestPath.Length -gt 16384 -or (Test-TWWaterStagedCommitReparsePoint $requestPath.FullName)) { throw 'Invalid request file.' }
            $request = Get-Content -LiteralPath $requestPath.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
            if ([int]$request.schema_version -ne 1 -or ([string]$request.operation_id).ToLowerInvariant() -ne $operationId) { throw 'Invalid request schema.' }

            $extension = ([string]$request.extension).ToLowerInvariant()
            if ($extension -in @('png','jpg','jpeg','webp','gif')) {
                $result.error_code='IMAGE_ASSET_REQUIRES_SIGNED_IMAGE_PIPELINE'
                throw 'IMAGE_ASSET_REQUIRES_SIGNED_IMAGE_PIPELINE'
            }
            if ($extension -notin @('pptx','pdf','docx','xlsx','md','txt')) { throw 'Invalid extension.' }
            $candidateHash = ([string]$request.candidate_sha256).ToLowerInvariant()
            if ($candidateHash -notmatch '^[0-9a-f]{64}$') { throw 'Invalid candidate hash.' }
            $expectedHash = if ($null -eq $request.expected_current_sha256) { '' } else { ([string]$request.expected_current_sha256).ToLowerInvariant() }
            if ($expectedHash -ne '' -and $expectedHash -notmatch '^[0-9a-f]{64}$') { throw 'Invalid expected hash.' }
            $result.candidate_sha256 = $candidateHash
            $result.target_relative_path = [string]$request.target_relative_path
            $result.expected_current_sha256 = if ($expectedHash -eq '') { $null } else { $expectedHash }

            $relative = ([string]$request.target_relative_path).Replace('/', '\')
            if ($relative -eq '' -or [IO.Path]::IsPathRooted($relative) -or $relative -match '(^|\\)\.\.(\\|$)') { throw 'Invalid target path.' }
            $segments = @($relative.Split('\'))
            if ($segments.Count -lt 2 -or $segments[0] -cne $AllowedRelativeRoot -or $segments -contains '' -or $segments -contains '.') { throw 'Target path is outside the allowed root.' }
            if ([IO.Path]::GetExtension($relative).TrimStart('.').ToLowerInvariant() -ne $extension) { throw 'Target extension mismatch.' }

            $workspace = Join-Path $stagingFull $operationId
            $candidate = Join-Path $workspace ('source.' + $extension)
            if (-not (Test-Path -LiteralPath $candidate -PathType Leaf) -or (Test-TWWaterStagedCommitReparsePoint $workspace) -or (Test-TWWaterStagedCommitReparsePoint $candidate)) { throw 'Candidate is missing or unsafe.' }
            $actualCandidateHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $candidate).Hash.ToLowerInvariant()
            if ($actualCandidateHash -ne $candidateHash) { throw 'Candidate hash mismatch.' }

            $target = [IO.Path]::GetFullPath((Join-Path $sourceFull $relative))
            if (-not $target.StartsWith($sourceFull + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Target path escaped source root.' }
            $targetDirectory = Split-Path -Parent $target
            if (-not (Test-Path -LiteralPath $targetDirectory -PathType Container)) {
                New-Item -ItemType Directory -Path $targetDirectory -Force | Out-Null
            }
            $walk = $sourceFull
            foreach ($segment in $segments[0..($segments.Count - 2)]) {
                $walk = Join-Path $walk $segment
                if (Test-TWWaterStagedCommitReparsePoint $walk) { throw 'Target path contains a reparse point.' }
            }

            $targetExists = Test-Path -LiteralPath $target -PathType Leaf
            if ($targetExists) {
                if (Test-TWWaterStagedCommitReparsePoint $target) { throw 'Target file is unsafe.' }
                $currentHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $target).Hash.ToLowerInvariant()
                if ($expectedHash -eq '' -or $currentHash -ne $expectedHash) {
                    $result.status = 'conflict'
                    $result.error_code = 'EXPECTED_HASH_MISMATCH'
                    throw [InvalidOperationException]::new('Expected source hash mismatch.')
                }
            } elseif ($expectedHash -ne '') {
                $result.status = 'conflict'
                $result.error_code = 'SOURCE_MISSING'
                throw [InvalidOperationException]::new('Expected source is missing.')
            }

            $temporary = Join-Path $targetDirectory ('.twwater-staged-' + $operationId + '.tmp')
            [IO.File]::Copy($candidate, $temporary, $false)
            if ((Get-FileHash -Algorithm SHA256 -LiteralPath $temporary).Hash.ToLowerInvariant() -ne $candidateHash) { throw 'Temporary copy hash mismatch.' }
            if ($targetExists) {
                $archiveDirectory = Join-Path $versionFull ('staged-commits\' + $operationId)
                New-Item -ItemType Directory -Path $archiveDirectory -Force | Out-Null
                $backup = Join-Path $archiveDirectory ([IO.Path]::GetFileName($target))
                [IO.File]::Replace($temporary, $target, $backup, $true)
            } else {
                [IO.File]::Move($temporary, $target)
            }
            $writtenHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $target).Hash.ToLowerInvariant()
            if ($writtenHash -ne $candidateHash) { throw 'Committed file hash mismatch.' }
            $result.status = 'local-drive-written'
            $result.actual_sha256 = $writtenHash
            $result.error_code = $null
        }
        catch {
            if ($result.status -ne 'conflict') {
                $result.status = 'failed'
                if ($result.error_code -eq $null) { $result.error_code = 'COMMIT_FAILED' }
            }
        }
        finally {
            $resultPath = Join-Path $resultRoot ($operationId + '.json')
            Write-TWWaterStagedCommitJson -Path $resultPath -Payload $result
            Remove-Item -LiteralPath $requestPath.FullName -Force -ErrorAction SilentlyContinue
            $processed++
        }
    }
    return $processed
}
