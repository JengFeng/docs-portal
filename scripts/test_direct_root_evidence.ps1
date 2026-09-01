#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'direct_root_evidence.ps1')

function Assert-True { param([bool]$Condition,[string]$Message) if(-not $Condition){throw $Message} }
function Copy-Object {
 param($Value)
 $copy=($Value|ConvertTo-Json -Depth 12 -Compress|ConvertFrom-Json)
 if($null -ne $copy.PSObject.Properties['schema_version']){$copy.schema_version=[int]$copy.schema_version}
 return $copy
}

$expected=@{AppPool='SyntheticPool';Target='C:\synthetic\document-library';Backup='C:\synthetic\backup'}
$valid=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_ALREADY_APPLIED_VERIFIED' -SourceEvidenceSha256 ('a'*64) -ChangedUtc '2026-08-30T05:11:07.0110553Z' -EvidenceMigratedUtc '2026-08-30T06:23:10.3108651Z' -GenerationId '11111111-1111-4111-8111-111111111111'
$r=Test-DirectRootEvidenceRecord -Record $valid -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
Assert-True $r.valid ('Valid schema3 rejected: '+($r.errors -join ','))
Assert-True ([string]$valid.payload_sha256 -ceq 'f5180c70121cacf5e6e63b2087c7d58365c475ebaf36567f86fd5817f511f502') 'Canonical schema3 payload hash changed unexpectedly.'

$negative=@()
$x=Copy-Object $valid;$x.app_pool='Other';$negative+=,$x
$x=Copy-Object $valid;$x.verified_document_root='C:\wrong';$negative+=,$x
$x=Copy-Object $valid;$x.backup_root='C:\wrong';$negative+=,$x
$x=Copy-Object $valid;$x.status='failed';$negative+=,$x
$x=Copy-Object $valid;$x.result_code='WRONG';$negative+=,$x
$x=Copy-Object $valid;$x.result_code='direct_root_already_applied_verified';$x.payload_sha256=Get-DirectRootSha256Hex (Get-DirectRootEvidencePayloadText $x);$negative+=,$x
$x=Copy-Object $valid;$x.previous_existed='true';$negative+=,$x
$x=Copy-Object $valid;$x.previous_document_root='';$negative+=,$x
$x=Copy-Object $valid;$x.previous_existed=$false;$negative+=,$x
$x=Copy-Object $valid;$x.schema_version=2;$negative+=,$x
$x=Copy-Object $valid;$x.generation_id='bad';$negative+=,$x
$x=Copy-Object $valid;$x.payload_sha256=('0'*64);$negative+=,$x
$x=Copy-Object $valid;$x.PSObject.Properties.Remove('app_pool');$negative+=,$x
foreach($case in $negative){$check=Test-DirectRootEvidenceRecord -Record $case -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup;Assert-True (-not $check.valid) 'Negative schema3 case was accepted.'}

$legacy=[pscustomobject][ordered]@{schema_version=1;changed_utc='2026-08-30T05:11:07.0110553Z';app_pool=$expected.AppPool;previous_document_root='C:\synthetic\old';verified_document_root=$expected.Target;backup_root=$expected.Backup}
$converted=ConvertFrom-DirectRootLegacyEvidence -Legacy $legacy -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -SourceEvidenceSha256 ('b'*64)
$convertedCheck=Test-DirectRootEvidenceRecord -Record $converted -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
Assert-True $convertedCheck.valid ('Converted legacy evidence invalid: '+($convertedCheck.errors -join ','))
Assert-True ([bool]$converted.previous_existed) 'Legacy previous existence not preserved.'
Assert-True ([string]$converted.changed_utc -ceq [string]$legacy.changed_utc) 'Migration changed the original transition timestamp.'
Assert-True (-not [string]::IsNullOrWhiteSpace([string]$converted.evidence_migrated_utc)) 'Migration timestamp was not recorded separately.'

