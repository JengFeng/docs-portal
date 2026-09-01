[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $SourceRoot,

    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $RelativePath,

    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $PreviewRoot,

    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $RuntimeRoot,

    [ValidatePattern('^(?i:[0-9a-f]{64})$|^$')]
    [string] $ExpectedSourceSha256 = '',

    [string] $LibreOfficePath = '',

    [ValidateRange(10, 1800)]
    [int] $TimeoutSeconds = 180,

    [ValidateRange(1048576, 1073741824)]
    [long] $MaxSourceBytes = 104857600,

    [ValidateRange(1, 600)]
    [int] $LockTimeoutSeconds = 60,

    [switch] $EmitJson
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:ManifestKind = 'TWWATER_PRESENTATION_PREVIEW'
$script:ManifestSchemaVersion = 1
$script:JobId = [Guid]::NewGuid().ToString('N')
$script:FailureStage = 'startup'
$script:FailureCode = 'UNEXPECTED_FAILURE'
$script:LibreOfficeExitCode = $null
$script:ResolvedRuntimeRoot = $null
$script:JobRoot = $null
$script:StagingRoot = $null
$script:LockPath = $null
$script:LockStream = $null
$resolvedPreviewRoot = $null

function New-SafePreviewException {
    param(
        [Parameter(Mandatory)][string] $Code,
        [Parameter(Mandatory)][string] $SafeMessage
    )

    $script:FailureCode = $Code
    $exception = New-Object InvalidOperationException($SafeMessage)
    $exception.Data['PreviewErrorCode'] = $Code
    return $exception
}

function Throw-SafePreviewError {
    param(
        [Parameter(Mandatory)][string] $Code,
        [Parameter(Mandatory)][string] $SafeMessage
    )

    throw (New-SafePreviewException -Code $Code -SafeMessage $SafeMessage)
}

function Get-NormalizedFullPath {
    param([Parameter(Mandatory)][string] $Path)

    return [IO.Path]::GetFullPath($Path).TrimEnd('\', '/')
}

function Test-PathEquals {
    param(
        [Parameter(Mandatory)][string] $First,
        [Parameter(Mandatory)][string] $Second
    )

    return [string]::Equals(
        (Get-NormalizedFullPath -Path $First),
        (Get-NormalizedFullPath -Path $Second),
        [StringComparison]::OrdinalIgnoreCase
    )
}

function Test-PathIsWithin {
    param(
        [Parameter(Mandatory)][string] $Parent,
        [Parameter(Mandatory)][string] $Candidate
    )

    $normalizedParent = Get-NormalizedFullPath -Path $Parent
    $normalizedCandidate = Get-NormalizedFullPath -Path $Candidate
    $prefix = $normalizedParent + [IO.Path]::DirectorySeparatorChar
    return $normalizedCandidate.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

function Assert-SeparateDirectoryTrees {
    param(
        [Parameter(Mandatory)][string] $First,
        [Parameter(Mandatory)][string] $Second,
        [Parameter(Mandatory)][string] $Code
    )

    if ((Test-PathEquals -First $First -Second $Second) -or
        (Test-PathIsWithin -Parent $First -Candidate $Second) -or
        (Test-PathIsWithin -Parent $Second -Candidate $First)) {
        Throw-SafePreviewError -Code $Code -SafeMessage 'Source, preview, and runtime directories must be separate, non-nested directory trees.'
    }
}

function Assert-RealDirectory {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Code
    )

    try {
        $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    }
    catch {
        Throw-SafePreviewError -Code $Code -SafeMessage 'A required directory does not exist or cannot be inspected.'
    }
    if (-not $item.PSIsContainer) {
        Throw-SafePreviewError -Code $Code -SafeMessage 'A required directory path is not a directory.'
    }
    if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Throw-SafePreviewError -Code $Code -SafeMessage 'A protected worker directory must not be a junction, symbolic link, or other reparse point.'
    }
    return $item.FullName.TrimEnd('\', '/')
}

function New-RealDirectory {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Code
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        try {
            [void][IO.Directory]::CreateDirectory($Path)
        }
        catch {
            Throw-SafePreviewError -Code $Code -SafeMessage 'A protected worker directory could not be created.'
        }
    }
    return Assert-RealDirectory -Path $Path -Code $Code
}

function Assert-SourcePathChain {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $Candidate
    )

    $normalizedRoot = Get-NormalizedFullPath -Path $Root
    $normalizedCandidate = Get-NormalizedFullPath -Path $Candidate
    if (-not (Test-PathIsWithin -Parent $normalizedRoot -Candidate $normalizedCandidate)) {
        Throw-SafePreviewError -Code 'SOURCE_PATH_ESCAPE' -SafeMessage 'The requested presentation escaped the configured source root.'
    }

    $relative = $normalizedCandidate.Substring($normalizedRoot.Length).TrimStart('\', '/')
    $current = $normalizedRoot
    foreach ($segment in ($relative -split '[\\/]')) {
        if ([string]::IsNullOrWhiteSpace($segment)) {
            continue
        }
        $current = Join-Path $current $segment
        try {
            $item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
        }
        catch {
            Throw-SafePreviewError -Code 'SOURCE_NOT_FOUND' -SafeMessage 'The requested presentation does not exist or cannot be inspected.'
        }
        if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            Throw-SafePreviewError -Code 'SOURCE_REPARSE_POINT' -SafeMessage 'The requested presentation path contains a reparse point and was rejected.'
        }
    }
}

