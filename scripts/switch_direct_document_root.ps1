[CmdletBinding()]
param(
    [switch] $Apply,
    [switch] $Rollback,
    [switch] $MigrateEvidenceOnly,
    [string] $AppPoolName = 'TWWATER_PortalPool',
    [string] $DocumentRoot = 'C:\web\gary\TWWATER\document-library',
    [string] $BackupRoot = 'C:\TWWATER\maintenance-backups\20260830-130354-direct-document-root'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'direct_root_evidence.ps1')
. (Join-Path $PSScriptRoot 'direct_root_switch_transaction.ps1')

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-AppPoolVariable {
    param([Parameter(Mandatory)] $Pool, [Parameter(Mandatory)][string] $Name)
    foreach ($entry in $Pool.GetChildElement('environmentVariables').GetCollection()) {
        if ([string]::Equals([string]$entry['name'], $Name, [StringComparison]::OrdinalIgnoreCase)) { return $entry }
    }
    return $null
}

function Set-AppPoolVariable {
    param(
        [Parameter(Mandatory)] $Pool,
        [Parameter(Mandatory)][string] $Name,
        [AllowEmptyString()][string] $Value,
        [bool] $RemoveWhenEmpty = $false
    )
    $collection = $Pool.GetChildElement('environmentVariables').GetCollection()
    $entry = Get-AppPoolVariable -Pool $Pool -Name $Name
    if ($RemoveWhenEmpty -and [string]::IsNullOrEmpty($Value)) {
        if ($null -ne $entry) { $collection.Remove($entry) }
        return
    }
    if ($null -eq $entry) {
        $entry = $collection.CreateElement('add')
        $entry['name'] = $Name
        [void]$collection.Add($entry)
    }
    $entry['value'] = $Value
}

function Assert-NoReparsePath {
    param([Parameter(Mandatory)][string] $Path)
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    $root = [IO.Path]::GetPathRoot($full)
    if ([string]::IsNullOrEmpty($root)) { throw 'DIRECT_ROOT_ABSOLUTE_PATH_REQUIRED' }
    $current = $root.TrimEnd('\')
    $relative = $full.Substring($root.Length)
    foreach ($segment in @($relative -split '\\' | Where-Object { $_ -ne '' })) {
        $current = Join-Path $current $segment
        $item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
        if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'DIRECT_ROOT_REPARSE_COMPONENT_REJECTED' }
    }
    return $full
}

function Import-IisAdministration {
    $dll = Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll'
    Add-Type -Path $dll -ErrorAction SilentlyContinue
}

function Read-AppPoolRootState {
    param([Parameter(Mandatory)][string] $PoolName, [Parameter(Mandatory)][string] $VariableName)
    $manager = New-Object Microsoft.Web.Administration.ServerManager
    try {
        $pool = $manager.ApplicationPools[$PoolName]
        if ($null -eq $pool) { throw 'DIRECT_ROOT_APP_POOL_NOT_FOUND' }
        $entry = Get-AppPoolVariable -Pool $pool -Name $VariableName
        return [pscustomobject]@{
            existed = ($null -ne $entry)
            value = if ($null -ne $entry) { [string]$entry['value'] } else { '' }
        }
    }
    finally { $manager.Dispose() }
}

function Commit-AppPoolRootState {
    param(
        [Parameter(Mandatory)][string] $PoolName,
        [Parameter(Mandatory)][string] $VariableName,
        [AllowEmptyString()][string] $Value,
        [Parameter(Mandatory)][bool] $Exists
    )
    $manager = New-Object Microsoft.Web.Administration.ServerManager
    try {
        $pool = $manager.ApplicationPools[$PoolName]
        if ($null -eq $pool) { throw 'DIRECT_ROOT_APP_POOL_NOT_FOUND' }
        Set-AppPoolVariable -Pool $pool -Name $VariableName -Value $Value -RemoveWhenEmpty:(-not $Exists)
        $manager.CommitChanges()
    }
    finally { $manager.Dispose() }
}