$schema2=[pscustomobject][ordered]@{schema_version=2;status='applied';changed_utc=$legacy.changed_utc;evidence_upgraded_utc='2026-08-30T06:23:10.3108651Z';app_pool=$expected.AppPool;previous_existed=$true;previous_document_root=$legacy.previous_document_root;verified_document_root=$expected.Target;backup_root=$expected.Backup;result_code='DIRECT_ROOT_ALREADY_APPLIED_VERIFIED'}
$schema2Check=Test-DirectRootSchema2MigrationIdentity -Record $schema2 -Legacy $legacy -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
Assert-True $schema2Check.valid ('Valid schema2 migration identity rejected: '+($schema2Check.errors -join ','))
$schema2Wrong=Copy-Object $schema2;$schema2Wrong.previous_document_root='C:\synthetic\schema2-lie'
$schema2WrongCheck=Test-DirectRootSchema2MigrationIdentity -Record $schema2Wrong -Legacy $legacy -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
Assert-True (-not $schema2WrongCheck.valid) 'Schema2 was allowed to override immutable schema1 facts.'
foreach($schema2Variant in @('pool','status','result','target','backup','previous_existed','timestamp','upgrade_timestamp')){
 $candidate=Copy-Object $schema2
 switch($schema2Variant){
  'pool'{$candidate.app_pool='Other'}
  'status'{$candidate.status='rolled_back'}
  'result'{$candidate.result_code='WRONG'}
  'target'{$candidate.verified_document_root='C:\wrong'}
  'backup'{$candidate.backup_root='C:\wrong'}
  'previous_existed'{$candidate.previous_existed=$false}
  'timestamp'{$candidate.changed_utc='bad'}
  'upgrade_timestamp'{$candidate.evidence_upgraded_utc='bad'}
 }
 $candidateCheck=Test-DirectRootSchema2MigrationIdentity -Record $candidate -Legacy $legacy -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True (-not $candidateCheck.valid) "Schema2 $schema2Variant identity defect was accepted."
}

$rollbackRecord=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot 'C:\synthetic\old' -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot $expected.Target -Status 'rolled_back' -ResultCode 'DIRECT_ROOT_ROLLBACK_VERIFIED' -SourceEvidenceSha256 $valid.payload_sha256 -ChangedUtc '2026-08-30T05:12:07.0110553Z' -GenerationId '22222222-2222-4222-8222-222222222222'
$rollbackCheck=Test-DirectRootEvidenceRecord -Record $rollbackRecord -ExpectedAppPool $expected.AppPool -ExpectedTarget 'C:\synthetic\old' -ExpectedBackupRoot $expected.Backup
Assert-True $rollbackCheck.valid ('Strict schema3 rollback evidence rejected: '+($rollbackCheck.errors -join ','))
$absentRollback=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot '' -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot $expected.Target -Status 'rolled_back' -ResultCode 'DIRECT_ROOT_ROLLBACK_VERIFIED' -SourceEvidenceSha256 $valid.payload_sha256 -ChangedUtc '2026-08-30T05:13:07.0110553Z' -GenerationId '33333333-3333-4333-8333-333333333333'
$absentRollbackCheck=Test-DirectRootEvidenceRecord -Record $absentRollback -ExpectedAppPool $expected.AppPool -ExpectedTarget '' -ExpectedBackupRoot $expected.Backup
Assert-True $absentRollbackCheck.valid ('Rollback-to-absent schema3 evidence rejected: '+($absentRollbackCheck.errors -join ','))