function Convert-ToSafeRelativePath {
    param([Parameter(Mandatory)][string] $Path)

    if ([IO.Path]::IsPathRooted($Path) -or $Path.IndexOf([char]0) -ge 0) {
        Throw-SafePreviewError -Code 'INVALID_RELATIVE_PATH' -SafeMessage 'The presentation path must be a relative path beneath the configured source root.'
    }

    $segments = $Path -split '[\\/]'
    if ($segments.Count -eq 0) {
        Throw-SafePreviewError -Code 'INVALID_RELATIVE_PATH' -SafeMessage 'The presentation relative path is empty.'
    }
    foreach ($segment in $segments) {
        if ([string]::IsNullOrWhiteSpace($segment) -or $segment -eq '.' -or $segment -eq '..' -or $segment.Contains(':')) {
            Throw-SafePreviewError -Code 'INVALID_RELATIVE_PATH' -SafeMessage 'The presentation relative path contains an unsupported path segment.'
        }
    }
    if (-not [string]::Equals([IO.Path]::GetExtension($Path), '.pptx', [StringComparison]::OrdinalIgnoreCase)) {
        Throw-SafePreviewError -Code 'UNSUPPORTED_SOURCE_TYPE' -SafeMessage 'Only PPTX source files can be converted by this worker.'
    }
    if ([IO.Path]::GetFileName($Path).StartsWith('~$', [StringComparison]::Ordinal)) {
        Throw-SafePreviewError -Code 'OFFICE_TEMPORARY_FILE' -SafeMessage 'Office temporary files cannot be converted.'
    }
    return ($segments -join [IO.Path]::DirectorySeparatorChar)
}

function Write-Utf8NoBomFile {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][AllowEmptyString()][string] $Text
    )

    $encoding = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($Path, $Text, $encoding)
}

