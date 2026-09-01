[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()]
    [string] $ProjectRoot = '',

    [string] $LibreOfficePath = '',

    [string] $SamplePptx = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
}

function Assert-Condition {
    param(
        [Parameter(Mandatory)][bool] $Condition,
        [Parameter(Mandatory)][string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Test-PathIsWithin {
    param(
        [Parameter(Mandatory)][string] $Parent,
        [Parameter(Mandatory)][string] $Candidate
    )

    $parentPath = [IO.Path]::GetFullPath($Parent).TrimEnd('\', '/')
    $candidatePath = [IO.Path]::GetFullPath($Candidate).TrimEnd('\', '/')
    return $candidatePath.StartsWith($parentPath + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)
}

function Get-FileSha256 {
    param([Parameter(Mandatory)][string] $Path)

    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

$ProjectRoot = (Get-Item -LiteralPath $ProjectRoot -Force -ErrorAction Stop).FullName.TrimEnd('\', '/')
$workerPath = Join-Path $ProjectRoot 'scripts\presentation_preview_worker.ps1'
if (-not (Test-Path -LiteralPath $workerPath -PathType Leaf)) {
    throw 'Presentation preview worker script was not found.'
}

$tokens = $null
$parseErrors = $null
[void][Management.Automation.Language.Parser]::ParseFile($workerPath, [ref]$tokens, [ref]$parseErrors)
Assert-Condition -Condition ($parseErrors.Count -eq 0) -Message 'Presentation preview worker has PowerShell parse errors.'

$workerText = [IO.File]::ReadAllText($workerPath)
$requiredSafetyMarkers = @(
    '[IO.FileShare]::Read',
    'SOURCE_PATH_ESCAPE',
    'SOURCE_REPARSE_POINT',
    'ExpectedSourceSha256',
    '-env:UserInstallation=',
    "Join-Path `$resolvedPreviewRoot '.staging'",
    '[IO.Directory]::Move',
    'schemaVersion',
    'sourceSha256',
    'pdfSha256',
    'slideCount',
    'converterVersion',
    'createdUtc',
    "pdfFile = 'preview.pdf'",
    'LIBREOFFICE_NOT_FOUND'
)
foreach ($marker in $requiredSafetyMarkers) {
    Assert-Condition -Condition ($workerText.Contains($marker)) -Message "Required preview-worker safety marker is missing: $marker"
}

# Dry negative test: a rooted path must be rejected before converter discovery.
$testRoot = Join-Path $ProjectRoot ('.presentation-preview-test-' + [Guid]::NewGuid().ToString('N'))
Assert-Condition -Condition (Test-PathIsWithin -Parent $ProjectRoot -Candidate $testRoot) -Message 'Test root escaped the project root.'
try {
    $sourceRoot = Join-Path $testRoot 'source'
    $previewRoot = Join-Path $testRoot 'previews'
    $runtimeRoot = Join-Path $testRoot 'runtime'
    foreach ($path in @($sourceRoot, $previewRoot, $runtimeRoot)) {
        [void][IO.Directory]::CreateDirectory($path)
    }

    $negativeFailedSafely = $false
    try {
        & $workerPath `
            -SourceRoot $sourceRoot `
            -RelativePath 'C:\outside\unsafe.pptx' `
            -PreviewRoot $previewRoot `
            -RuntimeRoot $runtimeRoot `
            -LibreOfficePath 'C:\missing\soffice.com' 2>&1 | Out-Null
    }
    catch {
        $negativeFailedSafely = $_.Exception.Message.Contains('[INVALID_RELATIVE_PATH]')
    }
    Assert-Condition -Condition $negativeFailedSafely -Message 'Rooted-path negative test did not fail with the safe validation code.'

    # Dry dependency diagnosis: an explicit nonexistent converter must return a
    # stable code and a path-free diagnostic record. The placeholder is never
    # opened because converter resolution precedes the source snapshot.
    $missingLibreOfficeSource = Join-Path $sourceRoot 'missing-libreoffice.pptx'
    [IO.File]::WriteAllBytes($missingLibreOfficeSource, (New-Object byte[] 1))
    $missingLibreOfficeFailedSafely = $false
    try {
        & $workerPath `
            -SourceRoot $sourceRoot `
            -RelativePath 'missing-libreoffice.pptx' `
            -PreviewRoot $previewRoot `
            -RuntimeRoot $runtimeRoot `
            -LibreOfficePath (Join-Path $testRoot 'missing\soffice.com') 2>&1 | Out-Null
    }
    catch {
        $missingLibreOfficeFailedSafely = $_.Exception.Message.Contains('[LIBREOFFICE_NOT_FOUND]')
    }
    Assert-Condition -Condition $missingLibreOfficeFailedSafely -Message 'Missing-LibreOffice diagnosis did not return its stable safe code.'
    $diagnosticFiles = @(Get-ChildItem -LiteralPath (Join-Path $runtimeRoot 'errors') -Filter '*.json' -File -ErrorAction Stop)
    Assert-Condition -Condition ($diagnosticFiles.Count -eq 1) -Message 'Missing-LibreOffice diagnosis did not write exactly one safe record.'
    $diagnosticText = [IO.File]::ReadAllText($diagnosticFiles[0].FullName)
    $diagnostic = $diagnosticText | ConvertFrom-Json -ErrorAction Stop
    Assert-Condition -Condition ($diagnostic.code -eq 'LIBREOFFICE_NOT_FOUND') -Message 'Dependency diagnostic code is incorrect.'
    Assert-Condition -Condition (-not $diagnosticText.Contains($sourceRoot)) -Message 'Dependency diagnostic exposed an absolute source path.'
    Assert-Condition -Condition (-not $diagnosticText.Contains('missing-libreoffice.pptx')) -Message 'Dependency diagnostic exposed a source filename.'

    if ([string]::IsNullOrWhiteSpace($LibreOfficePath) -xor [string]::IsNullOrWhiteSpace($SamplePptx)) {
        throw 'Supply both -LibreOfficePath and -SamplePptx for the optional conversion integration test, or supply neither.'
    }

    if (-not [string]::IsNullOrWhiteSpace($LibreOfficePath)) {
        $sampleItem = Get-Item -LiteralPath $SamplePptx -Force -ErrorAction Stop
        Assert-Condition -Condition (-not $sampleItem.PSIsContainer) -Message 'SamplePptx must be a file.'
        Assert-Condition -Condition ([string]::Equals($sampleItem.Extension, '.pptx', [StringComparison]::OrdinalIgnoreCase)) -Message 'SamplePptx must have a .pptx extension.'

        $testPptx = Join-Path $sourceRoot 'sample.pptx'
        [IO.File]::Copy($sampleItem.FullName, $testPptx, $false)
        $sourceHashBefore = Get-FileSha256 -Path $testPptx
        [IO.File]::SetAttributes($testPptx, [IO.FileAttributes]::ReadOnly)

        $resultJson = & $workerPath `
            -SourceRoot $sourceRoot `
            -RelativePath 'sample.pptx' `
            -PreviewRoot $previewRoot `
            -RuntimeRoot $runtimeRoot `
            -ExpectedSourceSha256 $sourceHashBefore `
            -LibreOfficePath $LibreOfficePath `
            -EmitJson
        $result = $resultJson | ConvertFrom-Json -ErrorAction Stop
        Assert-Condition -Condition ($result.status -eq 'created') -Message 'Integration conversion did not create a preview generation.'
        Assert-Condition -Condition ($result.sourceSha256 -eq $sourceHashBefore) -Message 'Integration conversion returned the wrong source hash.'
        Assert-Condition -Condition ((Get-FileSha256 -Path $testPptx) -eq $sourceHashBefore) -Message 'The source PPTX changed during integration conversion.'
        Assert-Condition -Condition ([bool]((Get-Item -LiteralPath $testPptx -Force).Attributes -band [IO.FileAttributes]::ReadOnly)) -Message 'The source PPTX read-only attribute changed.'

        $generationRoot = Join-Path $previewRoot (($sourceHashBefore.Substring(0, 2)) + '\' + $sourceHashBefore)
        $manifestPath = Join-Path $generationRoot 'manifest.json'
        $pdfPath = Join-Path $generationRoot 'preview.pdf'
        Assert-Condition -Condition (Test-Path -LiteralPath $manifestPath -PathType Leaf) -Message 'Integration manifest was not published.'
        Assert-Condition -Condition (Test-Path -LiteralPath $pdfPath -PathType Leaf) -Message 'Integration PDF was not published.'
        $manifest = [IO.File]::ReadAllText($manifestPath) | ConvertFrom-Json -ErrorAction Stop
        Assert-Condition -Condition ($manifest.sourceSha256 -eq $sourceHashBefore) -Message 'Manifest source hash is incorrect.'
        Assert-Condition -Condition ($manifest.pdfSha256 -eq (Get-FileSha256 -Path $pdfPath)) -Message 'Manifest PDF hash is incorrect.'
        Assert-Condition -Condition ([int]$manifest.slideCount -ge 1) -Message 'Manifest slide count is invalid.'

        $secondResultJson = & $workerPath `
            -SourceRoot $sourceRoot `
            -RelativePath 'sample.pptx' `
            -PreviewRoot $previewRoot `
            -RuntimeRoot $runtimeRoot `
            -ExpectedSourceSha256 $sourceHashBefore `
            -LibreOfficePath $LibreOfficePath `
            -EmitJson
        $secondResult = $secondResultJson | ConvertFrom-Json -ErrorAction Stop
        Assert-Condition -Condition ($secondResult.status -eq 'existing') -Message 'Second integration conversion was not idempotent.'
        Write-Host '[OK] Presentation preview integration test passed: conversion, immutable publish, hashes, slide count, source read-only integrity, and idempotency.'
    }
    else {
        Write-Host '[OK] Presentation preview dry/static test passed: syntax, required safety controls, rooted-path rejection, and safe LibreOffice diagnosis.'
        Write-Host '[INFO] LibreOffice was not executed. Supply -LibreOfficePath and -SamplePptx to run the optional local integration conversion.'
    }
}
finally {
    if (Test-Path -LiteralPath $testRoot) {
        if (-not (Test-PathIsWithin -Parent $ProjectRoot -Candidate $testRoot)) {
            throw 'Refusing to remove a test path outside the project root.'
        }
        Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