foreach($variant in @('empty','missing','pool','target','backup','timestamp','hash')){
 $l=Copy-Object $legacy;$hash=('b'*64);$expectedCode=''
 switch($variant){
  'empty'{$l.previous_document_root='';$expectedCode='LEGACY_PREVIOUS_EXISTENCE_AMBIGUOUS'}
  'missing'{$l.PSObject.Properties.Remove('previous_document_root');$expectedCode='LEGACY_PREVIOUS_PROPERTY_MISSING'}
  'pool'{$l.app_pool='Other';$expectedCode='LEGACY_APP_POOL_IDENTITY_MISMATCH'}
  'target'{$l.verified_document_root='C:\wrong';$expectedCode='LEGACY_TARGET_IDENTITY_MISMATCH'}
  'backup'{$l.backup_root='C:\wrong';$expectedCode='LEGACY_BACKUP_IDENTITY_MISMATCH'}
  'timestamp'{$l.changed_utc='bad';$expectedCode='LEGACY_TIMESTAMP_MALFORMED'}
  'hash'{$hash='bad';$expectedCode='LEGACY_SOURCE_HASH_INVALID'}
 }
 $caught='';try{[void](ConvertFrom-DirectRootLegacyEvidence -Legacy $l -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -SourceEvidenceSha256 $hash)}catch{$caught=$_.Exception.Message}
 Assert-True ($caught -like ($expectedCode+'*')) ("Legacy $variant did not fail with $expectedCode`: $caught")
}
$fixtureRoot='C:\TWWATER\maintenance-backups\20260830-144453-durable-evidence-implementation\.test-evidence-'+[Guid]::NewGuid().ToString('N')
[void][IO.Directory]::CreateDirectory($fixtureRoot)
try{
 $primary=Join-Path $fixtureRoot 'state.json'
 $stages=New-Object Collections.Generic.List[string]
 $write1=Write-DirectRootEvidenceDurable -Record $valid -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -FaultInjector { param($stage) $stages.Add([string]$stage) }
 Assert-True ($write1.publication_outcome -ceq 'PUBLISHED_VERIFIED') 'Initial publication did not return an explicit verified outcome.'
 Assert-True ($stages -contains 'after_flush' -and $stages -contains 'before_publish' -and $stages -contains 'after_publish_api_before_outcome' -and $stages -contains 'after_publish') 'Durable publisher did not expose the required deterministic fault boundaries.'
 Assert-True (Test-Path -LiteralPath $primary -PathType Leaf) 'Initial primary was not published.'
 Assert-True ((Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup).valid) 'Initial primary failed readback.'
 $next=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('c'*64)
 $write2=Write-DirectRootEvidenceDurable -Record $next -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True (Test-Path -LiteralPath $write2.recovery_path -PathType Leaf) 'Replacement did not preserve a recovery generation.'
 $resolved=Resolve-DirectRootEvidenceSet -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($resolved.status -eq 'primary_valid') 'Valid primary was not selected.'
 $preparedPath=Join-Path $fixtureRoot 'prepared.json';$preparedFixture=[ordered]@{schema_version=3;status='prepared';prepared_utc='2026-08-30T06:00:00Z';app_pool=$expected.AppPool;previous_existed=$true;previous_document_root='C:\synthetic\old';target_document_root=$expected.Target;backup_root=$expected.Backup};[IO.File]::WriteAllText($preparedPath,($preparedFixture|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 $resolved=Resolve-DirectRootEvidenceSet -PrimaryPath $primary -PreparedPath $preparedPath -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($resolved.status -eq 'primary_valid' -and [bool]$resolved.cleanup_pending) 'Valid primary plus stale prepared state did not surface cleanup_pending.'
 $preparedFixture.target_document_root='C:\synthetic\conflict';[IO.File]::WriteAllText($preparedPath,($preparedFixture|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 $resolved=Resolve-DirectRootEvidenceSet -PrimaryPath $primary -PreparedPath $preparedPath -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($resolved.status -eq 'ambiguous') 'Conflicting prepared state did not block reconciliation.'
 [IO.File]::Delete($preparedPath)
 $tempPath=Join-Path $fixtureRoot 'state.next.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json';[IO.File]::WriteAllText($tempPath,($valid|ConvertTo-Json -Depth 12),(New-Object Text.UTF8Encoding($false)))
 $resolved=Resolve-DirectRootEvidenceSet -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($resolved.status -eq 'ambiguous') 'Unaccepted temp did not block reconciliation.'
 [IO.File]::Delete($tempPath)

 $replacement=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('d'*64) -GenerationId '44444444-4444-4444-8444-444444444444'
 $ambiguousWrite=Write-DirectRootEvidenceDurable -Record $replacement -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -FaultInjector { param($stage,$context) if($stage -eq 'after_publish'){ $other=Copy-Object $replacement;$other.source_evidence_sha256=('e'*64);$other.payload_sha256=Get-DirectRootSha256Hex (Get-DirectRootEvidencePayloadText $other);[IO.File]::WriteAllText($primary,($other|ConvertTo-Json -Depth 12),(New-Object Text.UTF8Encoding($false))) } }
 Assert-True ($ambiguousWrite.publication_outcome -ceq 'PUBLISHED_AMBIGUOUS') 'Post-publication exact-payload mismatch was not reported as ambiguous.'

 [IO.File]::WriteAllText($primary,($valid|ConvertTo-Json -Depth 12),(New-Object Text.UTF8Encoding($false)))
 foreach($faultStage in @('before_temp_create','before_flush','before_publish','replace_api')){
  $candidate=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('f'*64)
  $fault=Write-DirectRootEvidenceDurable -Record $candidate -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -FaultInjector { param($stage) if($stage -eq $faultStage){throw "INJECTED_$faultStage"} }
  Assert-True ($fault.publication_outcome -ceq 'NOT_PUBLISHED') "$faultStage was not classified NOT_PUBLISHED."
  $old=Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
  Assert-True ($old.valid -and [string]$old.record.payload_sha256 -ceq [string]$valid.payload_sha256) "$faultStage damaged the accepted primary."
 }
 $afterFlush=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('1'*64)
 $afterFlushResult=Write-DirectRootEvidenceDurable -Record $afterFlush -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -FaultInjector {param($stage) if($stage -eq 'after_flush'){throw 'INJECTED_AFTER_FLUSH'}}
 Assert-True ($afterFlushResult.publication_outcome -ceq 'NOT_PUBLISHED') 'After-flush fault was not classified NOT_PUBLISHED.'
 $apiBoundary=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('4'*64)
 $apiBoundaryResult=Write-DirectRootEvidenceDurable -Record $apiBoundary -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -FaultInjector {param($stage) if($stage -eq 'after_publish_api_before_outcome'){throw 'INJECTED_REPLACE_RETURN_AMBIGUITY'}}
 Assert-True ($apiBoundaryResult.publication_outcome -ceq 'PUBLISHED_VERIFIED') 'Replace-return ambiguity did not reconcile the exact intended payload.'
 $postPublish=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('2'*64)
 $postPublishResult=Write-DirectRootEvidenceDurable -Record $postPublish -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -FaultInjector {param($stage) if($stage -eq 'after_publish'){throw 'INJECTED_POST_REPLACE_READBACK'}}
 Assert-True ($postPublishResult.publication_outcome -ceq 'PUBLISHED_VERIFIED') 'Exact post-replace reconciliation did not verify the intended payload.'
 $collision=New-DirectRootEvidenceRecord -AppPoolName $expected.AppPool -VerifiedDocumentRoot $expected.Target -BackupRoot $expected.Backup -PreviousExisted $true -PreviousDocumentRoot 'C:\synthetic\old' -Status 'applied' -ResultCode 'DIRECT_ROOT_APPLIED_VERIFIED' -SourceEvidenceSha256 ('3'*64) -GenerationId $write2.generation_id
 $collisionResult=Write-DirectRootEvidenceDurable -Record $collision -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($collisionResult.publication_outcome -ceq 'NOT_PUBLISHED') 'Older recovery collision did not fail before publication.'
 foreach($extraRecovery in @(Get-ChildItem -LiteralPath $fixtureRoot -Filter 'state.previous.*.json' -File)){if($extraRecovery.FullName -cne $write2.recovery_path){[IO.File]::Delete($extraRecovery.FullName)}}
 [IO.File]::WriteAllText($primary,'{broken',(New-Object Text.UTF8Encoding($false)))
 $resolved=Resolve-DirectRootEvidenceSet -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($resolved.status -eq 'recovery_required') 'Corrupt primary did not require recovery.'
 Copy-Item -LiteralPath $write2.recovery_path -Destination (Join-Path $fixtureRoot 'state.previous.conflict.json')
 $resolved=Resolve-DirectRootEvidenceSet -PrimaryPath $primary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($resolved.status -eq 'ambiguous') 'Multiple valid recoveries did not fail closed.'
}
finally{if(Test-Path -LiteralPath $fixtureRoot){[IO.Directory]::Delete($fixtureRoot,$true)}}
Assert-True (-not (Test-Path -LiteralPath $fixtureRoot)) 'Evidence test fixture leaked.'

$evidenceSource=Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'direct_root_evidence.ps1')
Assert-True ($evidenceSource.Contains('EVIDENCE_REPARSE_LEAF_REJECTED')) 'Leaf-level primary/temp/recovery reparse rejection is missing.'
Assert-True ($evidenceSource.Contains('Split-Path -Parent $candidateFull')) 'Recovery containment is not revalidated.'
Assert-True ($evidenceSource.Contains('Join-Path $dir ("state.next.$generation.json")')) 'Temp publication is not structurally constrained to the primary directory/volume.'

$relativeCaught='';try{[void](Assert-DirectRootEvidencePath -Path 'relative-evidence.json')}catch{$relativeCaught=$_.Exception.Message}
Assert-True ($relativeCaught -like 'EVIDENCE_ABSOLUTE_PATH_REQUIRED*') "Relative evidence path was not rejected before canonicalization: $relativeCaught"

$migrationFixture='C:\TWWATER\maintenance-backups\20260830-144453-durable-evidence-implementation\.test-schema2-migration-'+[Guid]::NewGuid().ToString('N')
[void][IO.Directory]::CreateDirectory($migrationFixture)
try{
 $migrationPrimary=Join-Path $migrationFixture 'state.json'
 $migrationArchive=Join-Path $migrationFixture 'direct-root-switch-state-schema2-pre-migration.json'
 $schema2Bytes=$schema2|ConvertTo-Json -Depth 12
 [IO.File]::WriteAllText($migrationPrimary,$schema2Bytes,(New-Object Text.UTF8Encoding($false)))
 $schema3Migration=ConvertFrom-DirectRootLegacyEvidence -Legacy $legacy -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup -SourceEvidenceSha256 ('b'*64) -EvidenceMigratedUtc '2026-08-30T06:23:10.3108651Z'
 $migrationWrite=Write-DirectRootEvidenceDurable -Record $schema3Migration -PrimaryPath $migrationPrimary -RecoveryPath $migrationArchive -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($migrationWrite.publication_outcome -ceq 'PUBLISHED_VERIFIED') 'Schema2-to-schema3 migration publication failed.'
 Assert-True (Test-Path -LiteralPath $migrationArchive -PathType Leaf) 'Schema2 predecessor archive was not retained.'
 $archivedSchema2=Get-Content -Raw -LiteralPath $migrationArchive|ConvertFrom-Json
 Assert-True ([int]$archivedSchema2.schema_version -eq 2) 'Schema2 predecessor archive changed schema.'
 $migrationResolved=Resolve-DirectRootEvidenceSet -PrimaryPath $migrationPrimary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 Assert-True ($migrationResolved.status -ceq 'primary_valid') "Schema2 predecessor archive poisoned schema3 reconciliation: $($migrationResolved.status)/$($migrationResolved.reason)"
 $beforeHash=(Get-FileHash -Algorithm SHA256 -LiteralPath $migrationPrimary).Hash
 $migrationResolvedAgain=Resolve-DirectRootEvidenceSet -PrimaryPath $migrationPrimary -ExpectedAppPool $expected.AppPool -ExpectedTarget $expected.Target -ExpectedBackupRoot $expected.Backup
 $afterHash=(Get-FileHash -Algorithm SHA256 -LiteralPath $migrationPrimary).Hash
 Assert-True ($migrationResolvedAgain.status -ceq 'primary_valid' -and $beforeHash -ceq $afterHash) 'Repeated migration reconciliation was not idempotent.'
}
finally{if(Test-Path -LiteralPath $migrationFixture){[IO.Directory]::Delete($migrationFixture,$true)}}
Assert-True (-not(Test-Path -LiteralPath $migrationFixture)) 'Schema2 migration fixture leaked.'

Write-Output '[OK] Strict schema-3, legacy conversion, durable publication, and reconciliation contracts passed.'
