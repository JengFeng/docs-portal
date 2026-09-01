[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'document_version_engine.ps1')

function Assert-True {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function Get-HashLower {
    param([string] $Path)
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

$root = Join-Path ([IO.Path]::GetTempPath()) ('twwater-version-engine-' + [Guid]::NewGuid().ToString('N'))
$sourceRoot = Join-Path $root 'source'
$versionRoot = Join-Path $root 'versions'
$sessionRoot = Join-Path $root 'sessions'
$candidateRoot = Join-Path $root 'candidate'
foreach ($directory in @($sourceRoot, $versionRoot, $sessionRoot, $candidateRoot)) {
    [void][IO.Directory]::CreateDirectory($directory)
}
$relativePath = 'folder\deck.pptx'
$sourcePath = Join-Path $sourceRoot $relativePath
[void][IO.Directory]::CreateDirectory((Split-Path -Parent $sourcePath))
$candidatePath = Join-Path $candidateRoot 'revised.pptx'
[IO.File]::WriteAllText($sourcePath, 'original-version')
[IO.File]::WriteAllText($candidatePath, 'revised-version')
$oldHash = Get-HashLower $sourcePath
$newHash = Get-HashLower $candidatePath
$publicId = '8d22046c-ece0-4818-a67e-12799b05ae25'

try {
    $injected = $false
    try {
        [void](Publish-TWWaterDocumentVersion -SourceRoot $sourceRoot -VersionRoot $versionRoot -SessionRoot $sessionRoot -DocumentPublicId $publicId -RelativePath $relativePath -CandidatePath $candidatePath -ExpectedCurrentHash $oldHash -Reason 'publish' -ActorReference 'test-user' -FailurePoint 'ApprovalWrite')
    } catch { $injected = $_.Exception.Message -eq 'INJECTED_APPROVAL_WRITE_FAILURE' }
    Assert-True $injected 'Approval failure injection must abort publish.'
    Assert-True ((Get-HashLower $sourcePath) -eq $oldHash) 'Post-replace failure must restore the authoritative source.'
    $actualResultFailureParent=Join-Path $root 'completed-parent-is-file'
    [IO.File]::WriteAllText($actualResultFailureParent,'not-a-directory')
    $actualResultFailurePath=Join-Path $actualResultFailureParent 'result.json'
    $resultFailure=$false
    try {[void](Publish-TWWaterDocumentVersion -SourceRoot $sourceRoot -VersionRoot $versionRoot -SessionRoot $sessionRoot -DocumentPublicId $publicId -RelativePath $relativePath -CandidatePath $candidatePath -ExpectedCurrentHash $oldHash -Reason 'publish' -ActorReference 'test-user' -CompletedResultPath $actualResultFailurePath)} catch {$resultFailure=$_.Exception.Message -match 'JSON parent must be a real directory'}
    Assert-True $resultFailure 'The real completed-result JSON write must fail at its filesystem destination.'
    Assert-True ((Get-HashLower $sourcePath) -eq $oldHash) 'Actual completed-result write failure must restore the authoritative source.'
    $failedApproval=Join-Path (Join-Path $sessionRoot 'document-version-approved') ($publicId+'-'+$newHash+'.json')
    Assert-True (-not(Test-Path -LiteralPath $failedApproval)) 'Actual result-write failure must remove the approval marker.'
    $published = Publish-TWWaterDocumentVersion `
        -SourceRoot $sourceRoot `
        -VersionRoot $versionRoot `
        -SessionRoot $sessionRoot `
        -DocumentPublicId $publicId `
        -RelativePath $relativePath `
        -CandidatePath $candidatePath `
        -ExpectedCurrentHash $oldHash `
        -Reason 'publish' `
        -ActorReference 'test-user'

    Assert-True ($published.PreviousHash -eq $oldHash) 'Publish previous hash mismatch.'
    Assert-True ($published.CurrentHash -eq $newHash) 'Publish current hash mismatch.'
    Assert-True ((Get-HashLower $sourcePath) -eq $newHash) 'Publish must atomically replace the authoritative source.'
    $oldArtifact = Join-Path (Join-Path (Join-Path $versionRoot $publicId) $oldHash) 'source.pptx'
    $oldManifest = Join-Path (Split-Path -Parent $oldArtifact) 'manifest.json'
    Assert-True (Test-Path -LiteralPath $oldArtifact -PathType Leaf) 'Publish must archive the prior version.'
    Assert-True ((Get-HashLower $oldArtifact) -eq $oldHash) 'Archived prior version hash mismatch.'
    Assert-True (Test-Path -LiteralPath $oldManifest -PathType Leaf) 'Archived prior version manifest is missing.'
    $approvedNew = Join-Path (Join-Path $sessionRoot 'document-version-approved') ($publicId + '-' + $newHash + '.json')
    Assert-True (Test-Path -LiteralPath $approvedNew -PathType Leaf) 'Publish approval marker is missing.'

    $pendingRoot = Join-Path (Join-Path $sessionRoot 'document-version-requests') 'pending'
    [void][IO.Directory]::CreateDirectory($pendingRoot)
    $operationId = [Guid]::NewGuid().ToString()
    $request = [ordered]@{
        schema_version = 1
        operation = 'restore'
        operation_id = $operationId
        document_public_id = $publicId
        document_relative_path = $relativePath.Replace('\', '/')
        original_file_name = 'deck.pptx'
        extension = 'pptx'
        target_content_hash = $oldHash
        expected_current_hash = $newHash
        requested_by_user_id = 7
        requested_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    }
    $requestPath = Join-Path $pendingRoot ($operationId + '.json')
    [IO.File]::WriteAllText($requestPath, ($request | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))

    $processed = Invoke-TWWaterDocumentVersionRequests `
        -SourceRoot $sourceRoot `
        -VersionRoot $versionRoot `
        -SessionRoot $sessionRoot
    Assert-True ($processed -eq 1) 'Exactly one restore request must be processed.'
    Assert-True ((Get-HashLower $sourcePath) -eq $oldHash) 'Restore must replace the authoritative source with the selected archive.'
    $newArtifact = Join-Path (Join-Path (Join-Path $versionRoot $publicId) $newHash) 'source.pptx'
    Assert-True (Test-Path -LiteralPath $newArtifact -PathType Leaf) 'Restore must archive the version it replaces.'
    Assert-True ((Get-HashLower $newArtifact) -eq $newHash) 'Restore backup hash mismatch.'
    $completed = Join-Path (Join-Path (Join-Path $sessionRoot 'document-version-requests') 'completed') ($operationId + '.json')
    Assert-True (Test-Path -LiteralPath $completed -PathType Leaf) 'Restore completion record is missing.'
    $result = Get-Content -LiteralPath $completed -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-True ([string]$result.status -eq 'local-restored') 'Restore completion status must expose the local-restored stage.'
    Assert-True ([string]$result.previous_hash -eq $newHash) 'Restore result previous hash mismatch.'
    Assert-True ([string]$result.current_hash -eq $oldHash) 'Restore result current hash mismatch.'
    $approvedOld = Join-Path (Join-Path $sessionRoot 'document-version-approved') ($publicId + '-' + $oldHash + '.json')
    Assert-True (Test-Path -LiteralPath $approvedOld -PathType Leaf) 'Restore approval marker is missing.'

    $conflictOperationId = [Guid]::NewGuid().ToString()
    $conflictRequest = [ordered]@{
        schema_version = 1
        operation = 'restore'
        operation_id = $conflictOperationId
        document_public_id = $publicId
        document_relative_path = $relativePath.Replace('\', '/')
        original_file_name = 'deck.pptx'
        extension = 'pptx'
        target_content_hash = $oldHash
        expected_current_hash = ('f' * 64)
        requested_by_user_id = 7
        requested_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    }
    $conflictPath = Join-Path $pendingRoot ($conflictOperationId + '.json')
    [IO.File]::WriteAllText($conflictPath, ($conflictRequest | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
    [void](Invoke-TWWaterDocumentVersionRequests -SourceRoot $sourceRoot -VersionRoot $versionRoot -SessionRoot $sessionRoot)
    $failedPath = Join-Path (Join-Path (Join-Path $sessionRoot 'document-version-requests') 'failed') ($conflictOperationId + '.json')
    Assert-True (Test-Path -LiteralPath $failedPath -PathType Leaf) 'Conflict result record is missing.'
    $conflict = Get-Content -LiteralPath $failedPath -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-True ([string]$conflict.status -eq 'conflict') ('Stale-current restore must be reported as conflict; actual=' + [string]$conflict.status + ';code=' + [string]$conflict.error_code)
    Assert-True ([string]$conflict.error_code -eq 'CURRENT_VERSION_CONFLICT') 'Conflict error code mismatch.'

    $bridgeSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'document_bridge.ps1') -Raw -Encoding UTF8
    Assert-True ($bridgeSource -match '\[string\]\s+\$VersionRoot') 'Document bridge must accept a protected version root.'
    Assert-True ($bridgeSource -match "document_version_engine\.ps1") 'Document bridge must load the version engine.'
    Assert-True ($bridgeSource -match 'Invoke-TWWaterDocumentVersionRequests') 'Document bridge must process queued restore operations.'
    $publishScriptPath = Join-Path $PSScriptRoot 'publish_document_version.ps1'
    Assert-True (Test-Path -LiteralPath $publishScriptPath -PathType Leaf) 'Controlled publish CLI is missing.'
    $publishSource = Get-Content -LiteralPath $publishScriptPath -Raw -Encoding UTF8
    Assert-True ($publishSource -match 'Publish-TWWaterDocumentVersion') 'Controlled publish CLI must call the shared version engine.'
    $tokens = $null
    $parseErrors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile($publishScriptPath, [ref]$tokens, [ref]$parseErrors)
    Assert-True ($parseErrors.Count -eq 0) 'Controlled publish CLI must parse under Windows PowerShell 5.1.'
    $installScriptPath = Join-Path $PSScriptRoot 'install_document_version_management.ps1'
    Assert-True (Test-Path -LiteralPath $installScriptPath -PathType Leaf) 'Version management installer is missing.'
    $installSource = Get-Content -LiteralPath $installScriptPath -Raw -Encoding UTF8
    Assert-True ($installSource -match 'PORTAL_DOCUMENT_VERSION_ROOT') 'Installer must configure the IIS version root.'
    Assert-True ($installSource -match 'ReadAndExecute') 'Installer must keep the IIS identity read-only on version archives.'
    Assert-True ($installSource -match '\[string\]\s+\$CacheRoot') 'Installer must accept the active IIS document cache root.'
    Assert-True ($installSource -match 'PORTAL_DOCUMENT_ROOT') 'Installer must derive the active cache root from IIS instead of assuming a folder name.'
    Assert-True ($installSource -match 'New-ScheduledTaskAction') 'Installer must repair stale bridge task paths.'
    Assert-True ($installSource -match "'-VersionRoot'") 'Installer must pin the bridge task to the protected version root.'
    Assert-True ($installSource -match '\$currentTaskState') 'Installer must explicitly read and stop an already-running bridge task before restart.'
    Assert-True ($installSource -match 'cache_acl_repair\.ps1') 'Installer must load the bounded cache ACL repair helper.'
    Assert-True ($installSource -match 'Repair-TWWaterCacheChildAclInheritance') 'Installer must repair protected child ACLs before restarting the bridge.'
    Assert-True ($installSource -match '\$taskStableDeadline') 'Installer must verify the bridge remains running after startup.'
    $createStart = $installSource.IndexOf("`$target = `$variables.CreateElement('add')")
    $valueSet = $installSource.IndexOf("`$target['value'] = `$Value", $createStart)
    $collectionAdd = $installSource.IndexOf('[void]$variables.Add($target)', $createStart)
    Assert-True ($createStart -ge 0 -and $valueSet -gt $createStart -and $collectionAdd -gt $valueSet) 'Installer must set required name and value before adding a new IIS environment element.'

    Write-Host '[OK] Document version publish and restore engine contract passed.'
}
finally {
    if (Test-Path -LiteralPath $root) {
        Remove-Item -LiteralPath $root -Recurse -Force
    }
}
