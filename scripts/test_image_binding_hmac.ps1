[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
$key=[byte[]](0..31)
$binding=[ordered]@{binding_id='22222222-2222-4222-8222-222222222222';issued_utc='2026-08-30T10:00:00Z';expires_utc='2026-08-30T11:00:00Z';public_id='4380862e-aca7-4637-872e-0222ac65d09b';relative_path='infographic/test.png';source_hash='a'*64;extension='png';source_width=8;source_height=8;regions='0,0,8,8'}
$actual=Get-TWWaterImageBindingHmac -Binding $binding -Key $key
if($actual-ne'13d7c60ab6a36a0dcfc6d410576525caaa8e3279c71a3afa109af5d44ab4a067'){throw "PowerShell image binding HMAC mismatch: $actual"}
Write-Host '[OK] PowerShell image binding HMAC matches the cross-language vector.'
