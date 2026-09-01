[CmdletBinding()]
param(
  [Parameter(Mandatory)][string] $DocumentPublicId,
  [Parameter(Mandatory)][string] $RelativePath,
  [Parameter(Mandatory)][ValidatePattern('^[0-9a-fA-F]{64}$')][string] $ContentHash,
  [string] $SessionRoot = 'C:\TWWATER\runtime\sessions',
  [string] $OperationId = ''
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
if ([string]::IsNullOrWhiteSpace($OperationId)) { $OperationId = [Guid]::NewGuid().ToString() }
Write-TWWaterVersionApproval -SessionRoot $SessionRoot -DocumentPublicId $DocumentPublicId.ToLowerInvariant() -RelativePath $RelativePath -ContentHash $ContentHash.ToLowerInvariant() -OperationId $OperationId
[ordered]@{ ok=$true; operation_id=$OperationId; content_hash=$ContentHash.ToLowerInvariant() } | ConvertTo-Json -Compress
