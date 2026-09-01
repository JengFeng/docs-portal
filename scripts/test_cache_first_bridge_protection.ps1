[CmdletBinding()]
param([string] $ProjectRoot = '')
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
}
$ProjectRoot = (Get-Item -LiteralPath $ProjectRoot -Force -ErrorAction Stop).FullName.TrimEnd('\','/')
$bridgeScript = Join-Path $ProjectRoot 'scripts\document_bridge.ps1'
$testRoot = Join-Path $ProjectRoot ('.cache-first-bridge-test-' + [Guid]::NewGuid().ToString('N'))

function Assert-True([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw $Message }
}
function Write-Utf8([string] $Path, [string] $Content) {
    $parent = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $parent)) { [void][IO.Directory]::CreateDirectory($parent) }
    [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding($false)))
}
function Get-Sha256([string] $Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

try {
    $sourceRoot = Join-Path $testRoot 'source'
    $cacheRoot = Join-Path $testRoot 'cache'
    $runtimeRoot = Join-Path $testRoot 'runtime'
    $sessionRoot = Join-Path $testRoot 'sessions'
    $versionRoot = Join-Path $testRoot 'versions'
    $stagingRoot = Join-Path $testRoot 'staging'
    $commitRoot = Join-Path $testRoot 'commit-channel'
    foreach ($path in @($sourceRoot,$runtimeRoot,$sessionRoot,$versionRoot,$stagingRoot,$commitRoot)) {
        [void][IO.Directory]::CreateDirectory($path)
    }

    $relativePath = '網站文件\保護.md'
    $sourceFile = Join-Path $sourceRoot $relativePath
    Write-Utf8 -Path $sourceFile -Content 'source v1'
    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -Once -SkipWake -SkipSignal

    $cacheFile = Join-Path $cacheRoot $relativePath
    Assert-True (Test-Path -LiteralPath $cacheFile -PathType Leaf) 'Initial cache copy is missing.'
    $baseHash = Get-Sha256 -Path $sourceFile
    Write-Utf8 -Path $cacheFile -Content 'local v2'
    $workingHash = Get-Sha256 -Path $cacheFile

    $documentId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    $stateRoot = Join-Path (Split-Path -Parent $cacheFile) '.twwater-state'
    [void][IO.Directory]::CreateDirectory($stateRoot)
    $markerPath = Join-Path $stateRoot ($documentId + '.json')
    $marker = [ordered]@{
        schema_version = 1
        document_id = $documentId
        relative_path = '網站文件/保護.md'
        generation = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
        status = 'pending'
        base_source_sha256 = $baseHash
        working_sha256 = $workingHash
        observed_source_sha256 = $null
        error_code = $null
        not_before_utc = '2099-01-01T00:00:00Z'
        updated_utc = '2026-08-30T00:00:00Z'
    }
    Write-Utf8 -Path $markerPath -Content ($marker | ConvertTo-Json -Compress)
    Start-Sleep -Milliseconds 300
    Write-Utf8 -Path $sourceFile -Content 'external v2'

    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -EnableCacheFirstWriteback -Once -SkipWake -SkipSignal

    $logPath = Join-Path $runtimeRoot 'document-bridge.jsonl'
    $reconcileEntries = @()
    foreach ($line in [IO.File]::ReadAllLines($logPath, [Text.Encoding]::UTF8)) {
        $entry = $line | ConvertFrom-Json
        if ($entry.event -eq 'reconcile_succeeded') { $reconcileEntries += $entry }
    }
    $lastReconcile = $reconcileEntries[-1]
    Assert-True ([int]$lastReconcile.protected -eq 1) ("Expected one protected cache document; actual=" + [string]$lastReconcile.protected)
    Assert-True ([IO.File]::ReadAllText($cacheFile) -eq 'local v2') 'Dirty website cache was overwritten by source reconcile.'
    Assert-True ([IO.File]::ReadAllText($sourceFile) -eq 'external v2') 'Reconcile must not write the Drive source.'
    Assert-True (Test-Path -LiteralPath $markerPath -PathType Leaf) 'Dirty marker was removed or quarantined.'

    $marker.not_before_utc = '2000-01-01T00:00:00Z'
    $marker.updated_utc = '2026-08-30T00:05:00Z'
    Write-Utf8 -Path $markerPath -Content ($marker | ConvertTo-Json -Compress)
    $resultRoot = Join-Path $stateRoot 'results'
    $resultPath = Join-Path $resultRoot ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.json')
    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -Once -SkipWake -SkipSignal
    Assert-True (-not (Test-Path -LiteralPath $resultPath)) 'Default Bridge invocation must not process cache-first write-back markers.'

    Write-Utf8 -Path $cacheFile -Content 'local v2'
    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -EnableCacheFirstWriteback -Once -SkipWake -SkipSignal

    Assert-True (Test-Path -LiteralPath $resultPath -PathType Leaf) 'Due conflict must create an immutable generation result.'
    $conflict = [IO.File]::ReadAllText($resultPath, [Text.Encoding]::UTF8) | ConvertFrom-Json
    $requestAfterConflict = [IO.File]::ReadAllText($markerPath, [Text.Encoding]::UTF8) | ConvertFrom-Json
    $observedSourceHash = Get-Sha256 -Path $sourceFile
    Assert-True ($requestAfterConflict.status -eq 'pending') 'Bridge must not overwrite the current request marker in place.'
    Assert-True ($conflict.status -eq 'conflict') 'Due write-back with changed Drive source must become conflict.'
    Assert-True ($conflict.generation -eq 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb') 'Conflict result must be fenced to the request generation.'
    Assert-True ($conflict.observed_source_sha256 -eq $observedSourceHash) 'Conflict result must record the observed Drive hash.'
    Assert-True ($conflict.error_code -eq 'SOURCE_CHANGED') 'Conflict result must use stable SOURCE_CHANGED code.'
    Assert-True ($null -eq $conflict.not_before_utc) 'Conflict result must disable automatic retry.'
    Assert-True ([IO.File]::ReadAllText($cacheFile) -eq 'local v2') 'Conflict handling changed website cache bytes.'
    Assert-True ([IO.File]::ReadAllText($sourceFile) -eq 'external v2') 'Conflict handling overwrote Drive source bytes.'

    $successRelativePath = '網站文件\成功.md'
    $successWirePath = '網站文件/成功.md'
    $successSourceFile = Join-Path $sourceRoot $successRelativePath
    Write-Utf8 -Path $successSourceFile -Content 'success source v1'
    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -EnableCacheFirstWriteback -Once -SkipWake -SkipSignal
    $successCacheFile = Join-Path $cacheRoot $successRelativePath
    $successBaseHash = Get-Sha256 -Path $successSourceFile
    Write-Utf8 -Path $successCacheFile -Content 'success local v2'
    $successWorkingHash = Get-Sha256 -Path $successCacheFile
    $successDocumentId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
    $successGeneration = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
    $successMarkerPath = Join-Path $stateRoot ($successDocumentId + '.json')
    $successMarker = [ordered]@{
        schema_version = 1
        document_id = $successDocumentId
        relative_path = $successWirePath
        generation = $successGeneration
        status = 'pending'
        base_source_sha256 = $successBaseHash
        working_sha256 = $successWorkingHash
        observed_source_sha256 = $null
        error_code = $null
        not_before_utc = '2000-01-01T00:00:00Z'
        updated_utc = '2026-08-30T00:10:00Z'
    }
    Write-Utf8 -Path $successMarkerPath -Content ($successMarker | ConvertTo-Json -Compress)
    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -EnableCacheFirstWriteback -Once -SkipWake -SkipSignal

    $successResultPath = Join-Path $resultRoot ($successGeneration + '.json')
    Assert-True (Test-Path -LiteralPath $successResultPath -PathType Leaf) 'Successful write-back must create an immutable generation result.'
    $successResult = [IO.File]::ReadAllText($successResultPath, [Text.Encoding]::UTF8) | ConvertFrom-Json
    Assert-True ($successResult.status -eq 'synced') 'Successful write-back result must be synced.'
    Assert-True ($successResult.observed_source_sha256 -eq $successWorkingHash) 'Successful result must bind the final source hash.'
    Assert-True ($null -eq $successResult.error_code) 'Successful result must not contain an error code.'
    Assert-True ([IO.File]::ReadAllText($successSourceFile) -eq 'success local v2') 'Successful write-back did not atomically update source bytes.'
    Assert-True ([IO.File]::ReadAllText($successCacheFile) -eq 'success local v2') 'Successful write-back changed cache bytes.'
    $archivedSource = Join-Path (Join-Path (Join-Path $versionRoot $successDocumentId) $successBaseHash) 'source.md'
    Assert-True (Test-Path -LiteralPath $archivedSource -PathType Leaf) 'Successful write-back did not preserve the prior source version.'
    Assert-True ([IO.File]::ReadAllText($archivedSource) -eq 'success source v1') 'Archived prior source bytes are wrong.'

    Write-Utf8 -Path $resultPath -Content '{"schema_version":1,"status":"conflict"}'
    $tamperedRejected = $false
    try {
        & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $commitRoot -EnableCacheFirstWriteback -Once -SkipWake -SkipSignal
    }
    catch {
        $tamperedRejected = $true
    }
    Assert-True $tamperedRejected 'Malformed existing generation result must fail closed.'
    Assert-True ([IO.File]::ReadAllText($cacheFile) -eq 'local v2') 'Malformed result handling changed cache bytes.'
    Assert-True ([IO.File]::ReadAllText($sourceFile) -eq 'external v2') 'Malformed result handling changed source bytes.'
    Write-Host '[OK] Cache-first Bridge protection, conflict fencing, successful archived write-back, and malformed-result rejection passed.'
}
finally {
    if (Test-Path -LiteralPath $testRoot) {
        $normalizedProject = [IO.Path]::GetFullPath($ProjectRoot).TrimEnd('\','/') + [IO.Path]::DirectorySeparatorChar
        $normalizedTest = [IO.Path]::GetFullPath($testRoot)
        if ($normalizedTest.StartsWith($normalizedProject,[StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}
