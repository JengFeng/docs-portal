[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
function Expect-Invalid($Request,[string]$Label){$actual='';try{Assert-TWWaterImageRestoreRequestSchema $Request}catch{$actual=$_.Exception.Message};if($actual-ne'IMAGE_RESTORE_REQUEST_INVALID'){throw "$Label expected IMAGE_RESTORE_REQUEST_INVALID, got $actual"}}
$valid=[pscustomobject][ordered]@{schema_version=[int64]2;operation='restore';archive_scope='image-source';operation_id='11111111-1111-4111-8111-111111111111';document_public_id='4380862e-aca7-4637-872e-0222ac65d09b';document_relative_path='image.png';original_file_name='image.png';extension='png';target_content_hash='a'*64;expected_current_hash='b'*64;requested_by_user_id=[int64]7;requested_utc='2026-08-30T10:00:00Z';request_hmac='c'*64}
Assert-TWWaterImageRestoreRequestSchema $valid
$extra=$valid|Select-Object *;$extra|Add-Member -NotePropertyName injected -NotePropertyValue 'x';Expect-Invalid $extra 'extra field'
$stringSchema=$valid|Select-Object *;$stringSchema.schema_version='2';Expect-Invalid $stringSchema 'string schema_version'
$stringUser=$valid|Select-Object *;$stringUser.requested_by_user_id='7';Expect-Invalid $stringUser 'string requested_by_user_id'
Write-Host '[OK] Signed image restore requires exact fields and JSON types.'
