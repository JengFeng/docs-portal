#requires -Version 5.1
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'direct_root_evidence.ps1')

function Complete-DirectRootSwitchEvidence {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][scriptblock] $WriteSuccessEvidence,
        [Parameter(Mandatory)][scriptblock] $RemovePreparedEvidence
    )
    $publication = & $WriteSuccessEvidence
    if ($null -eq $publication -or [string]$publication.publication_outcome -cne 'PUBLISHED_VERIFIED') {
        throw 'DIRECT_ROOT_SUCCESS_EVIDENCE_NOT_VERIFIED'
    }
    $cleanupPending = $false
    $cleanupError = ''
    try { & $RemovePreparedEvidence }
    catch { $cleanupPending = $true; $cleanupError = $_.Exception.Message }
    return [pscustomobject]@{cleanup_pending=$cleanupPending;cleanup_error=$cleanupError;prepared_cleanup_pending=$cleanupPending;prepared_cleanup_error=$cleanupError;publication_outcome='PUBLISHED_VERIFIED'}
}

function Invoke-DirectRootEvidenceOnlyOperation {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][scriptblock] $ReadCurrent,
        [Parameter(Mandatory)][scriptblock] $VerifyCurrent,
        [Parameter(Mandatory)][scriptblock] $PublishEvidence
    )
    $state = & $ReadCurrent
    if (-not [bool](& $VerifyCurrent $state)) { throw 'DIRECT_ROOT_EVIDENCE_ONLY_LIVE_VERIFY_FAILED' }
    $result = & $PublishEvidence $state
    if ($null -eq $result -or [string]$result.publication_outcome -cne 'PUBLISHED_VERIFIED') { throw 'DIRECT_ROOT_EVIDENCE_ONLY_PUBLICATION_FAILED' }
    return $result
}

function Invoke-DirectRootSwitchTransaction {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][scriptblock] $CommitTarget,
        [Parameter(Mandatory)][scriptblock] $VerifyTarget,
        [Parameter(Mandatory)][scriptblock] $RecycleTarget,
        [Parameter(Mandatory)][scriptblock] $WaitForTargetReady,
        [Parameter(Mandatory)][scriptblock] $WriteEvidence,
        [Parameter(Mandatory)][scriptblock] $RemovePreparedEvidence,
        [Parameter(Mandatory)][scriptblock] $RestorePrevious,
        [Parameter(Mandatory)][scriptblock] $WaitForRestoreReady,
        [Parameter(Mandatory)][scriptblock] $VerifyRestore
    )

    $publicationOutcome = 'NOT_PUBLISHED'
    try {
        & $CommitTarget
        if (-not [bool](& $VerifyTarget)) { throw 'DIRECT_ROOT_TARGET_READBACK_FAILED' }
        & $RecycleTarget
        if (-not [bool](& $WaitForTargetReady)) { throw 'DIRECT_ROOT_TARGET_NOT_READY' }
        $publicationResult = & $WriteEvidence
        if ($null -eq $publicationResult) { throw 'DIRECT_ROOT_EVIDENCE_OUTCOME_MISSING' }
        $publicationOutcome = [string]$publicationResult.publication_outcome
        if ($publicationOutcome -ceq 'PUBLISHED_AMBIGUOUS') { throw 'DIRECT_ROOT_EVIDENCE_PUBLICATION_AMBIGUOUS_NO_ROLLBACK' }
        if ($publicationOutcome -cne 'PUBLISHED_VERIFIED') { throw ('DIRECT_ROOT_EVIDENCE_NOT_PUBLISHED:' + [string]$publicationResult.error) }
    }
    catch {
        $originalFailure = $_.Exception.Message
        if ($publicationOutcome -ceq 'PUBLISHED_AMBIGUOUS' -or $originalFailure -like 'DIRECT_ROOT_EVIDENCE_PUBLICATION_AMBIGUOUS_NO_ROLLBACK*') {
            throw "DIRECT_ROOT_EVIDENCE_PUBLICATION_AMBIGUOUS_NO_ROLLBACK:$originalFailure"
        }
        try {
            & $RestorePrevious
            if (-not [bool](& $WaitForRestoreReady)) { throw 'DIRECT_ROOT_RESTORE_NOT_READY' }
            if (-not [bool](& $VerifyRestore)) { throw 'DIRECT_ROOT_RESTORE_READBACK_FAILED' }
        }
        catch {
            $rollbackFailure = $_.Exception.Message
            throw "DIRECT_ROOT_ROLLBACK_FAILED:$originalFailure`:$rollbackFailure"
        }
        throw "DIRECT_ROOT_POST_COMMIT_FAILED_ROLLED_BACK:$originalFailure"
    }

    $cleanupPending = $false
    $cleanupError = ''
    try { & $RemovePreparedEvidence }
    catch { $cleanupPending = $true; $cleanupError = $_.Exception.Message }

    return [pscustomobject]@{
        committed = $true
        rollback_verified = $false
        publication_outcome = $publicationOutcome
        cleanup_pending = $cleanupPending
        cleanup_error = $cleanupError
    }
}