function Test-AppPoolRootState {
    param(
        [Parameter(Mandatory)][string] $PoolName,
        [Parameter(Mandatory)][string] $VariableName,
        [AllowEmptyString()][string] $ExpectedValue,
        [Parameter(Mandatory)][bool] $ExpectedExists
    )
    $state = Read-AppPoolRootState -PoolName $PoolName -VariableName $VariableName
    if ([bool]$state.existed -ne $ExpectedExists) { return $false }
    if (-not $ExpectedExists) { return $true }
    return [string]::Equals(([string]$state.value).TrimEnd('\'), $ExpectedValue.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)
}

function Recycle-AppPool {
    param([Parameter(Mandatory)][string] $PoolName)
    $manager = New-Object Microsoft.Web.Administration.ServerManager
    try {
        $pool = $manager.ApplicationPools[$PoolName]
        if ($null -eq $pool) { throw 'DIRECT_ROOT_APP_POOL_NOT_FOUND_BEFORE_RECYCLE' }
        [void]$pool.Recycle()
    }
    finally { $manager.Dispose() }
}

function Wait-AppPoolReadyAndRoot {
    param([Parameter(Mandatory)][string] $PoolName,[Parameter(Mandatory)][string] $VariableName,[AllowEmptyString()][string] $ExpectedValue,[Parameter(Mandatory)][bool] $ExpectedExists,[int] $TimeoutSeconds = 30)
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        $manager = New-Object Microsoft.Web.Administration.ServerManager
        try { $pool = $manager.ApplicationPools[$PoolName]; $started = $null -ne $pool -and [string]$pool.State -ceq 'Started' }
        finally { $manager.Dispose() }
        if ($started -and (Test-AppPoolRootState -PoolName $PoolName -VariableName $VariableName -ExpectedValue $ExpectedValue -ExpectedExists $ExpectedExists)) { return $true }
        Start-Sleep -Milliseconds 250
    } while ([DateTime]::UtcNow -lt $deadline)
    return $false
}

function Write-PreparedDirectRootState {
    param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)] $Value)
    $validatedPath = Assert-DirectRootEvidencePath -Path $Path
    $bytes = [Text.Encoding]::UTF8.GetBytes(($Value | ConvertTo-Json -Depth 8))
    $stream = New-Object IO.FileStream($validatedPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None, 4096, [IO.FileOptions]::WriteThrough)
    try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) }
    finally { $stream.Dispose() }
    $readBack = Get-Content -Raw -LiteralPath $validatedPath | ConvertFrom-Json
    if ([string]$readBack.status -cne 'prepared' -or [string]$readBack.app_pool -cne $AppPoolName) {
        throw 'DIRECT_ROOT_PREPARED_READBACK_FAILED'
    }
}

$modeCount = ([int][bool]$Apply) + ([int][bool]$Rollback) + ([int][bool]$MigrateEvidenceOnly)
if ($modeCount -gt 1) { throw 'DIRECT_ROOT_MODE_CONFLICT' }

