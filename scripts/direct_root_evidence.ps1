#requires -Version 5.1
Set-StrictMode -Version Latest

$script:DirectRootEvidenceFields=@('schema_version','generation_id','status','result_code','changed_utc','evidence_migrated_utc','app_pool','previous_existed','previous_document_root','verified_document_root','backup_root','source_evidence_sha256','payload_sha256')
$script:DirectRootPayloadFields=@('schema_version','generation_id','status','result_code','changed_utc','evidence_migrated_utc','app_pool','previous_existed','previous_document_root','verified_document_root','backup_root','source_evidence_sha256')

function Get-DirectRootSha256Hex {
 param([Parameter(Mandatory)][string]$Text)
 $sha=[Security.Cryptography.SHA256]::Create();try{$bytes=[Text.Encoding]::UTF8.GetBytes($Text);return (($sha.ComputeHash($bytes)|ForEach-Object{$_.ToString('x2')}) -join '')}finally{$sha.Dispose()}
}
function Get-DirectRootEvidencePayloadText {
 param([Parameter(Mandatory)]$Record)
 $parts=foreach($name in $script:DirectRootPayloadFields){$p=$Record.PSObject.Properties[$name];if($null -eq $p){throw "EVIDENCE_PAYLOAD_PROPERTY_MISSING:$name"};$v=if($p.Value -is [bool]){if($p.Value){'true'}else{'false'}}elseif($null -eq $p.Value){'<null>'}else{[string]$p.Value};'{0}:{1}:{2}' -f $name,$v.Length,$v};return ($parts -join '|')
}
function Test-DirectRootTimestamp {
 param([AllowEmptyString()][string]$Value,[bool]$AllowEmpty=$false)
 if($AllowEmpty -and [string]::IsNullOrEmpty($Value)){return $true}
 $dto=[DateTimeOffset]::MinValue
 return ($Value -match '^\d{4}-\d{2}-\d{2}T.*Z$' -and [DateTimeOffset]::TryParse($Value,[Globalization.CultureInfo]::InvariantCulture,[Globalization.DateTimeStyles]::AssumeUniversal,[ref]$dto))
}
function Test-DirectRootIdentity {
 param([AllowEmptyString()][string]$Actual,[AllowEmptyString()][string]$Expected)
 return [string]::Equals($Actual.TrimEnd('\'),$Expected.TrimEnd('\'),[StringComparison]::OrdinalIgnoreCase)
}
function New-DirectRootEvidenceRecord {
 [CmdletBinding()]param([Parameter(Mandatory)][string]$AppPoolName,[Parameter(Mandatory)][AllowEmptyString()][string]$VerifiedDocumentRoot,[Parameter(Mandatory)][string]$BackupRoot,[Parameter(Mandatory)][bool]$PreviousExisted,[AllowEmptyString()][string]$PreviousDocumentRoot,[Parameter(Mandatory)][string]$Status,[Parameter(Mandatory)][string]$ResultCode,[Parameter(Mandatory)][string]$SourceEvidenceSha256,[string]$ChangedUtc=[DateTime]::UtcNow.ToString('o'),[AllowEmptyString()][string]$EvidenceMigratedUtc='',[string]$GenerationId=[Guid]::NewGuid().ToString())
 $r=[pscustomobject][ordered]@{schema_version=3;generation_id=$GenerationId;status=$Status;result_code=$ResultCode;changed_utc=$ChangedUtc;evidence_migrated_utc=$EvidenceMigratedUtc;app_pool=$AppPoolName;previous_existed=$PreviousExisted;previous_document_root=$PreviousDocumentRoot;verified_document_root=$VerifiedDocumentRoot;backup_root=$BackupRoot;source_evidence_sha256=$SourceEvidenceSha256;payload_sha256=''}
 $r.payload_sha256=Get-DirectRootSha256Hex (Get-DirectRootEvidencePayloadText $r);return $r
}
function Test-DirectRootEvidenceRecord {
 [CmdletBinding()]param([Parameter(Mandatory)]$Record,[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][AllowEmptyString()][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot)
 $errors=New-Object Collections.Generic.List[string];$names=@($Record.PSObject.Properties.Name)
 foreach($n in $script:DirectRootEvidenceFields){if($names -cnotcontains $n){$errors.Add("missing_property:$n")}}
 foreach($n in $names){if($script:DirectRootEvidenceFields -cnotcontains $n){$errors.Add("unexpected_property:$n")}}
 if($errors.Count -eq 0){
  if(($Record.schema_version -isnot [int]) -or [int]$Record.schema_version -ne 3){$errors.Add('schema_version_mismatch')}
  $g=[Guid]::Empty;if(($Record.generation_id -isnot [string]) -or -not [Guid]::TryParse([string]$Record.generation_id,[ref]$g)){$errors.Add('generation_id_invalid')}
  $statusResultValid=(($Record.status -is [string]) -and ($Record.result_code -is [string]) -and ((([string]$Record.status -ceq 'applied') -and ([string]$Record.result_code -cin @('DIRECT_ROOT_APPLIED_VERIFIED','DIRECT_ROOT_ALREADY_APPLIED_VERIFIED')))-or (([string]$Record.status -ceq 'rolled_back') -and ([string]$Record.result_code -ceq 'DIRECT_ROOT_ROLLBACK_VERIFIED'))))
  if(-not $statusResultValid){$errors.Add('status_result_mismatch')}
  foreach($pair in @(@('app_pool',$ExpectedAppPool),@('verified_document_root',$ExpectedTarget),@('backup_root',$ExpectedBackupRoot))){$n=$pair[0];if(($Record.$n -isnot [string]) -or -not (Test-DirectRootIdentity ([string]$Record.$n) ([string]$pair[1]))){$errors.Add("identity_mismatch:$n")}}
  if(($Record.changed_utc -isnot [string]) -or -not(Test-DirectRootTimestamp ([string]$Record.changed_utc))){$errors.Add('changed_utc_invalid')}
  if(($Record.evidence_migrated_utc -isnot [string]) -or -not(Test-DirectRootTimestamp ([string]$Record.evidence_migrated_utc) $true)){$errors.Add('evidence_migrated_utc_invalid')}
  if(([string]$Record.result_code -ceq 'DIRECT_ROOT_ALREADY_APPLIED_VERIFIED') -and [string]::IsNullOrEmpty([string]$Record.evidence_migrated_utc)){$errors.Add('evidence_migrated_utc_required')}
  if($Record.previous_existed -isnot [bool]){$errors.Add('previous_existed_type_invalid')}elseif([bool]$Record.previous_existed){if(($Record.previous_document_root -isnot [string]) -or [string]::IsNullOrWhiteSpace([string]$Record.previous_document_root)){$errors.Add('previous_document_root_required')}}elseif(($Record.previous_document_root -isnot [string]) -or -not [string]::IsNullOrEmpty([string]$Record.previous_document_root)){$errors.Add('previous_document_root_must_be_empty')}
  foreach($h in @('source_evidence_sha256','payload_sha256')){if(($Record.$h -isnot [string]) -or [string]$Record.$h -cnotmatch '^[0-9a-f]{64}$'){$errors.Add("${h}_invalid")}}
  if($errors.Count -eq 0){$actual=Get-DirectRootSha256Hex (Get-DirectRootEvidencePayloadText $Record);if($actual -cne [string]$Record.payload_sha256){$errors.Add('payload_sha256_mismatch')}}
 }
 return [pscustomobject]@{valid=($errors.Count -eq 0);errors=@($errors)}
}
function Test-DirectRootSchema2MigrationIdentity {
 [CmdletBinding()]param([Parameter(Mandatory)]$Record,[Parameter(Mandatory)]$Legacy,[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot)
 $errors=New-Object Collections.Generic.List[string]
 $required=@('schema_version','status','changed_utc','evidence_upgraded_utc','app_pool','previous_existed','previous_document_root','verified_document_root','backup_root','result_code')
 foreach($n in $required){if($null -eq $Record.PSObject.Properties[$n]){$errors.Add("missing_property:$n")}}
 if($errors.Count -eq 0){
  if(($Record.schema_version -isnot [int]) -or [int]$Record.schema_version -ne 2){$errors.Add('schema_version_mismatch')}
  if(($Record.status -isnot [string])-or [string]$Record.status -cne 'applied'){$errors.Add('status_mismatch')}
  if(($Record.result_code -isnot [string])-or [string]$Record.result_code -cne 'DIRECT_ROOT_ALREADY_APPLIED_VERIFIED'){$errors.Add('result_code_mismatch')}
  if(($Record.app_pool -isnot [string])-or [string]$Record.app_pool -cne $ExpectedAppPool){$errors.Add('app_pool_mismatch')}
  if(-not(Test-DirectRootIdentity ([string]$Record.verified_document_root) $ExpectedTarget)){$errors.Add('target_mismatch')}
  if(-not(Test-DirectRootIdentity ([string]$Record.backup_root) $ExpectedBackupRoot)){$errors.Add('backup_mismatch')}
  if(($Record.previous_existed -isnot [bool])-or -not [bool]$Record.previous_existed){$errors.Add('previous_existed_mismatch')}
  if([string]$Record.previous_document_root -cne [string]$Legacy.previous_document_root){$errors.Add('previous_document_root_mismatch')}
  if([string]$Record.changed_utc -cne [string]$Legacy.changed_utc){$errors.Add('changed_utc_mismatch')}
  if(-not(Test-DirectRootTimestamp ([string]$Record.changed_utc))){$errors.Add('changed_utc_invalid')}
  if(-not(Test-DirectRootTimestamp ([string]$Record.evidence_upgraded_utc))){$errors.Add('evidence_upgraded_utc_invalid')}
 }
 return [pscustomobject]@{valid=($errors.Count -eq 0);errors=@($errors)}
}
function ConvertFrom-DirectRootLegacyEvidence {
 [CmdletBinding()]param([Parameter(Mandatory)]$Legacy,[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][AllowEmptyString()][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot,[Parameter(Mandatory)][string]$SourceEvidenceSha256,[string]$EvidenceMigratedUtc=[DateTime]::UtcNow.ToString('o'))
 foreach($n in @('schema_version','changed_utc','app_pool','previous_document_root','verified_document_root','backup_root')){if($null -eq $Legacy.PSObject.Properties[$n]){if($n -eq 'previous_document_root'){throw 'LEGACY_PREVIOUS_PROPERTY_MISSING'};throw "LEGACY_REQUIRED_PROPERTY_MISSING:$n"}}
 if([int]$Legacy.schema_version -ne 1){throw 'LEGACY_SCHEMA_UNSUPPORTED'}
 if($SourceEvidenceSha256 -cnotmatch '^[0-9a-f]{64}$'){throw 'LEGACY_SOURCE_HASH_INVALID'}
 if([string]$Legacy.app_pool -cne $ExpectedAppPool){throw 'LEGACY_APP_POOL_IDENTITY_MISMATCH'}
 if(-not(Test-DirectRootIdentity ([string]$Legacy.verified_document_root) $ExpectedTarget)){throw 'LEGACY_TARGET_IDENTITY_MISMATCH'}
 if(-not(Test-DirectRootIdentity ([string]$Legacy.backup_root) $ExpectedBackupRoot)){throw 'LEGACY_BACKUP_IDENTITY_MISMATCH'}
 if(-not(Test-DirectRootTimestamp ([string]$Legacy.changed_utc))){throw 'LEGACY_TIMESTAMP_MALFORMED'}
 if(-not(Test-DirectRootTimestamp $EvidenceMigratedUtc)){throw 'LEGACY_MIGRATION_TIMESTAMP_MALFORMED'}
 $previous=[string]$Legacy.previous_document_root;if([string]::IsNullOrWhiteSpace($previous)){throw 'LEGACY_PREVIOUS_EXISTENCE_AMBIGUOUS'}
 return New-DirectRootEvidenceRecord -AppPoolName $ExpectedAppPool -VerifiedDocumentRoot $ExpectedTarget -BackupRoot $ExpectedBackupRoot -PreviousExisted $true -PreviousDocumentRoot $previous -Status 'applied' -ResultCode 'DIRECT_ROOT_ALREADY_APPLIED_VERIFIED' -SourceEvidenceSha256 $SourceEvidenceSha256 -ChangedUtc ([string]$Legacy.changed_utc) -EvidenceMigratedUtc $EvidenceMigratedUtc
}
function Assert-DirectRootEvidencePath {
 param([Parameter(Mandatory)][string]$Path)
 if(-not [IO.Path]::IsPathRooted($Path)){throw 'EVIDENCE_ABSOLUTE_PATH_REQUIRED'}
 $full=[IO.Path]::GetFullPath($Path)
 $parent=Split-Path -Parent $full;if(-not(Test-Path -LiteralPath $parent -PathType Container)){throw 'EVIDENCE_PARENT_MISSING'}
 $root=[IO.Path]::GetPathRoot($parent);$current=$root.TrimEnd('\')
 foreach($segment in @($parent.Substring($root.Length)-split '\\'|Where-Object{$_ -ne ''})){$current=Join-Path $current $segment;$item=Get-Item -LiteralPath $current -Force -ErrorAction Stop;if([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'EVIDENCE_REPARSE_COMPONENT_REJECTED'}}
 if(Test-Path -LiteralPath $full){$leaf=Get-Item -LiteralPath $full -Force -ErrorAction Stop;if([bool]($leaf.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'EVIDENCE_REPARSE_LEAF_REJECTED'};if($leaf.PSIsContainer){throw 'EVIDENCE_LEAF_FILE_REQUIRED'}}
 return $full
}
function Read-DirectRootEvidenceFile {
 [CmdletBinding()]param([Parameter(Mandatory)][string]$Path,[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][AllowEmptyString()][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot)
 try{$validated=Assert-DirectRootEvidencePath -Path $Path;if(-not(Test-Path -LiteralPath $validated -PathType Leaf)){return [pscustomobject]@{valid=$false;path=$validated;record=$null;errors=@('missing')}};$raw=Get-Content -Raw -LiteralPath $validated;[void](Assert-DirectRootEvidencePath -Path $validated);$record=$raw|ConvertFrom-Json;$check=Test-DirectRootEvidenceRecord -Record $record -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot;return [pscustomobject]@{valid=[bool]$check.valid;path=$validated;record=$record;errors=@($check.errors)}}catch{return [pscustomobject]@{valid=$false;path=$Path;record=$null;errors=@($_.Exception.Message)}}
}
function Test-DirectRootExactReadback {
 param($Read,[string]$GenerationId,[string]$PayloadSha256)
 return ($null -ne $Read -and [bool]$Read.valid -and [string]$Read.record.generation_id -ceq $GenerationId -and [string]$Read.record.payload_sha256 -ceq $PayloadSha256)
}
function Invoke-DirectRootEvidenceFault {
 param([scriptblock]$FaultInjector,[string]$Stage,$Context)
 if($null -ne $FaultInjector){& $FaultInjector $Stage $Context}
}
function Write-DirectRootEvidenceDurable {
 [CmdletBinding()]param([Parameter(Mandatory)]$Record,[Parameter(Mandatory)][string]$PrimaryPath,[AllowEmptyString()][string]$RecoveryPath='',[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][AllowEmptyString()][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot,[scriptblock]$FaultInjector)
 $check=Test-DirectRootEvidenceRecord -Record $Record -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot;if(-not $check.valid){throw ('EVIDENCE_RECORD_INVALID:'+($check.errors -join ','))}
 $primary=Assert-DirectRootEvidencePath -Path $PrimaryPath;$dir=Split-Path -Parent $primary;$generation=[string]$Record.generation_id;$payload=[string]$Record.payload_sha256;$temp=Join-Path $dir ("state.next.$generation.json");$recovery=if([string]::IsNullOrEmpty($RecoveryPath)){Join-Path $dir ("state.previous.$generation.json")}else{Assert-DirectRootEvidencePath -Path $RecoveryPath};if(-not [string]::Equals((Split-Path -Parent $recovery),$dir,[StringComparison]::OrdinalIgnoreCase)){throw 'EVIDENCE_RECOVERY_MUST_SHARE_PRIMARY_DIRECTORY'};$published=$false;$publicationAttempted=$false;$oldExists=$false;$oldRead=$null
 $context=[pscustomobject]@{primary_path=$primary;temp_path=$temp;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload}
 try{
  Invoke-DirectRootEvidenceFault $FaultInjector 'before_temp_create' $context
  [void](Assert-DirectRootEvidencePath -Path $temp);[void](Assert-DirectRootEvidencePath -Path $recovery)
  if(Test-Path -LiteralPath $temp){throw 'EVIDENCE_TEMP_ALREADY_EXISTS'};if(Test-Path -LiteralPath $recovery){throw 'EVIDENCE_RECOVERY_ALREADY_EXISTS'}
  $bytes=[Text.Encoding]::UTF8.GetBytes(($Record|ConvertTo-Json -Depth 12));$stream=New-Object IO.FileStream($temp,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None,4096,[IO.FileOptions]::WriteThrough)
  try{$stream.Write($bytes,0,$bytes.Length);Invoke-DirectRootEvidenceFault $FaultInjector 'before_flush' $context;$stream.Flush($true)}finally{$stream.Dispose()}
  Invoke-DirectRootEvidenceFault $FaultInjector 'after_flush' $context
  $tempRead=Read-DirectRootEvidenceFile -Path $temp -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot
  if(-not(Test-DirectRootExactReadback $tempRead $generation $payload)){throw 'EVIDENCE_TEMP_EXACT_READBACK_FAILED'}
  Invoke-DirectRootEvidenceFault $FaultInjector 'before_publish' $context
  [void](Assert-DirectRootEvidencePath -Path $primary);[void](Assert-DirectRootEvidencePath -Path $temp)
  $oldExists=Test-Path -LiteralPath $primary -PathType Leaf
  if($oldExists){$oldRead=Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot}
  $publicationAttempted=$true
  Invoke-DirectRootEvidenceFault $FaultInjector 'replace_api' $context
  if($oldExists){[IO.File]::Replace($temp,$primary,$recovery,$true)}else{$recovery='';$context.recovery_path='';[IO.File]::Move($temp,$primary)}
  Invoke-DirectRootEvidenceFault $FaultInjector 'after_publish_api_before_outcome' $context
  $published=$true
  Invoke-DirectRootEvidenceFault $FaultInjector 'after_publish' $context
  [void](Assert-DirectRootEvidencePath -Path $primary)
  $final=Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot
  if(-not(Test-DirectRootExactReadback $final $generation $payload)){throw 'EVIDENCE_FINAL_EXACT_READBACK_FAILED'}
  return [pscustomobject]@{primary_path=$primary;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload;verified=$true;publication_outcome='PUBLISHED_VERIFIED';error=''}
 }catch{
  $failure=$_.Exception.Message
  if($published -or $publicationAttempted){
   $reconciled=Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot
   if(Test-DirectRootExactReadback $reconciled $generation $payload){return [pscustomobject]@{primary_path=$primary;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload;verified=$true;publication_outcome='PUBLISHED_VERIFIED';error=$failure}}
   if($publicationAttempted -and -not $published){
    if((-not $oldExists) -and -not(Test-Path -LiteralPath $primary)){try{if(Test-Path -LiteralPath $temp -PathType Leaf){[IO.File]::Delete($temp)}}catch{};return [pscustomobject]@{primary_path=$primary;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload;verified=$false;publication_outcome='NOT_PUBLISHED';error=$failure}}
    if($oldExists -and $null -ne $oldRead -and [bool]$oldRead.valid -and (Test-DirectRootExactReadback $reconciled ([string]$oldRead.record.generation_id) ([string]$oldRead.record.payload_sha256))){try{if(Test-Path -LiteralPath $temp -PathType Leaf){[IO.File]::Delete($temp)}}catch{};return [pscustomobject]@{primary_path=$primary;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload;verified=$false;publication_outcome='NOT_PUBLISHED';error=$failure}}
   }
   return [pscustomobject]@{primary_path=$primary;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload;verified=$false;publication_outcome='PUBLISHED_AMBIGUOUS';error=$failure}
  }
  try{if(Test-Path -LiteralPath $temp -PathType Leaf){[IO.File]::Delete($temp)}}catch{}
  return [pscustomobject]@{primary_path=$primary;recovery_path=$recovery;generation_id=$generation;payload_sha256=$payload;verified=$false;publication_outcome='NOT_PUBLISHED';error=$failure}
 }
}
function Read-DirectRootPreparedState {
 [CmdletBinding()]param([Parameter(Mandatory)][string]$Path,[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot)
 try{
  $validated=Assert-DirectRootEvidencePath -Path $Path;if(-not(Test-Path -LiteralPath $validated -PathType Leaf)){return [pscustomobject]@{valid=$false;errors=@('missing')}}
  $raw=Get-Content -Raw -LiteralPath $validated;[void](Assert-DirectRootEvidencePath -Path $validated);$record=$raw|ConvertFrom-Json;$errors=New-Object Collections.Generic.List[string]
  $fields=@('schema_version','status','prepared_utc','app_pool','previous_existed','previous_document_root','target_document_root','backup_root');$names=@($record.PSObject.Properties.Name)
  foreach($n in $fields){if($names -cnotcontains $n){$errors.Add("missing_property:$n")}};foreach($n in $names){if($fields -cnotcontains $n){$errors.Add("unexpected_property:$n")}}
  if($errors.Count -eq 0){
   if(($record.schema_version -isnot [int])-or [int]$record.schema_version -ne 3){$errors.Add('schema_version_mismatch')};if(($record.status -isnot [string])-or [string]$record.status -cne 'prepared'){$errors.Add('status_mismatch')}
   if(($record.prepared_utc -isnot [string])-or -not(Test-DirectRootTimestamp ([string]$record.prepared_utc))){$errors.Add('prepared_utc_invalid')};if(($record.app_pool -isnot [string])-or [string]$record.app_pool -cne $ExpectedAppPool){$errors.Add('app_pool_mismatch')}
   if(-not(Test-DirectRootIdentity ([string]$record.target_document_root) $ExpectedTarget)){$errors.Add('target_mismatch')};if(-not(Test-DirectRootIdentity ([string]$record.backup_root) $ExpectedBackupRoot)){$errors.Add('backup_mismatch')}
   if($record.previous_existed -isnot [bool]){$errors.Add('previous_existed_invalid')}elseif([bool]$record.previous_existed){if([string]::IsNullOrWhiteSpace([string]$record.previous_document_root)){$errors.Add('previous_document_root_required')}}elseif(-not [string]::IsNullOrEmpty([string]$record.previous_document_root)){$errors.Add('previous_document_root_must_be_empty')}
  }
  return [pscustomobject]@{valid=($errors.Count -eq 0);record=$record;errors=@($errors)}
 }catch{return [pscustomobject]@{valid=$false;record=$null;errors=@($_.Exception.Message)}}
}
function Resolve-DirectRootEvidenceSet {
 [CmdletBinding()]param([Parameter(Mandatory)][string]$PrimaryPath,[string]$PreparedPath='',[Parameter(Mandatory)][string]$ExpectedAppPool,[Parameter(Mandatory)][AllowEmptyString()][string]$ExpectedTarget,[Parameter(Mandatory)][string]$ExpectedBackupRoot)
 $primary=Assert-DirectRootEvidencePath -Path $PrimaryPath;$dir=Split-Path -Parent $primary
 $temps=@(Get-ChildItem -LiteralPath $dir -Filter 'state.next.*.json' -Force -ErrorAction SilentlyContinue)
 if($temps.Count -gt 0){return [pscustomobject]@{status='ambiguous';selected=$null;recoveries=@();temps=$temps;cleanup_pending=$true;reason='unaccepted_temp_present'}}
 $preparedPresent=$false
 if(-not [string]::IsNullOrEmpty($PreparedPath)){
  try{$preparedValidated=Assert-DirectRootEvidencePath -Path $PreparedPath;$preparedPresent=Test-Path -LiteralPath $preparedValidated -PathType Leaf}catch{return [pscustomobject]@{status='ambiguous';selected=$null;recoveries=@();temps=@();cleanup_pending=$true;reason='prepared_path_unsafe'}}
  if($preparedPresent){$preparedRead=Read-DirectRootPreparedState -Path $preparedValidated -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot;if(-not $preparedRead.valid){return [pscustomobject]@{status='ambiguous';selected=$null;recoveries=@();temps=@();cleanup_pending=$true;reason='prepared_state_conflict'}}}
 }
 $primaryRead=Read-DirectRootEvidenceFile -Path $primary -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot
 $validRecoveries=@();$invalidRecovery=$false
 foreach($p in @(Get-ChildItem -LiteralPath $dir -Filter 'state.previous.*.json' -Force -ErrorAction SilentlyContinue)){
  if([bool]($p.Attributes -band [IO.FileAttributes]::ReparsePoint)){$invalidRecovery=$true;continue}
  $candidateFull=[IO.Path]::GetFullPath($p.FullName);if(-not [string]::Equals((Split-Path -Parent $candidateFull),$dir,[StringComparison]::OrdinalIgnoreCase)){$invalidRecovery=$true;continue}
  $r=Read-DirectRootEvidenceFile -Path $candidateFull -ExpectedAppPool $ExpectedAppPool -ExpectedTarget $ExpectedTarget -ExpectedBackupRoot $ExpectedBackupRoot;if($r.valid){$validRecoveries+=,$r}else{$invalidRecovery=$true}
 }
 if($primaryRead.valid){
  $conflict=$invalidRecovery
  foreach($r in $validRecoveries){if([string]$r.record.generation_id -ceq [string]$primaryRead.record.generation_id -or [DateTimeOffset]::Parse([string]$r.record.changed_utc) -gt [DateTimeOffset]::Parse([string]$primaryRead.record.changed_utc)){$conflict=$true}}
  if($conflict){return [pscustomobject]@{status='ambiguous';selected=$null;recoveries=$validRecoveries;temps=@();cleanup_pending=$true;reason='conflicting_recovery'}}
  return [pscustomobject]@{status='primary_valid';selected=$primaryRead;recoveries=$validRecoveries;temps=@();cleanup_pending=($preparedPresent -or $validRecoveries.Count -gt 0);reason=if($preparedPresent){'prepared_cleanup_pending'}else{''}}
 }
 if($preparedPresent){return [pscustomobject]@{status='ambiguous';selected=$null;recoveries=$validRecoveries;temps=@();cleanup_pending=$true;reason='prepared_without_valid_primary'}}
 if($invalidRecovery -or $validRecoveries.Count -gt 1){return [pscustomobject]@{status='ambiguous';selected=$null;recoveries=$validRecoveries;temps=@();cleanup_pending=$true;reason='recovery_conflict'}}
 if($validRecoveries.Count -eq 1){return [pscustomobject]@{status='recovery_required';selected=$validRecoveries[0];recoveries=$validRecoveries;temps=@();cleanup_pending=$true;reason='primary_invalid'}}
 return [pscustomobject]@{status='unavailable';selected=$null;recoveries=@();temps=@();cleanup_pending=$false;reason='no_evidence'}
}