function Write-AtomicText {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][AllowEmptyString()][string] $Text
    )

    $parent = Split-Path -Parent $Path
    if ([string]::IsNullOrWhiteSpace($parent)) {
        Throw-SafePreviewError -Code 'ATOMIC_WRITE_INVALID' -SafeMessage 'An atomic-write target had no parent directory.'
    }
    [void](New-RealDirectory -Path $parent -Code 'ATOMIC_WRITE_DIRECTORY_INVALID')
    $temporaryPath = Join-Path $parent ('.presentation-preview-write-{0}.tmp' -f [Guid]::NewGuid().ToString('N'))
    $backupPath = Join-Path $parent ('.presentation-preview-backup-{0}.tmp' -f [Guid]::NewGuid().ToString('N'))
    try {
        Write-Utf8NoBomFile -Path $temporaryPath -Text $Text
        if (Test-Path -LiteralPath $Path -PathType Leaf) {
            [IO.File]::Replace($temporaryPath, $Path, $backupPath, $true)
        }
        else {
            [IO.File]::Move($temporaryPath, $Path)
        }
    }
    finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
        if (Test-Path -LiteralPath $backupPath -PathType Leaf) {
            Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Get-FileSha256 {
    param([Parameter(Mandatory)][string] $Path)

    $sha = [Security.Cryptography.SHA256]::Create()
    $stream = $null
    try {
        $stream = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        $bytes = $sha.ComputeHash($stream)
        return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
    }
    finally {
        if ($null -ne $stream) { $stream.Dispose() }
        $sha.Dispose()
    }
}

function Copy-ReadOnlySourceSnapshot {
    param(
        [Parameter(Mandatory)][string] $SourcePath,
        [Parameter(Mandatory)][string] $DestinationPath,
        [Parameter(Mandatory)][long] $MaximumBytes
    )

    $sourceStream = $null
    $destinationStream = $null
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        # FileShare.Read prevents writers and deleters for the entire hash-and-copy window.
        $sourceStream = New-Object IO.FileStream($SourcePath, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        if ($sourceStream.Length -le 0 -or $sourceStream.Length -gt $MaximumBytes) {
            Throw-SafePreviewError -Code 'SOURCE_SIZE_REJECTED' -SafeMessage 'The presentation is empty or exceeds the configured preview size limit.'
        }
        $sourceLength = $sourceStream.Length
        $hashBytes = $sha.ComputeHash($sourceStream)
        $sourceStream.Position = 0
        $destinationStream = New-Object IO.FileStream($DestinationPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $sourceStream.CopyTo($destinationStream)
        $destinationStream.Flush($true)
        $destinationStream.Dispose()
        $destinationStream = $null
        [IO.File]::SetAttributes($DestinationPath, [IO.FileAttributes]::ReadOnly)
        return [ordered]@{
            sha256 = (($hashBytes | ForEach-Object { $_.ToString('x2') }) -join '')
            length = [int64]$sourceLength
        }
    }
    catch [IO.IOException] {
        Throw-SafePreviewError -Code 'SOURCE_SNAPSHOT_FAILED' -SafeMessage 'The presentation could not be opened as a stable read-only snapshot.'
    }
    finally {
        if ($null -ne $destinationStream) { $destinationStream.Dispose() }
        if ($null -ne $sourceStream) { $sourceStream.Dispose() }
        $sha.Dispose()
    }
}

function Get-PptxSlideCount {
    param([Parameter(Mandatory)][string] $Path)

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = $null
    try {
        $archive = [IO.Compression.ZipFile]::OpenRead($Path)
        $slideEntries = @($archive.Entries | Where-Object {
            $_.FullName -match '^ppt/slides/slide[1-9][0-9]*\.xml$'
        })
        if ($slideEntries.Count -lt 1) {
            Throw-SafePreviewError -Code 'PPTX_STRUCTURE_INVALID' -SafeMessage 'The presentation package contains no valid slides.'
        }
        return [int]$slideEntries.Count
    }
    catch [IO.InvalidDataException] {
        Throw-SafePreviewError -Code 'PPTX_STRUCTURE_INVALID' -SafeMessage 'The presentation package is not a valid PPTX archive.'
    }
    finally {
        if ($null -ne $archive) { $archive.Dispose() }
    }
}

function Resolve-LibreOfficeExecutable {
    param([string] $RequestedPath)

    $candidates = New-Object Collections.Generic.List[string]
    if (-not [string]::IsNullOrWhiteSpace($RequestedPath)) {
        $candidates.Add($RequestedPath)
    }
    else {
        if (-not [string]::IsNullOrWhiteSpace($env:ProgramFiles)) {
            $candidates.Add((Join-Path $env:ProgramFiles 'LibreOffice\program\soffice.com'))
            $candidates.Add((Join-Path $env:ProgramFiles 'LibreOffice\program\soffice.exe'))
        }
        ${programFilesX86} = [Environment]::GetEnvironmentVariable('ProgramFiles(x86)')
        if (-not [string]::IsNullOrWhiteSpace(${programFilesX86})) {
            $candidates.Add((Join-Path ${programFilesX86} 'LibreOffice\program\soffice.com'))
            $candidates.Add((Join-Path ${programFilesX86} 'LibreOffice\program\soffice.exe'))
        }
        foreach ($commandName in @('soffice.com', 'soffice.exe')) {
            $command = Get-Command $commandName -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($null -ne $command) {
                $candidates.Add($command.Source)
            }
        }
    }

    foreach ($candidate in $candidates) {
        try {
            $item = Get-Item -LiteralPath $candidate -Force -ErrorAction Stop
            if (-not $item.PSIsContainer -and @('.exe', '.com') -contains $item.Extension.ToLowerInvariant()) {
                return $item.FullName
            }
        }
        catch {
            continue
        }
    }
    Throw-SafePreviewError -Code 'LIBREOFFICE_NOT_FOUND' -SafeMessage 'LibreOffice was not found. Install LibreOffice or supply the full soffice.com/soffice.exe path with -LibreOfficePath.'
}

function Get-ConverterVersion {
    param([Parameter(Mandatory)][string] $ExecutablePath)

    try {
        $version = (Get-Item -LiteralPath $ExecutablePath -Force -ErrorAction Stop).VersionInfo.ProductVersion
        if ([string]::IsNullOrWhiteSpace($version)) {
            return 'unknown'
        }
        $sanitized = ($version -replace '[^0-9A-Za-z._+ -]', '').Trim()
        if ([string]::IsNullOrWhiteSpace($sanitized)) { return 'unknown' }
        if ($sanitized.Length -gt 80) { return $sanitized.Substring(0, 80) }
        return $sanitized
    }
    catch {
        return 'unknown'
    }
}

function Quote-ProcessArgument {
    param([Parameter(Mandatory)][string] $Value)

    return '"' + $Value.Replace('"', '\"') + '"'
}

function Stop-OwnedProcessTree {
    param([Parameter(Mandatory)][int] $ProcessId)

    try {
        $children = @(Get-CimInstance Win32_Process -Filter "ParentProcessId=$ProcessId" -ErrorAction SilentlyContinue)
        foreach ($child in $children) {
            Stop-OwnedProcessTree -ProcessId ([int]$child.ProcessId)
        }
    }
    catch {
        # Best-effort timeout cleanup; never broaden the target beyond descendants.
    }
    Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
}

function Test-PdfHeader {
    param([Parameter(Mandatory)][string] $Path)

    $stream = $null
    try {
        $stream = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        if ($stream.Length -lt 8) { return $false }
        $buffer = New-Object byte[] 5
        if ($stream.Read($buffer, 0, $buffer.Length) -ne $buffer.Length) { return $false }
        return [Text.Encoding]::ASCII.GetString($buffer) -eq '%PDF-'
    }
    finally {
        if ($null -ne $stream) { $stream.Dispose() }
    }
}

function Enter-GenerationLock {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][int] $Timeout
    )

    $deadline = [DateTime]::UtcNow.AddSeconds($Timeout)
    do {
        try {
            return New-Object IO.FileStream($Path, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
        }
        catch [IO.IOException] {
            Start-Sleep -Milliseconds 250
        }
    } while ([DateTime]::UtcNow -lt $deadline)
    Throw-SafePreviewError -Code 'GENERATION_LOCK_TIMEOUT' -SafeMessage 'Another worker is still generating the same presentation version.'
}

function Get-ExistingGeneration {
    param(
        [Parameter(Mandatory)][string] $GenerationRoot,
        [Parameter(Mandatory)][string] $SourceSha256
    )

    if (-not (Test-Path -LiteralPath $GenerationRoot)) {
        return $null
    }
    $generationItem = Get-Item -LiteralPath $GenerationRoot -Force -ErrorAction Stop
    if (-not $generationItem.PSIsContainer -or [bool]($generationItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Throw-SafePreviewError -Code 'GENERATION_CONFLICT' -SafeMessage 'An invalid object already occupies the immutable preview generation path.'
    }
    $manifestPath = Join-Path $GenerationRoot 'manifest.json'
    $pdfPath = Join-Path $GenerationRoot 'preview.pdf'
    try {
        $manifest = [IO.File]::ReadAllText($manifestPath) | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        Throw-SafePreviewError -Code 'GENERATION_CONFLICT' -SafeMessage 'The existing immutable preview manifest is unreadable or invalid.'
    }
    $requiredProperties = @('kind', 'schemaVersion', 'sourceSha256', 'pdfSha256', 'slideCount', 'converter', 'converterVersion', 'createdUtc', 'pdfFile')
    foreach ($property in $requiredProperties) {
        if ($manifest.PSObject.Properties.Name -notcontains $property) {
            Throw-SafePreviewError -Code 'GENERATION_CONFLICT' -SafeMessage 'The existing immutable preview manifest is incomplete.'
        }
    }
    if ($manifest.kind -ne $script:ManifestKind -or
        [int]$manifest.schemaVersion -ne $script:ManifestSchemaVersion -or
        -not [string]::Equals([string]$manifest.sourceSha256, $SourceSha256, [StringComparison]::OrdinalIgnoreCase) -or
        $manifest.pdfFile -ne 'preview.pdf' -or
        -not (Test-Path -LiteralPath $pdfPath -PathType Leaf)) {
        Throw-SafePreviewError -Code 'GENERATION_CONFLICT' -SafeMessage 'The existing immutable preview generation does not match the requested source version.'
    }
    $actualPdfHash = Get-FileSha256 -Path $pdfPath
    if (-not [string]::Equals($actualPdfHash, [string]$manifest.pdfSha256, [StringComparison]::OrdinalIgnoreCase)) {
        Throw-SafePreviewError -Code 'GENERATION_CONFLICT' -SafeMessage 'The existing immutable preview PDF failed its integrity check.'
    }
    return $manifest
}

function Write-SafeFailureDiagnostic {
    param(
        [Parameter(Mandatory)][string] $Code,
        [Parameter(Mandatory)][string] $Stage,
        [Parameter(Mandatory)][string] $ExceptionType
    )

    if ([string]::IsNullOrWhiteSpace([string]$script:ResolvedRuntimeRoot)) {
        return
    }
    try {
        $errorRoot = New-RealDirectory -Path (Join-Path $script:ResolvedRuntimeRoot 'errors') -Code 'ERROR_LOG_DIRECTORY_INVALID'
        $diagnostic = [ordered]@{
            schemaVersion = 1
            kind = 'TWWATER_PRESENTATION_PREVIEW_FAILURE'
            jobId = $script:JobId
            code = $Code
            stage = $Stage
            exceptionType = $ExceptionType
            libreOfficeExitCode = $script:LibreOfficeExitCode
            failedUtc = [DateTime]::UtcNow.ToString('o')
        }
        Write-AtomicText -Path (Join-Path $errorRoot ($script:JobId + '.json')) -Text ($diagnostic | ConvertTo-Json -Depth 4)
    }
    catch {
        # Failure diagnostics must never replace the original safe error.
    }
}

try {
    $script:FailureStage = 'validate_paths'
    $safeRelativePath = Convert-ToSafeRelativePath -Path $RelativePath
    $resolvedSourceRoot = Assert-RealDirectory -Path $SourceRoot -Code 'SOURCE_ROOT_INVALID'
    $resolvedPreviewRoot = New-RealDirectory -Path $PreviewRoot -Code 'PREVIEW_ROOT_INVALID'
    $script:ResolvedRuntimeRoot = New-RealDirectory -Path $RuntimeRoot -Code 'RUNTIME_ROOT_INVALID'
    Assert-SeparateDirectoryTrees -First $resolvedSourceRoot -Second $resolvedPreviewRoot -Code 'SOURCE_PREVIEW_OVERLAP'
    Assert-SeparateDirectoryTrees -First $resolvedSourceRoot -Second $script:ResolvedRuntimeRoot -Code 'SOURCE_RUNTIME_OVERLAP'
    Assert-SeparateDirectoryTrees -First $resolvedPreviewRoot -Second $script:ResolvedRuntimeRoot -Code 'PREVIEW_RUNTIME_OVERLAP'

    $sourcePath = Get-NormalizedFullPath -Path (Join-Path $resolvedSourceRoot $safeRelativePath)
    Assert-SourcePathChain -Root $resolvedSourceRoot -Candidate $sourcePath
    $sourceItem = Get-Item -LiteralPath $sourcePath -Force -ErrorAction Stop
    if ($sourceItem.PSIsContainer) {
        Throw-SafePreviewError -Code 'SOURCE_NOT_FILE' -SafeMessage 'The requested presentation is not a regular file.'
    }
    if ([bool]($sourceItem.Attributes -band ([IO.FileAttributes]::Hidden -bor [IO.FileAttributes]::System))) {
        Throw-SafePreviewError -Code 'SOURCE_ATTRIBUTE_REJECTED' -SafeMessage 'Hidden or system files cannot be converted.'
    }
    $sourceLastWriteUtc = $sourceItem.LastWriteTimeUtc.ToString('o')

    $script:FailureStage = 'resolve_converter'
    $libreOfficeExecutable = Resolve-LibreOfficeExecutable -RequestedPath $LibreOfficePath
    $converterVersion = Get-ConverterVersion -ExecutablePath $libreOfficeExecutable

    $script:FailureStage = 'snapshot_source'
    $jobsRoot = New-RealDirectory -Path (Join-Path $script:ResolvedRuntimeRoot 'jobs') -Code 'JOBS_ROOT_INVALID'
    $script:JobRoot = Join-Path $jobsRoot $script:JobId
    [void](New-RealDirectory -Path $script:JobRoot -Code 'JOB_ROOT_INVALID')
    $snapshotPath = Join-Path $script:JobRoot 'presentation.pptx'
    $snapshot = Copy-ReadOnlySourceSnapshot -SourcePath $sourcePath -DestinationPath $snapshotPath -MaximumBytes $MaxSourceBytes
    $sourceSha256 = [string]$snapshot.sha256
    if (-not [string]::IsNullOrWhiteSpace($ExpectedSourceSha256) -and
        -not [string]::Equals($sourceSha256, $ExpectedSourceSha256, [StringComparison]::OrdinalIgnoreCase)) {
        Throw-SafePreviewError -Code 'SOURCE_VERSION_MISMATCH' -SafeMessage 'The presentation changed after the preview job was requested. A new job is required.'
    }
    $slideCount = Get-PptxSlideCount -Path $snapshotPath

    $script:FailureStage = 'acquire_generation_lock'
    $locksRoot = New-RealDirectory -Path (Join-Path $resolvedPreviewRoot '.locks') -Code 'LOCK_ROOT_INVALID'
    $script:LockPath = Join-Path $locksRoot ($sourceSha256 + '.lock')
    $script:LockStream = Enter-GenerationLock -Path $script:LockPath -Timeout $LockTimeoutSeconds

    $hashPrefixRoot = New-RealDirectory -Path (Join-Path $resolvedPreviewRoot $sourceSha256.Substring(0, 2)) -Code 'GENERATION_PREFIX_INVALID'
    $generationRoot = Join-Path $hashPrefixRoot $sourceSha256
    $existingManifest = Get-ExistingGeneration -GenerationRoot $generationRoot -SourceSha256 $sourceSha256
    if ($null -ne $existingManifest) {
        $summary = [ordered]@{
            status = 'existing'
            jobId = $script:JobId
            sourceSha256 = $sourceSha256
            slideCount = [int]$existingManifest.slideCount
            previewGeneration = ($sourceSha256.Substring(0, 2) + '/' + $sourceSha256)
            manifestFile = 'manifest.json'
            pdfFile = 'preview.pdf'
        }
        if ($EmitJson) { Write-Output ($summary | ConvertTo-Json -Compress) }
        else { Write-Host "[OK] Existing immutable presentation preview verified. Job: $($script:JobId)" }
        return
    }

    $script:FailureStage = 'convert_presentation'
    $profileRoot = New-RealDirectory -Path (Join-Path $script:JobRoot 'libreoffice-profile') -Code 'PROFILE_ROOT_INVALID'
    $conversionRoot = New-RealDirectory -Path (Join-Path $script:JobRoot 'conversion-output') -Code 'CONVERSION_ROOT_INVALID'
    $stdoutPath = Join-Path $script:JobRoot 'libreoffice.stdout.log'
    $stderrPath = Join-Path $script:JobRoot 'libreoffice.stderr.log'
    $profileUri = ([Uri](Get-NormalizedFullPath -Path $profileRoot)).AbsoluteUri
    $arguments = @(
        '--headless',
        '--nologo',
        '--nodefault',
        '--nolockcheck',
        '--norestore',
        ('-env:UserInstallation=' + $profileUri),
        '--convert-to',
        'pdf:impress_pdf_Export',
        '--outdir',
        (Quote-ProcessArgument -Value $conversionRoot),
        (Quote-ProcessArgument -Value $snapshotPath)
    )
    try {
        $process = Start-Process -FilePath $libreOfficeExecutable -ArgumentList $arguments -NoNewWindow -PassThru -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath
    }
    catch {
        Throw-SafePreviewError -Code 'LIBREOFFICE_START_FAILED' -SafeMessage 'LibreOffice could not be started by the preview worker identity.'
    }
    if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
        Stop-OwnedProcessTree -ProcessId $process.Id
        Throw-SafePreviewError -Code 'LIBREOFFICE_TIMEOUT' -SafeMessage 'LibreOffice exceeded the configured presentation conversion timeout.'
    }
    $process.WaitForExit()
    $script:LibreOfficeExitCode = [int]$process.ExitCode
    if ($script:LibreOfficeExitCode -ne 0) {
        Throw-SafePreviewError -Code 'LIBREOFFICE_CONVERSION_FAILED' -SafeMessage 'LibreOffice returned a nonzero presentation conversion status. Its raw output was not displayed.'
    }

    $convertedPdfPath = Join-Path $conversionRoot 'presentation.pdf'
    if (-not (Test-Path -LiteralPath $convertedPdfPath -PathType Leaf) -or -not (Test-PdfHeader -Path $convertedPdfPath)) {
        Throw-SafePreviewError -Code 'PDF_OUTPUT_INVALID' -SafeMessage 'LibreOffice did not produce a valid PDF preview artifact.'
    }

    $script:FailureStage = 'publish_generation'
    $stagingParent = New-RealDirectory -Path (Join-Path $resolvedPreviewRoot '.staging') -Code 'STAGING_ROOT_INVALID'
    $script:StagingRoot = Join-Path $stagingParent $script:JobId
    [void](New-RealDirectory -Path $script:StagingRoot -Code 'STAGING_GENERATION_INVALID')
    $stagedPdfPath = Join-Path $script:StagingRoot 'preview.pdf'
    [IO.File]::Copy($convertedPdfPath, $stagedPdfPath, $false)
    $pdfItem = Get-Item -LiteralPath $stagedPdfPath -Force -ErrorAction Stop
    $pdfSha256 = Get-FileSha256 -Path $stagedPdfPath
    $manifest = [ordered]@{
        schemaVersion = $script:ManifestSchemaVersion
        kind = $script:ManifestKind
        sourceRelativePath = ($safeRelativePath -replace '\\', '/')
        sourceSha256 = $sourceSha256
        sourceSizeBytes = [int64]$snapshot.length
        sourceLastWriteUtc = $sourceLastWriteUtc
        pdfSha256 = $pdfSha256
        pdfSizeBytes = [int64]$pdfItem.Length
        slideCount = [int]$slideCount
        converter = 'LibreOffice'
        converterVersion = $converterVersion
        createdUtc = [DateTime]::UtcNow.ToString('o')
        pdfFile = 'preview.pdf'
    }
    Write-Utf8NoBomFile -Path (Join-Path $script:StagingRoot 'manifest.json') -Text ($manifest | ConvertTo-Json -Depth 4)

    # The staging directory is below PreviewRoot, so Directory.Move publishes the
    # complete immutable PDF + manifest generation in one same-volume operation.
    [IO.Directory]::Move($script:StagingRoot, $generationRoot)
    $script:StagingRoot = $null

    $summary = [ordered]@{
        status = 'created'
        jobId = $script:JobId
        sourceSha256 = $sourceSha256
        slideCount = [int]$slideCount
        previewGeneration = ($sourceSha256.Substring(0, 2) + '/' + $sourceSha256)
        manifestFile = 'manifest.json'
        pdfFile = 'preview.pdf'
    }
    if ($EmitJson) { Write-Output ($summary | ConvertTo-Json -Compress) }
    else { Write-Host "[OK] Immutable presentation preview created. Job: $($script:JobId); slides: $slideCount" }
}
catch {
    $exceptionType = $_.Exception.GetType().FullName
    $code = $script:FailureCode
    if ($_.Exception.Data.Contains('PreviewErrorCode')) {
        $code = [string]$_.Exception.Data['PreviewErrorCode']
    }
    Write-SafeFailureDiagnostic -Code $code -Stage $script:FailureStage -ExceptionType $exceptionType
    $safeException = New-Object InvalidOperationException("Presentation preview failed [$code]. Job ID: $($script:JobId). No source contents, converter output, or secrets were displayed.")
    $safeException.Data['PreviewErrorCode'] = $code
    throw $safeException
}
finally {
    if ($null -ne $script:LockStream) {
        $script:LockStream.Dispose()
        $script:LockStream = $null
    }
    if (-not [string]::IsNullOrWhiteSpace([string]$script:LockPath) -and (Test-Path -LiteralPath $script:LockPath -PathType Leaf)) {
        Remove-Item -LiteralPath $script:LockPath -Force -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace([string]$script:StagingRoot) -and
        -not [string]::IsNullOrWhiteSpace([string]$resolvedPreviewRoot) -and
        (Test-PathIsWithin -Parent $resolvedPreviewRoot -Candidate $script:StagingRoot) -and
        (Test-Path -LiteralPath $script:StagingRoot)) {
        Remove-Item -LiteralPath $script:StagingRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace([string]$script:JobRoot) -and
        -not [string]::IsNullOrWhiteSpace([string]$script:ResolvedRuntimeRoot) -and
        (Test-PathIsWithin -Parent $script:ResolvedRuntimeRoot -Candidate $script:JobRoot) -and
        (Test-Path -LiteralPath $script:JobRoot)) {
        Remove-Item -LiteralPath $script:JobRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