$approvedAppPool = 'TWWATER_PortalPool'
$approvedBackupRoot = [IO.Path]::GetFullPath('C:\TWWATER\maintenance-backups\20260830-130354-direct-document-root').TrimEnd('\')
if ($AppPoolName -cne $approvedAppPool) { throw 'DIRECT_ROOT_APP_POOL_IDENTITY_MISMATCH' }
if (-not [IO.Path]::IsPathRooted($BackupRoot)) { throw 'DIRECT_ROOT_BACKUP_ROOT_IDENTITY_MISMATCH' }
$canonicalBackupRoot = [IO.Path]::GetFullPath($BackupRoot).TrimEnd('\')
if (-not [string]::Equals($canonicalBackupRoot, $approvedBackupRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'DIRECT_ROOT_BACKUP_ROOT_IDENTITY_MISMATCH' }
$BackupRoot = $canonicalBackupRoot
$expectedRoot = [IO.Path]::GetFullPath('C:\web\gary\TWWATER\document-library').TrimEnd('\')
if (-not [IO.Path]::IsPathRooted($DocumentRoot)) { throw 'DIRECT_ROOT_PATH_NOT_ALLOWLISTED' }
$resolvedRoot = [IO.Path]::GetFullPath($DocumentRoot).TrimEnd('\')
if (-not [string]::Equals($resolvedRoot, $expectedRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'DIRECT_ROOT_PATH_NOT_ALLOWLISTED' }
$resolvedRoot = Assert-NoReparsePath -Path $resolvedRoot
$rootItem = Get-Item -LiteralPath $resolvedRoot -Force
if (-not $rootItem.PSIsContainer) { throw 'DIRECT_ROOT_MUST_BE_REAL_DIRECTORY' }
[void](Assert-DirectRootEvidencePath -Path (Join-Path $BackupRoot 'manifest.json'))
if (-not (Test-Path -LiteralPath (Join-Path $BackupRoot 'manifest.json') -PathType Leaf)) { throw 'DIRECT_ROOT_BACKUP_MANIFEST_MISSING' }

$successStatePath = Join-Path $BackupRoot 'direct-root-switch-state.json'
$preparedStatePath = Join-Path $BackupRoot 'direct-root-switch-prepared.json'
$rollbackStatePath = Join-Path $BackupRoot 'direct-root-rollback-state.json'
$legacyStatePath = Join-Path $BackupRoot 'direct-root-switch-state-v1-preupgrade.json'
$schema2ArchivePath = Join-Path $BackupRoot 'direct-root-switch-state-schema2-pre-migration.json'
$immutableLegacySha256 = 'd39aaa02c9946033cb0c3a2ccf16da1e34a7f3fad60eda40093589da94677219'
$variableName = 'PORTAL_DOCUMENT_ROOT'
$reconciliation = $null

if ($Rollback) {
    $reconciliation = Resolve-DirectRootEvidenceSet -PrimaryPath $successStatePath -PreparedPath $preparedStatePath -ExpectedAppPool $AppPoolName -ExpectedTarget $resolvedRoot -ExpectedBackupRoot $BackupRoot
    if ($reconciliation.status -cne 'primary_valid' -or [string]$reconciliation.selected.record.status -cne 'applied') {
        throw 'DIRECT_ROOT_ROLLBACK_STRICT_EVIDENCE_REQUIRED'
    }
}

if (-not $Apply -and -not $MigrateEvidenceOnly) {
    $target = if ($Rollback) { [string]$reconciliation.selected.record.previous_document_root } else { $resolvedRoot }
    [pscustomobject]@{
        mode = if ($Rollback) { 'rollback-plan' } else { 'plan' }
        apply = $false
        app_pool = $AppPoolName
        target_document_root = $target
        backup_root = $BackupRoot
        mutations = if ($Rollback) { @('restore recorded PORTAL_DOCUMENT_ROOT', 'read back exact value', 'recycle exact AppPool') } else { @('set PORTAL_DOCUMENT_ROOT', 'read back exact value', 'recycle exact AppPool') }
    } | ConvertTo-Json -Compress
    exit 0
}

if ($MigrateEvidenceOnly) {
    Import-IisAdministration
    $migrationResult = Invoke-DirectRootEvidenceOnlyOperation `
        -ReadCurrent { return Read-AppPoolRootState -PoolName $AppPoolName -VariableName $variableName } `
        -VerifyCurrent { param($liveState) return [bool]$liveState.existed -and [string]::Equals(([string]$liveState.value).TrimEnd('\'), $resolvedRoot, [StringComparison]::OrdinalIgnoreCase) } `
        -PublishEvidence {
            param($liveState)
            $existing = Resolve-DirectRootEvidenceSet -PrimaryPath $successStatePath -PreparedPath $preparedStatePath -ExpectedAppPool $AppPoolName -ExpectedTarget $resolvedRoot -ExpectedBackupRoot $BackupRoot
            if ($existing.status -ceq 'primary_valid') {
                $existingRecord = $existing.selected.record
                if ([string]$existingRecord.result_code -cne 'DIRECT_ROOT_ALREADY_APPLIED_VERIFIED' -or [string]$existingRecord.source_evidence_sha256 -cne $immutableLegacySha256) { throw 'DIRECT_ROOT_MIGRATION_EXISTING_EVIDENCE_CONFLICT' }
                return [pscustomobject]@{ publication_outcome='PUBLISHED_VERIFIED'; mode='evidence-already-migrated'; apply=$false; evidence_changed=$false; state_path=$successStatePath; generation_id=[string]$existingRecord.generation_id; evidence_migrated_utc=[string]$existingRecord.evidence_migrated_utc }
            }
            if ($existing.status -cne 'unavailable') { throw 'DIRECT_ROOT_MIGRATION_EVIDENCE_AMBIGUOUS' }
            if (-not (Test-Path -LiteralPath $legacyStatePath -PathType Leaf)) { throw 'DIRECT_ROOT_IMMUTABLE_LEGACY_EVIDENCE_MISSING' }
            $sourceHash = ([string](Get-FileHash -Algorithm SHA256 -LiteralPath $legacyStatePath).Hash).ToLowerInvariant()
            if ($sourceHash -cne $immutableLegacySha256) { throw 'DIRECT_ROOT_IMMUTABLE_LEGACY_HASH_MISMATCH' }
            $legacy = Get-Content -Raw -LiteralPath $legacyStatePath | ConvertFrom-Json
            if (-not (Test-Path -LiteralPath $successStatePath -PathType Leaf)) { throw 'DIRECT_ROOT_SCHEMA2_EVIDENCE_MISSING' }
            $schema2 = Get-Content -Raw -LiteralPath $successStatePath | ConvertFrom-Json
            $schema2Identity = Test-DirectRootSchema2MigrationIdentity -Record $schema2 -Legacy $legacy -ExpectedAppPool $AppPoolName -ExpectedTarget $resolvedRoot -ExpectedBackupRoot $BackupRoot
            if (-not $schema2Identity.valid) { throw ('DIRECT_ROOT_SCHEMA2_IDENTITY_INVALID:' + ($schema2Identity.errors -join ',')) }
            $schema3 = ConvertFrom-DirectRootLegacyEvidence -Legacy $legacy -ExpectedAppPool $AppPoolName -ExpectedTarget $resolvedRoot -ExpectedBackupRoot $BackupRoot -SourceEvidenceSha256 $sourceHash -EvidenceMigratedUtc ([DateTime]::UtcNow.ToString('o'))
            $publication = Write-DirectRootEvidenceDurable -Record $schema3 -PrimaryPath $successStatePath -RecoveryPath $schema2ArchivePath -ExpectedAppPool $AppPoolName -ExpectedTarget $resolvedRoot -ExpectedBackupRoot $BackupRoot
            if ([string]$publication.publication_outcome -cne 'PUBLISHED_VERIFIED') { return $publication }
            return [pscustomobject]@{ publication_outcome='PUBLISHED_VERIFIED'; mode='evidence-migrated'; apply=$false; evidence_changed=$true; state_path=$successStatePath; generation_id=[string]$schema3.generation_id; evidence_migrated_utc=[string]$schema3.evidence_migrated_utc }
        }
    $migrationResult | Select-Object mode,apply,evidence_changed,state_path,generation_id,evidence_migrated_utc | ConvertTo-Json -Compress
    exit 0
}

if (-not (Test-IsAdministrator)) { throw 'DIRECT_ROOT_ADMINISTRATOR_REQUIRED' }
Import-IisAdministration
$current = Read-AppPoolRootState -PoolName $AppPoolName -VariableName $variableName

if (-not $Rollback) {
    $reconciliation = Resolve-DirectRootEvidenceSet -PrimaryPath $successStatePath -PreparedPath $preparedStatePath -ExpectedAppPool $AppPoolName -ExpectedTarget $resolvedRoot -ExpectedBackupRoot $BackupRoot
    $currentIsTarget = [bool]$current.existed -and [string]::Equals(([string]$current.value).TrimEnd('\'), $resolvedRoot, [StringComparison]::OrdinalIgnoreCase)
    if ($currentIsTarget) {
        if ($reconciliation.status -cne 'primary_valid' -or [string]$reconciliation.selected.record.status -cne 'applied') {
            throw 'DIRECT_ROOT_ALREADY_APPLIED_STRICT_SCHEMA3_PRIMARY_REQUIRED'
        }
        [pscustomobject]@{
            mode = 'already-applied'
            apply = $true
            app_pool = $AppPoolName
            result_code = [string]$reconciliation.selected.record.result_code
            verified_document_root = $resolvedRoot
            evidence_changed = $false
            cleanup_pending = [bool]$reconciliation.cleanup_pending
            state_path = $successStatePath
        } | ConvertTo-Json -Compress
        exit 0
    }
    if ((Test-Path -LiteralPath $successStatePath -PathType Leaf) -or $reconciliation.status -cne 'unavailable') {
        throw 'DIRECT_ROOT_APPLY_EXISTING_EVIDENCE_CONFLICT'
    }
}

$target = @{ Exists = $true; Value = $resolvedRoot }
$transactionPrevious = @{ Exists = [bool]$current.existed; Value = [string]$current.value }
$mode = 'applied'
$evidencePath = $successStatePath
$resultCode = 'DIRECT_ROOT_APPLIED_VERIFIED'
$sourceEvidenceHash = ''

if ($Rollback) {
    $accepted = $reconciliation.selected.record
    if (-not [bool]$current.existed -or -not [string]::Equals(([string]$current.value).TrimEnd('\'), ([string]$accepted.verified_document_root).TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'DIRECT_ROOT_ROLLBACK_CURRENT_STATE_CONFLICT'
    }
    if ([bool]$accepted.previous_existed) {
        $rollbackTarget = Assert-NoReparsePath -Path ([string]$accepted.previous_document_root)
        if (-not (Get-Item -LiteralPath $rollbackTarget -Force).PSIsContainer) { throw 'DIRECT_ROOT_ROLLBACK_TARGET_NOT_DIRECTORY' }
        $target = @{ Exists = $true; Value = $rollbackTarget }
    }
    else { $target = @{ Exists = $false; Value = '' } }
    $mode = 'rolled-back'
    $evidencePath = $rollbackStatePath
    $resultCode = 'DIRECT_ROOT_ROLLBACK_VERIFIED'
    $sourceEvidenceHash = [string]$accepted.payload_sha256
}
else {
    $prepared = [ordered]@{
        schema_version = 3
        status = 'prepared'
        prepared_utc = [DateTime]::UtcNow.ToString('o')
        app_pool = $AppPoolName
        previous_existed = [bool]$current.existed
        previous_document_root = [string]$current.value
        target_document_root = $resolvedRoot
        backup_root = $BackupRoot
    }
    Write-PreparedDirectRootState -Path $preparedStatePath -Value $prepared
    $sourceEvidenceHash = ([string](Get-FileHash -Algorithm SHA256 -LiteralPath $preparedStatePath).Hash).ToLowerInvariant()
}

$evidence = New-DirectRootEvidenceRecord `
    -AppPoolName $AppPoolName `
    -VerifiedDocumentRoot ([string]$target.Value) `
    -BackupRoot $BackupRoot `
    -PreviousExisted ([bool]$transactionPrevious.Exists) `
    -PreviousDocumentRoot ([string]$transactionPrevious.Value) `
    -Status $mode `
    -ResultCode $resultCode `
    -SourceEvidenceSha256 $sourceEvidenceHash
$verifiedTarget = ''

$transactionResult = Invoke-DirectRootSwitchTransaction `
    -CommitTarget { Commit-AppPoolRootState -PoolName $AppPoolName -VariableName $variableName -Value ([string]$target.Value) -Exists ([bool]$target.Exists) } `
    -VerifyTarget {
        $ok = Test-AppPoolRootState -PoolName $AppPoolName -VariableName $variableName -ExpectedValue ([string]$target.Value) -ExpectedExists ([bool]$target.Exists)
        if ($ok) { $verifiedTarget = [string]$target.Value }
        return $ok
    } `
    -RecycleTarget { Recycle-AppPool -PoolName $AppPoolName } `
    -WaitForTargetReady { return Wait-AppPoolReadyAndRoot -PoolName $AppPoolName -VariableName $variableName -ExpectedValue ([string]$target.Value) -ExpectedExists ([bool]$target.Exists) } `
    -WriteEvidence {
        return Write-DirectRootEvidenceDurable -Record $evidence -PrimaryPath $evidencePath -ExpectedAppPool $AppPoolName -ExpectedTarget ([string]$target.Value) -ExpectedBackupRoot $BackupRoot
    } `
    -RemovePreparedEvidence {
        if (-not $Rollback -and (Test-Path -LiteralPath $preparedStatePath -PathType Leaf)) { [IO.File]::Delete($preparedStatePath) }
    } `
    -RestorePrevious {
        Commit-AppPoolRootState -PoolName $AppPoolName -VariableName $variableName -Value ([string]$transactionPrevious.Value) -Exists ([bool]$transactionPrevious.Exists)
        Recycle-AppPool -PoolName $AppPoolName
    } `
    -WaitForRestoreReady { return Wait-AppPoolReadyAndRoot -PoolName $AppPoolName -VariableName $variableName -ExpectedValue ([string]$transactionPrevious.Value) -ExpectedExists ([bool]$transactionPrevious.Exists) } `
    -VerifyRestore {
        return Test-AppPoolRootState -PoolName $AppPoolName -VariableName $variableName -ExpectedValue ([string]$transactionPrevious.Value) -ExpectedExists ([bool]$transactionPrevious.Exists)
    }

[pscustomobject]@{
    mode = $mode
    apply = $true
    app_pool = $AppPoolName
    result_code = $resultCode
    previous_document_root = [string]$transactionPrevious.Value
    verified_document_root = $verifiedTarget
    rollback_verified = [bool]$transactionResult.rollback_verified
    cleanup_pending = [bool]$transactionResult.cleanup_pending
    cleanup_error = [string]$transactionResult.cleanup_error
    state_path = $evidencePath
} | ConvertTo-Json -Compress
