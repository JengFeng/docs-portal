[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$engine=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'image_source_version_engine.ps1') -Raw
$cli=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'publish_image_output.ps1') -Raw
if($engine.Contains('IsolatedTestOnly')-or$cli.Contains('IsolatedTestOnly')){throw 'Production image pipeline must not retain a test mutation bypass.'}
if($engine.Contains('IMAGE_AUTO_COMMIT_DISABLED_PENDING_TRUSTED_BINDING')){throw 'Signed image pipeline must not retain the obsolete unconditional disable gate.'}
foreach($parameter in @('SourceRoot','PreviewRoot','ArchiveRoot','SessionRoot','CommandKeyPath')){if($cli-match('(?m)^\s*\[[^\]]*\]\[string\]\$'+$parameter+'\b')){throw "Public image CLI must not expose $parameter."}}
foreach($token in @('C:\web\gary\TWWATER\document-library','C:\TWWATER\runtime\pre-upload-previews','C:\TWWATER\runtime\image-source-archive','C:\TWWATER\runtime\sessions','image-command.key','-CommandKeyPath','reindex_confirmed','document-version-approved','approvalMarker','Repair-TWWaterPreparedImagePublishes')){if(-not$cli.Contains($token)){throw "Public image CLI is missing fixed trust boundary: $token"}}
foreach($token in @('binding_hmac','Get-TWWaterImageBindingHmac','IMAGE_BINDING_INVALID')){if(-not$engine.Contains($token)){throw "Image engine signed-binding contract missing: $token"}}
Write-Host '[OK] Public image CLI exposes no root/key override and requires signed binding.'
