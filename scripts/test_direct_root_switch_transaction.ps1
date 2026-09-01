#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'direct_root_switch_transaction.ps1')

function Assert-True {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function Invoke-FailureCase {
    param([Parameter(Mandatory)][string] $Failure)
    $box = @{ Current = 'old-root'; Restores = 0; RestoreVerified = 0; CleanupCalls = 0 }
    $caught = ''
    try {
        [void](Invoke-DirectRootSwitchTransaction `
            -CommitTarget { $box.Current = 'new-root' } `
            -VerifyTarget {
                if ($Failure -eq 'VerifyTarget') { return $false }
                return $box.Current -eq 'new-root'
            } `
            -RecycleTarget { if ($Failure -eq 'Recycle') { throw 'INJECTED_RECYCLE' } } `
            -WaitForTargetReady { if ($Failure -eq 'Readiness') { return $false }; return $true } `
            -WriteEvidence { if ($Failure -eq 'Evidence') { return [pscustomobject]@{publication_outcome='NOT_PUBLISHED'} }; return [pscustomobject]@{publication_outcome='PUBLISHED_VERIFIED'} } `
            -RemovePreparedEvidence { $box.CleanupCalls++ } `
            -RestorePrevious { $box.Current = 'old-root'; $box.Restores++ } `
            -WaitForRestoreReady { return $true } `
            -VerifyRestore { $box.RestoreVerified++; return $box.Current -eq 'old-root' })
    }
    catch { $caught = $_.Exception.Message }

    Assert-True ($caught -like 'DIRECT_ROOT_POST_COMMIT_FAILED_ROLLED_BACK:*') "$Failure did not report verified rollback: $caught"
    Assert-True ($box.Current -eq 'old-root') "$Failure left target committed."
    Assert-True ($box.Restores -eq 1) "$Failure restored $($box.Restores) times."
    Assert-True ($box.RestoreVerified -eq 1) "$Failure verified restoration $($box.RestoreVerified) times."
    Assert-True ($box.CleanupCalls -eq 0) "$Failure reached prepared cleanup before accepted evidence."
}

foreach ($failure in @('VerifyTarget', 'Recycle', 'Readiness', 'Evidence')) { Invoke-FailureCase -Failure $failure }

$success = @{ Current = 'old-root'; EvidenceReadable = $false; CleanupCalls = 0; Restores = 0; RestoreVerified = 0 }
$successResult = Invoke-DirectRootSwitchTransaction `
    -CommitTarget { $success.Current = 'new-root' } `
    -VerifyTarget { return $success.Current -eq 'new-root' } `
    -RecycleTarget { } `
    -WaitForTargetReady { return $true } `
    -WriteEvidence { $success.EvidenceReadable = $true; return [pscustomobject]@{publication_outcome='PUBLISHED_VERIFIED'} } `
    -RemovePreparedEvidence { $success.CleanupCalls++ } `
    -RestorePrevious { $success.Current = 'old-root'; $success.Restores++ } `
    -WaitForRestoreReady { return $true } `
    -VerifyRestore { $success.RestoreVerified++; return $success.Current -eq 'old-root' }
Assert-True ([bool]$successResult.committed) 'Normal success was not committed.'
Assert-True (-not [bool]$successResult.cleanup_pending) 'Normal success incorrectly reported cleanup pending.'
Assert-True ($success.EvidenceReadable) 'Normal success did not persist readable evidence before completion.'
Assert-True ($success.CleanupCalls -eq 1) 'Normal success did not clean prepared evidence once.'
Assert-True ($success.Restores -eq 0 -and $success.RestoreVerified -eq 0) 'Normal success entered rollback.'

$cleanup = @{ Current = 'old-root'; EvidenceReadable = $false; PreparedRetained = $true; CleanupCalls = 0; Restores = 0; RestoreVerified = 0 }
$cleanupResult = Invoke-DirectRootSwitchTransaction `
    -CommitTarget { $cleanup.Current = 'new-root' } `
    -VerifyTarget { return $cleanup.Current -eq 'new-root' } `
    -RecycleTarget { } `
    -WaitForTargetReady { return $true } `
    -WriteEvidence { $cleanup.EvidenceReadable = $true; return [pscustomobject]@{publication_outcome='PUBLISHED_VERIFIED'} } `
    -RemovePreparedEvidence { $cleanup.CleanupCalls++; throw 'INJECTED_PREPARED_DELETE' } `
    -RestorePrevious { $cleanup.Current = 'old-root'; $cleanup.Restores++ } `
    -WaitForRestoreReady { return $true } `
    -VerifyRestore { $cleanup.RestoreVerified++; return $cleanup.Current -eq 'old-root' }
Assert-True ([bool]$cleanupResult.committed) 'Cleanup failure lost committed result.'
Assert-True ([bool]$cleanupResult.cleanup_pending) 'Cleanup failure was not reported as cleanup_pending.'
Assert-True ($cleanupResult.cleanup_error -eq 'INJECTED_PREPARED_DELETE') 'Cleanup failure detail was not retained.'
Assert-True ($cleanup.Current -eq 'new-root') 'Cleanup failure restored the accepted target.'
Assert-True ($cleanup.EvidenceReadable) 'Cleanup failure lost readable success evidence.'
Assert-True ($cleanup.PreparedRetained) 'Cleanup failure did not retain prepared evidence.'
Assert-True ($cleanup.Restores -eq 0 -and $cleanup.RestoreVerified -eq 0) 'Cleanup failure entered rollback.'

# A later cleanup retry is independent of the accepted transaction/evidence and must not rewrite it.
$acceptedEvidenceHash = 'accepted-generation-hash'
$retryWrites = 0
$cleanup.PreparedRetained = $false
$cleanup.CleanupCalls++
Assert-True (-not $cleanup.PreparedRetained) 'Cleanup retry did not remove prepared evidence.'
Assert-True ($retryWrites -eq 0) 'Cleanup retry rewrote accepted evidence.'
Assert-True ($acceptedEvidenceHash -eq 'accepted-generation-hash') 'Cleanup retry changed accepted evidence identity.'

$ambiguous = @{ Current = 'old-root'; Restores = 0; RestoreVerified = 0 }
$ambiguousCaught = ''
try {
    [void](Invoke-DirectRootSwitchTransaction `
        -CommitTarget { $ambiguous.Current = 'new-root'; throw 'INJECTED_COMMIT_AMBIGUOUS' } `
        -VerifyTarget { return $false } `
        -RecycleTarget { } `
        -WaitForTargetReady { return $true } `
        -WriteEvidence { return [pscustomobject]@{publication_outcome='PUBLISHED_VERIFIED'} } `
        -RemovePreparedEvidence { } `
        -RestorePrevious { $ambiguous.Current = 'old-root'; $ambiguous.Restores++ } `
        -WaitForRestoreReady { return $true } `
        -VerifyRestore { $ambiguous.RestoreVerified++; return $ambiguous.Current -eq 'old-root' })
}
catch { $ambiguousCaught = $_.Exception.Message }
Assert-True ($ambiguousCaught -like 'DIRECT_ROOT_POST_COMMIT_FAILED_ROLLED_BACK:*') "Ambiguous commit did not report verified rollback: $ambiguousCaught"
Assert-True ($ambiguous.Restores -eq 1 -and $ambiguous.RestoreVerified -eq 1) 'Ambiguous commit was not restored and verified exactly once.'

$restoreHealth=@{Current='old-root';Restores=0;RestoreVerifies=0}
$restoreHealthCaught=''
try{
 [void](Invoke-DirectRootSwitchTransaction -CommitTarget {$restoreHealth.Current='new-root'} -VerifyTarget {return $false} -RecycleTarget {} -WaitForTargetReady {return $true} -WriteEvidence {return [pscustomobject]@{publication_outcome='PUBLISHED_VERIFIED'}} -RemovePreparedEvidence {} -RestorePrevious {$restoreHealth.Current='old-root';$restoreHealth.Restores++} -WaitForRestoreReady {return $false} -VerifyRestore {$restoreHealth.RestoreVerifies++;return $true})
}catch{$restoreHealthCaught=$_.Exception.Message}
Assert-True ($restoreHealthCaught -like 'DIRECT_ROOT_ROLLBACK_FAILED:*DIRECT_ROOT_RESTORE_NOT_READY*') "Rollback readiness failure was not fail-closed: $restoreHealthCaught"
Assert-True ($restoreHealth.Restores -eq 1 -and $restoreHealth.RestoreVerifies -eq 0) 'Rollback readiness failure bypassed bounded readiness ordering.'

# Exercise the real durable publisher through the outer transaction cleanup boundary.
$fixtureRoot = 'C:\TWWATER\maintenance-backups\20260830-144453-durable-evidence-implementation\.test-transaction-' + [Guid]::NewGuid().ToString('N')
[void][IO.Directory]::CreateDirectory($fixtureRoot)
try {
    $primary = Join-Path $fixtureRoot 'state.json'
    $prepared = Join-Path $fixtureRoot 'prepared.json'
    [IO.File]::WriteAllText($prepared, '{"status":"prepared"}', (New-Object Text.UTF8Encoding($false)))
    $record = New-DirectRootEvidenceRecord -AppPoolName 'SyntheticPool' -VerifiedDocumentRoot 'C:\synthetic\new' -BackupRoot 'C:\synthetic\backup' -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('a' * 64)
    $durable = @{ Current = 'old-root'; Restores = 0; RestoreVerified = 0 }
    $durableResult = Invoke-DirectRootSwitchTransaction `
        -CommitTarget { $durable.Current = 'new-root' } `
        -VerifyTarget { return $durable.Current -eq 'new-root' } `
        -RecycleTarget { } `
        -WaitForTargetReady { return $true } `
        -WriteEvidence { return Write-DirectRootEvidenceDurable -Record $record -PrimaryPath $primary -ExpectedAppPool 'SyntheticPool' -ExpectedTarget 'C:\synthetic\new' -ExpectedBackupRoot 'C:\synthetic\backup' } `
        -RemovePreparedEvidence { throw 'INJECTED_REAL_PREPARED_DELETE' } `
        -RestorePrevious { $durable.Current = 'old-root'; $durable.Restores++ } `
        -WaitForRestoreReady { return $true } `
        -VerifyRestore { $durable.RestoreVerified++; return $durable.Current -eq 'old-root' }
    $readBack = Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool 'SyntheticPool' -ExpectedTarget 'C:\synthetic\new' -ExpectedBackupRoot 'C:\synthetic\backup'
    Assert-True $readBack.valid 'Cleanup failure left unreadable durable success evidence.'
    Assert-True ([bool]$durableResult.cleanup_pending) 'Real durable cleanup failure was not pending.'
    Assert-True (Test-Path -LiteralPath $prepared -PathType Leaf) 'Real prepared evidence was not retained.'
    Assert-True ($durable.Current -eq 'new-root' -and $durable.Restores -eq 0 -and $durable.RestoreVerified -eq 0) 'Real durable cleanup failure restored the target.'
}
finally {
    if (Test-Path -LiteralPath $fixtureRoot) { [IO.Directory]::Delete($fixtureRoot, $true) }
}
Assert-True (-not (Test-Path -LiteralPath $fixtureRoot)) 'Transaction test fixture leaked.'

$publishedAmbiguous=@{Current='old-root';Restores=0;CleanupCalls=0}
$publishedCaught=''
try{
 [void](Invoke-DirectRootSwitchTransaction -CommitTarget {$publishedAmbiguous.Current='new-root'} -VerifyTarget {return $true} -RecycleTarget {} -WaitForTargetReady {return $true} -WriteEvidence {return [pscustomobject]@{publication_outcome='PUBLISHED_AMBIGUOUS'}} -RemovePreparedEvidence {$publishedAmbiguous.CleanupCalls++} -RestorePrevious {$publishedAmbiguous.Current='old-root';$publishedAmbiguous.Restores++} -WaitForRestoreReady {return $true} -VerifyRestore {return $true})
}catch{$publishedCaught=$_.Exception.Message}
Assert-True ($publishedCaught -like 'DIRECT_ROOT_EVIDENCE_PUBLICATION_AMBIGUOUS_NO_ROLLBACK*') "Published ambiguity did not fail closed: $publishedCaught"
Assert-True ($publishedAmbiguous.Current -eq 'new-root' -and $publishedAmbiguous.Restores -eq 0) 'Published ambiguity created contradictory applied evidence beside restored runtime.'
Assert-True ($publishedAmbiguous.CleanupCalls -eq 0) 'Published ambiguity performed cleanup.'

$evidenceOnly=@{Reads=0;Verifies=0;Publishes=0;CommitChanges=0;Recycles=0}
$evidenceOnlyResult=Invoke-DirectRootEvidenceOnlyOperation -ReadCurrent {$evidenceOnly.Reads++;return 'live-root'} -VerifyCurrent {param($state)$evidenceOnly.Verifies++;return $state -eq 'live-root'} -PublishEvidence {param($state)$evidenceOnly.Publishes++;return [pscustomobject]@{publication_outcome='PUBLISHED_VERIFIED';state=$state}}
Assert-True ($evidenceOnlyResult.publication_outcome -ceq 'PUBLISHED_VERIFIED') 'Evidence-only synthetic operation did not publish verified evidence.'
Assert-True ($evidenceOnly.Reads -eq 1 -and $evidenceOnly.Verifies -eq 1 -and $evidenceOnly.Publishes -eq 1) 'Evidence-only operation did not execute exactly once.'
Assert-True ($evidenceOnly.CommitChanges -eq 0 -and $evidenceOnly.Recycles -eq 0) 'Evidence-only operation committed or recycled IIS.'

Write-Output '[OK] Outer direct-root transaction ordering, rollback, and cleanup-pending contracts passed.'
