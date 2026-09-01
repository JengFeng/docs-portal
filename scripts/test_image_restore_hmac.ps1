[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
$key=[byte[]](0..31);$request=[ordered]@{schema_version=2;operation='restore';archive_scope='image-source';operation_id='11111111-1111-4111-8111-111111111111';document_public_id='4380862e-aca7-4637-872e-0222ac65d09b';document_relative_path='infographic/test.png';extension='png';target_content_hash='a'*64;expected_current_hash='b'*64;requested_by_user_id=7;requested_utc='2026-08-30T10:00:00Z'};$actual=Get-TWWaterImageRestoreHmac -Request $request -Key $key;if($actual-ne'd0a1aa04e98cd25f3bbda6e371c3b8361537e90e339c5cb2d8941edd2435f95a'){throw "PowerShell restore HMAC mismatch: $actual"};Write-Host '[OK] PowerShell image restore HMAC matches the cross-language vector.'
