[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $DocumentPublicId,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $RelativePath,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $CandidatePath,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-fA-F]{64}$')][string] $ExpectedCurrentHash,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string] $SourceRoot,
    [ValidateNotNullOrEmpty()][string] $VersionRoot = 'C:\TWWATER\document-versions',
    [ValidateNotNullOrEmpty()][string] $SessionRoot = 'C:\TWWATER\runtime\sessions',
    [ValidateNotNullOrEmpty()][string] $ActorReference = 'hermes-agent'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$engine = Join-Path $PSScriptRoot 'document_version_engine.ps1'
if (-not (Test-Path -LiteralPath $engine -PathType Leaf)) {
    throw 'Document version engine was not found.'
}
. $engine

if ([IO.Path]::GetExtension($RelativePath).ToLowerInvariant() -in @('.png','.jpg','.jpeg','.webp','.gif') -or
    [IO.Path]::GetExtension($CandidatePath).ToLowerInvariant() -in @('.png','.jpg','.jpeg','.webp','.gif')) {
    throw 'IMAGE_ASSET_REQUIRES_SIGNED_IMAGE_PIPELINE'
}

$result = Publish-TWWaterDocumentVersion `
    -SourceRoot $SourceRoot `
    -VersionRoot $VersionRoot `
    -SessionRoot $SessionRoot `
    -DocumentPublicId $DocumentPublicId `
    -RelativePath $RelativePath `
    -CandidatePath $CandidatePath `
    -ExpectedCurrentHash $ExpectedCurrentHash `
    -Reason publish `
    -ActorReference $ActorReference

[ordered]@{
    ok = $true
    operation_id = $result.OperationId
    previous_hash = $result.PreviousHash
    current_hash = $result.CurrentHash
} | ConvertTo-Json -Compress
