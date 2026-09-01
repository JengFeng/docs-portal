[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'image_source_version_engine.ps1')
function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-manifest-types-'+[Guid]::NewGuid().ToString('N'));[void][IO.Directory]::CreateDirectory($root)
$base=[ordered]@{schema_version=2;document_id=[Guid]::NewGuid().ToString();relative_path='image.png';source_path='C:\fixture\image.png';source_sha256='a'*64;candidate_path=(Join-Path $root 'candidate.png');candidate_sha256='b'*64;source_size=@(8,8);roi_px=@(0,0,8,8);outside_roi_changed_pixels=[int64]0;validation_status='passed';external_api_used=$false;source_overwritten=$false;binding=[ordered]@{binding_id=[Guid]::NewGuid().ToString();issued_utc='2026-08-30T10:00:00Z';expires_utc='2026-08-30T11:00:00Z';public_id=[Guid]::NewGuid().ToString();relative_path='image.png';source_hash='a'*64;extension='png';source_width=8;source_height=8;regions='0,0,8,8';binding_hmac='c'*64}}
function Invoke-Case([string]$Name,[scriptblock]$Mutate){$value=[ordered]@{};foreach($entry in $base.GetEnumerator()){$value[$entry.Key]=$entry.Value};&$Mutate $value;$path=Join-Path $root ($Name+'.json');[IO.File]::WriteAllText($path,($value|ConvertTo-Json -Compress -Depth 6),(New-Object Text.UTF8Encoding($false)));$actual='';try{[void](Get-TWWaterImageManifest $path $root)}catch{$actual=$_.Exception.Message};Assert ($actual-eq'IMAGE_MANIFEST_INVALID') ("$Name should fail strict manifest typing, got: $actual")}
try{
 Invoke-Case missing_external {param($m)$m.Remove('external_api_used')}
 Invoke-Case missing_overwritten {param($m)$m.Remove('source_overwritten')}
 Invoke-Case missing_outside {param($m)$m.Remove('outside_roi_changed_pixels')}
 Invoke-Case string_external {param($m)$m.external_api_used='false'}
 Invoke-Case string_overwritten {param($m)$m.source_overwritten='false'}
 Invoke-Case string_outside {param($m)$m.outside_roi_changed_pixels='0'}
 Invoke-Case extra_field {param($m)$m['injected']='x'}
 Invoke-Case string_source_size {param($m)$m.source_size=@('8',8)}
 $valid=Join-Path $root 'valid.json';[IO.File]::WriteAllText($valid,($base|ConvertTo-Json -Compress -Depth 6),(New-Object Text.UTF8Encoding($false)));[void](Get-TWWaterImageManifest $valid $root)
 $authorizedExternal=[ordered]@{};foreach($entry in $base.GetEnumerator()){$authorizedExternal[$entry.Key]=$entry.Value};$authorizedExternal.external_api_used=$true;$external=Join-Path $root 'authorized-external.json';[IO.File]::WriteAllText($external,($authorizedExternal|ConvertTo-Json -Compress -Depth 6),(New-Object Text.UTF8Encoding($false)));[void](Get-TWWaterImageManifest $external $root)
 Write-Host '[OK] Image manifest requires explicit correctly typed evidence and accepts an authorized external-image result.'
}finally{if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
