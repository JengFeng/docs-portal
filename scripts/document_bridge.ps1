[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()]
    [string] $SourceRoot = 'C:\Users\gisadmin\我的雲端硬碟\115供水監測',

    [ValidateNotNullOrEmpty()]
    [string] $CacheRoot = 'C:\TWWATER\document-cache',

    [ValidateNotNullOrEmpty()]
    [string] $VersionRoot = 'C:\TWWATER\document-versions',

    [ValidateNotNullOrEmpty()]
    [string] $ImageSourceRoot = 'C:\web\gary\TWWATER\document-library',

    [ValidateNotNullOrEmpty()]
    [string] $ImageArchiveRoot = 'C:\TWWATER\runtime\image-source-archive',

    [ValidateNotNullOrEmpty()]
    [string] $ImageCommandKeyPath = 'C:\TWWATER\runtime\image-source-archive\.keys\image-command.key',

    [ValidateNotNullOrEmpty()]
    [string] $RuntimeRoot = 'C:\TWWATER\runtime\document-bridge',

    [ValidateNotNullOrEmpty()]
    [string] $SessionRoot = 'C:\TWWATER\runtime\sessions',

    [ValidateNotNullOrEmpty()]
    [string] $StagingRoot = 'C:\TWWATER\runtime\pre-upload-previews',

    [ValidateNotNullOrEmpty()]
    [string] $StagedCommitRoot = 'C:\TWWATER\runtime\staged-commit-channel',

    [ValidateNotNullOrEmpty()]
    [string] $OfficialDocumentsRelativeRoot = '網站文件',

    [ValidateNotNullOrEmpty()]
    [string] $EntryUrl = 'https://aiwork.ddns.net/gary/TWWATER/?action=login',

    [string] $SettingsPath = 'C:\web\gary\TWWATER\app\bridge_settings.json',

    [ValidateRange(1, 60)]
    [int] $DebounceSeconds = 3,

    [ValidateRange(30, 86400)]
    [int] $FullReconcileSeconds = 300,

    [ValidateRange(1, 2000)]
    [int] $MaxDocuments = 2000,

    [switch] $EnableCacheFirstWriteback,
    [switch] $Once,
    [switch] $SkipWake,
    [switch] $SkipSignal
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$versionEnginePath = Join-Path $PSScriptRoot 'document_version_engine.ps1'
if (-not (Test-Path -LiteralPath $versionEnginePath -PathType Leaf)) {
    throw 'Document version engine was not found.'
}
. $versionEnginePath

$stagedCommitEnginePath = Join-Path $PSScriptRoot 'staged_commit_engine.ps1'
if (-not (Test-Path -LiteralPath $stagedCommitEnginePath -PathType Leaf)) {
    throw 'Staged commit engine was not found.'
}
. $stagedCommitEnginePath

$script:CacheSentinelName = '.twwater-document-cache.json'
$script:CacheSentinelKind = 'TWWATER_DOCUMENT_CACHE'
$script:CacheSentinelVersion = 1
$script:CacheStateDirectoryName = '.twwater-state'
$script:AllowedExtensions = @('.md', '.txt', '.pdf', '.docx', '.xlsx', '.pptx', '.png', '.jpg', '.jpeg', '.webp', '.gif')
$script:ExcludedDirectoryNames = @(
    '.discord_uploads',
    '.discord_outbox',
    '.git',
    'node_modules',
    'vendor',
    'tmp',
    '_pptx_build_tool',
    '.pptx_build',
    '.transcription_tmp',
    '.npm-cache',
    'maintenance-backups'
)
$script:BridgeStartUtc = [DateTime]::UtcNow
$script:LogPath = $null
$script:HeartbeatPath = $null
$script:LockStream = $null
$script:Mutex = $null
$script:MutexAcquired = $false

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

function Assert-RealDirectory {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Label
    )

    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer) {
        throw "$Label must be a directory."
    }
    if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "$Label must be a real directory, not a reparse point."
    }
    return $item.FullName.TrimEnd('\', '/')
}

function New-RealDirectory {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Label
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        [void][IO.Directory]::CreateDirectory($Path)
    }
    return Assert-RealDirectory -Path $Path -Label $Label
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
        throw 'Atomic-write target must have a parent directory.'
    }
    [void](New-RealDirectory -Path $parent -Label 'Atomic-write parent')

    $temporaryPath = Join-Path $parent ('.document-bridge-write-{0}.tmp' -f [Guid]::NewGuid().ToString('N'))
    $backupPath = Join-Path $parent ('.document-bridge-write-backup-{0}.tmp' -f [Guid]::NewGuid().ToString('N'))
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

function Write-ImmutableText {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Text
    )

    $parent = Split-Path -Parent $Path
    [void](New-RealDirectory -Path $parent -Label 'Immutable result parent')
    $temporaryPath = Join-Path $parent ('.cache-first-result-{0}.tmp' -f [Guid]::NewGuid().ToString('N'))
    try {
        Write-Utf8NoBomFile -Path $temporaryPath -Text $Text
        try {
            [IO.File]::Move($temporaryPath, $Path)
        }
        catch [IO.IOException] {
            if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
                throw
            }
            $existing = [IO.File]::ReadAllText($Path, [Text.Encoding]::UTF8)
            if (-not [string]::Equals($existing, $Text, [StringComparison]::Ordinal)) {
                throw 'Immutable cache-first result already exists with different content.'
            }
        }
    }
    finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Write-BridgeLog {
    param(
        [Parameter(Mandatory)][ValidateSet('info', 'warning', 'error')][string] $Level,
        [Parameter(Mandatory)][string] $Event,
        [string] $Generation = '',
        [hashtable] $Data = @{}
    )

    if ([string]::IsNullOrWhiteSpace([string]$script:LogPath)) {
        return
    }

    $entry = [ordered]@{
        utc = [DateTime]::UtcNow.ToString('o')
        level = $Level
        event = $Event
    }
    if (-not [string]::IsNullOrWhiteSpace($Generation)) {
        $entry['generation'] = $Generation
    }
    foreach ($key in $Data.Keys) {
        # Callers must provide only operational metadata, never file contents or credentials.
        $entry[[string]$key] = $Data[$key]
    }

    $line = ($entry | ConvertTo-Json -Compress -Depth 4)
    $encoding = New-Object Text.UTF8Encoding($false)
    $writer = New-Object IO.StreamWriter($script:LogPath, $true, $encoding)
    try {
        $writer.WriteLine($line)
    }
    finally {
        $writer.Dispose()
    }
}

function Write-Heartbeat {
    param(
        [string] $State = 'running',
        [string] $Generation = ''
    )

    if ([string]::IsNullOrWhiteSpace([string]$script:HeartbeatPath)) {
        return
    }
    $heartbeat = [ordered]@{
        version = 1
        pid = $PID
        user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
        started_utc = $script:BridgeStartUtc.ToString('o')
        heartbeat_utc = [DateTime]::UtcNow.ToString('o')
        state = $State
        generation = $Generation
    }
    Write-AtomicText -Path $script:HeartbeatPath -Text ($heartbeat | ConvertTo-Json -Compress)
}

function New-CacheSentinel {
    param([Parameter(Mandatory)][string] $Root)

    $sentinel = [ordered]@{
        kind = $script:CacheSentinelKind
        version = $script:CacheSentinelVersion
        cache_id = [Guid]::NewGuid().ToString('N')
        cache_root = (Get-NormalizedFullPath -Path $Root)
        created_utc = [DateTime]::UtcNow.ToString('o')
    }
    Write-AtomicText -Path (Join-Path $Root $script:CacheSentinelName) -Text ($sentinel | ConvertTo-Json -Compress)
}

function Assert-CacheSentinel {
    param([Parameter(Mandatory)][string] $Root)

    $sentinelPath = Join-Path $Root $script:CacheSentinelName
    try {
        $sentinelExists = Test-Path -LiteralPath $sentinelPath -PathType Leaf -ErrorAction Stop
    }
    catch {
        throw "Cache sentinel could not be inspected ($($_.Exception.GetType().Name)). Verify the bridge user can read the cache directory."
    }
    if (-not $sentinelExists) {
        throw 'Cache sentinel is missing. Refusing to use an unowned directory.'
    }
    try {
        $sentinelItem = Get-Item -LiteralPath $sentinelPath -Force -ErrorAction Stop
    }
    catch {
        throw "Cache sentinel metadata could not be read ($($_.Exception.GetType().Name)). Verify the bridge user can read the cache directory."
    }
    if ([bool]($sentinelItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Cache sentinel must be a regular file.'
    }
    try {
        $sentinelJson = Get-Content -LiteralPath $sentinelPath -Raw -Encoding UTF8 -ErrorAction Stop
    }
    catch {
        throw "Cache sentinel could not be read ($($_.Exception.GetType().Name)). Verify the bridge user has read access to the cache tree."
    }
    try {
        $sentinel = $sentinelJson | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw "Cache sentinel is not valid JSON ($($_.Exception.GetType().Name)). Its contents were not displayed."
    }
    if ([string]$sentinel.kind -ne $script:CacheSentinelKind -or [int]$sentinel.version -ne $script:CacheSentinelVersion) {
        throw 'Cache sentinel kind or version is invalid.'
    }
    if ([string]::IsNullOrWhiteSpace([string]$sentinel.cache_id) -or -not (Test-PathEquals -First ([string]$sentinel.cache_root) -Second $Root)) {
        throw 'Cache sentinel does not belong to this cache directory.'
    }
}

function Initialize-CacheRoot {
    param([Parameter(Mandatory)][string] $Path)

    $existed = Test-Path -LiteralPath $Path
    if (-not $existed) {
        $parent = Split-Path -Parent (Get-NormalizedFullPath -Path $Path)
        [void](New-RealDirectory -Path $parent -Label 'Cache parent')
        [void][IO.Directory]::CreateDirectory($Path)
    }
    $root = Assert-RealDirectory -Path $Path -Label 'CacheRoot'
    if (-not $existed) {
        New-CacheSentinel -Root $root
    }
    Assert-CacheSentinel -Root $root
    return $root
}

function Get-RelativePathUnderRoot {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $FullPath
    )

    $normalizedRoot = (Get-NormalizedFullPath -Path $Root)
    $normalizedPath = [IO.Path]::GetFullPath($FullPath)
    $prefix = $normalizedRoot + [IO.Path]::DirectorySeparatorChar
    if (-not $normalizedPath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Enumerated path escaped its declared root.'
    }
    return $normalizedPath.Substring($prefix.Length)
}

function Test-ExcludedName {
    param(
        [Parameter(Mandatory)][string] $Name,
        [switch] $Directory
    )

    if ($Name.StartsWith('.', [StringComparison]::Ordinal) -or $Name.StartsWith('~$', [StringComparison]::Ordinal)) {
        return $true
    }
    if ($Directory) {
        foreach ($excluded in $script:ExcludedDirectoryNames) {
            if ([string]::Equals($Name, $excluded, [StringComparison]::OrdinalIgnoreCase)) {
                return $true
            }
        }
    }
    return $false
}

function Test-ExcludedAttributes {
    param([Parameter(Mandatory)][IO.FileAttributes] $Attributes)

    $mask = [IO.FileAttributes]::Hidden -bor [IO.FileAttributes]::System -bor [IO.FileAttributes]::ReparsePoint
    return [bool]($Attributes -band $mask)
}

function Get-SourceInventory {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][int] $Limit
    )

    $inventory = @{}
    $count = 0
    $stack = New-Object 'Collections.Generic.Stack[string]'
    $stack.Push($Root)

    while ($stack.Count -gt 0) {
        $directoryPath = $stack.Pop()
        $directory = New-Object IO.DirectoryInfo($directoryPath)
        $entries = $directory.GetFileSystemInfos()
        foreach ($entry in $entries) {
            $isDirectory = [bool]($entry.Attributes -band [IO.FileAttributes]::Directory)
            if (Test-ExcludedAttributes -Attributes $entry.Attributes) {
                continue
            }
            if (Test-ExcludedName -Name $entry.Name -Directory:$isDirectory) {
                continue
            }
            if ($isDirectory) {
                $stack.Push($entry.FullName)
                continue
            }
            $extension = [IO.Path]::GetExtension($entry.Name).ToLowerInvariant()
            if ($script:AllowedExtensions -notcontains $extension) {
                continue
            }
            $count++
            if ($count -gt $Limit) {
                throw "The source contains more than $Limit allowed documents. Cache mutation was not started."
            }
            $relative = Get-RelativePathUnderRoot -Root $Root -FullPath $entry.FullName
            $inventory[$relative] = [pscustomobject]@{
                RelativePath = $relative
                FullPath = $entry.FullName
                Length = [int64]$entry.Length
                LastWriteTimeUtc = $entry.LastWriteTimeUtc
            }
        }
    }
    return $inventory
}

function Get-CacheInventory {
    param([Parameter(Mandatory)][string] $Root)

    $inventory = @{}
    $stack = New-Object 'Collections.Generic.Stack[string]'
    $stack.Push($Root)
    while ($stack.Count -gt 0) {
        $directoryPath = $stack.Pop()
        $directory = New-Object IO.DirectoryInfo($directoryPath)
        $entries = $directory.GetFileSystemInfos()
        foreach ($entry in $entries) {
            if ([bool]($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
                throw 'Cache contains a reparse point. Refusing to continue.'
            }
            $isDirectory = [bool]($entry.Attributes -band [IO.FileAttributes]::Directory)
            if ($isDirectory) {
                if ([string]::Equals($entry.Name, $script:CacheStateDirectoryName, [StringComparison]::OrdinalIgnoreCase)) {
                    continue
                }
                $stack.Push($entry.FullName)
                continue
            }
            $relative = Get-RelativePathUnderRoot -Root $Root -FullPath $entry.FullName
            if ([string]::Equals($relative, $script:CacheSentinelName, [StringComparison]::OrdinalIgnoreCase)) {
                continue
            }
            $inventory[$relative] = [pscustomobject]@{
                RelativePath = $relative
                FullPath = $entry.FullName
                Length = [int64]$entry.Length
                LastWriteTimeUtc = $entry.LastWriteTimeUtc
            }
        }
    }
    return $inventory
}

function Get-StableFileState {
    param([Parameter(Mandatory)][string] $Path)

    for ($attempt = 1; $attempt -le 6; $attempt++) {
        $first = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
        if ($first.PSIsContainer -or (Test-ExcludedAttributes -Attributes $first.Attributes) -or (Test-ExcludedName -Name $first.Name)) {
            throw 'Source file became ineligible while it was being copied.'
        }
        $firstLength = [int64]$first.Length
        $firstWrite = $first.LastWriteTimeUtc
        Start-Sleep -Milliseconds 250
        $second = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
        if ($firstLength -eq [int64]$second.Length -and $firstWrite -eq $second.LastWriteTimeUtc) {
            return [pscustomobject]@{
                Length = [int64]$second.Length
                LastWriteTimeUtc = $second.LastWriteTimeUtc
            }
        }
    }
    throw 'Source file did not become stable before the copy timeout.'
}

function Get-ValidatedDestinationPath {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $RelativePath
    )

    $candidate = [IO.Path]::GetFullPath((Join-Path $Root $RelativePath))
    $prefix = (Get-NormalizedFullPath -Path $Root) + [IO.Path]::DirectorySeparatorChar
    if (-not $candidate.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Destination path escaped the cache root.'
    }
    return $candidate
}

function Get-CacheFirstDirtyMarkers {
    param(
        [Parameter(Mandatory)][string] $CacheRoot,
        [Parameter(Mandatory)][string] $OfficialRelativeRoot,
        [Parameter(Mandatory)][int] $Limit
    )

    $markers = @{}
    $officialPath = Get-ValidatedDestinationPath -Root $CacheRoot -RelativePath $OfficialRelativeRoot
    $stateRoot = Join-Path $officialPath $script:CacheStateDirectoryName
    if (-not (Test-Path -LiteralPath $stateRoot)) {
        return $markers
    }
    $stateRoot = Assert-RealDirectory -Path $stateRoot -Label 'Cache-first state directory'
    $files = @(Get-ChildItem -LiteralPath $stateRoot -File -Force -ErrorAction Stop)
    if ($files.Count -gt $Limit) {
        throw "The cache-first state directory contains more than $Limit marker files."
    }

    $expectedProperties = @(
        'schema_version','document_id','relative_path','generation','status',
        'base_source_sha256','working_sha256','observed_source_sha256','error_code',
        'not_before_utc','updated_utc'
    )
    $uuidPattern = '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    $hashPattern = '^[0-9a-f]{64}$'
    $officialPrefix = $OfficialRelativeRoot.Replace('\','/').Trim('/') + '/'
    $utcStyles = [Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal

    foreach ($file in $files) {
        if ([bool]($file.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'Cache-first marker must not be a reparse point.'
        }
        if (-not [string]::Equals($file.Extension, '.json', [StringComparison]::OrdinalIgnoreCase) -or $file.Length -lt 2 -or $file.Length -gt 4096) {
            throw 'Cache-first marker file type or size is invalid.'
        }
        $raw = [IO.File]::ReadAllText($file.FullName, [Text.Encoding]::UTF8)
        try {
            $marker = $raw | ConvertFrom-Json -ErrorAction Stop
        }
        catch {
            throw 'Cache-first marker JSON is invalid.'
        }
        if ($null -eq $marker -or $marker -is [Array]) {
            throw 'Cache-first marker must be one JSON object.'
        }
        $actualProperties = @($marker.PSObject.Properties.Name)
        if (@(Compare-Object -ReferenceObject $expectedProperties -DifferenceObject $actualProperties).Count -ne 0) {
            throw 'Cache-first marker properties are invalid.'
        }
        if (($marker.schema_version -isnot [int]) -or [int]$marker.schema_version -ne 1) {
            throw 'Cache-first marker schema version is invalid.'
        }
        foreach ($name in @('document_id','relative_path','generation','status','base_source_sha256','working_sha256','updated_utc')) {
            if ($marker.$name -isnot [string]) {
                throw "Cache-first marker $name must be a string."
            }
        }
        $documentId = ([string]$marker.document_id).ToLowerInvariant()
        $generation = ([string]$marker.generation).ToLowerInvariant()
        if ($documentId -notmatch $uuidPattern -or $generation -notmatch $uuidPattern) {
            throw 'Cache-first marker UUID is invalid.'
        }
        if (-not [string]::Equals($file.Name, $documentId + '.json', [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Cache-first marker filename does not match its document UUID.'
        }
        $relative = [string]$marker.relative_path
        if ($relative.Contains('\') -or -not $relative.StartsWith($officialPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Cache-first marker relative path is invalid.'
        }
        foreach ($segment in $relative.Split('/')) {
            if ([string]::IsNullOrWhiteSpace($segment) -or $segment -eq '.' -or $segment -eq '..' -or $segment.StartsWith('.', [StringComparison]::Ordinal) -or $segment.StartsWith('~$', [StringComparison]::Ordinal)) {
                throw 'Cache-first marker relative path contains an excluded component.'
            }
        }
        $extension = [IO.Path]::GetExtension($relative).ToLowerInvariant()
        if ($script:AllowedExtensions -notcontains $extension) {
            throw 'Cache-first marker extension is not supported.'
        }
        $status = [string]$marker.status
        if (@('pending','conflict','failed') -notcontains $status) {
            throw 'Cache-first marker status is invalid.'
        }
        $baseHash = ([string]$marker.base_source_sha256).ToLowerInvariant()
        $workingHash = ([string]$marker.working_sha256).ToLowerInvariant()
        if ($baseHash -notmatch $hashPattern -or $workingHash -notmatch $hashPattern) {
            throw 'Cache-first marker hash is invalid.'
        }
        foreach ($name in @('updated_utc')) {
            try {
                [void][DateTime]::ParseExact([string]$marker.$name, 'yyyy-MM-ddTHH:mm:ssZ', [Globalization.CultureInfo]::InvariantCulture, $utcStyles)
            }
            catch {
                throw "Cache-first marker $name is invalid."
            }
        }
        $observedHash = $null
        $errorCode = $null
        if ($status -eq 'pending') {
            if ($null -ne $marker.observed_source_sha256 -or $null -ne $marker.error_code) {
                throw 'Pending cache-first marker must not contain conflict or error output.'
            }
            if ($marker.not_before_utc -isnot [string]) {
                throw 'Pending cache-first marker requires not_before_utc.'
            }
            try {
                [void][DateTime]::ParseExact([string]$marker.not_before_utc, 'yyyy-MM-ddTHH:mm:ssZ', [Globalization.CultureInfo]::InvariantCulture, $utcStyles)
            }
            catch {
                throw 'Cache-first marker not_before_utc is invalid.'
            }
        }
        elseif ($status -eq 'failed') {
            if ($null -ne $marker.observed_source_sha256) {
                throw 'Failed cache-first marker must not contain an observed source hash.'
            }
            if ($marker.error_code -isnot [string]) {
                throw 'Failed cache-first marker requires an error code.'
            }
            $errorCode = [string]$marker.error_code
            if ($errorCode -notmatch '^[A-Z][A-Z0-9_]{2,63}$') {
                throw 'Failed cache-first marker error code is invalid.'
            }
            if ($null -ne $marker.not_before_utc) {
                throw 'Failed cache-first marker must not have not_before_utc.'
            }
        }
        else {
            if ($marker.observed_source_sha256 -isnot [string]) {
                throw 'Conflict cache-first marker requires an observed source hash.'
            }
            $observedHash = ([string]$marker.observed_source_sha256).ToLowerInvariant()
            if ($observedHash -notmatch $hashPattern) {
                throw 'Conflict cache-first marker observed source hash is invalid.'
            }
            if (-not [string]::Equals([string]$marker.error_code, 'SOURCE_CHANGED', [StringComparison]::Ordinal)) {
                throw 'Conflict cache-first marker error code is invalid.'
            }
            if ($null -ne $marker.not_before_utc) {
                throw 'Conflict cache-first marker must not have not_before_utc.'
            }
            $errorCode = 'SOURCE_CHANGED'
        }

        $nativeRelative = $relative.Replace('/', [IO.Path]::DirectorySeparatorChar)
        $cachePath = Get-ValidatedDestinationPath -Root $CacheRoot -RelativePath $nativeRelative
        $cacheItem = Get-Item -LiteralPath $cachePath -Force -ErrorAction Stop
        if ($cacheItem.PSIsContainer -or [bool]($cacheItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'Cache-first marker target is not a regular cache file.'
        }
        $actualHash = (Get-FileHash -LiteralPath $cachePath -Algorithm SHA256).Hash.ToLowerInvariant()
        if (-not [string]::Equals($actualHash, $workingHash, [StringComparison]::Ordinal)) {
            throw 'Cache-first marker does not match current cache bytes.'
        }
        if ($markers.ContainsKey($nativeRelative)) {
            throw 'Duplicate cache-first marker relative path.'
        }
        $markers[$nativeRelative] = [pscustomobject]@{
            DocumentId = $documentId
            RelativePath = $nativeRelative
            WireRelativePath = $relative
            Status = $status
            Generation = $generation
            BaseSourceSha256 = $baseHash
            WorkingSha256 = $workingHash
            ObservedSourceSha256 = $observedHash
            ErrorCode = $errorCode
            MarkerPath = $file.FullName
            NotBeforeUtc = if ($null -eq $marker.not_before_utc) { $null } else { [string]$marker.not_before_utc }
        }
    }
    return $markers
}

function Get-LockedFileSha256 {
    param([Parameter(Mandatory)][string] $Path)

    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or [bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Write-back source must be a regular non-reparse file.'
    }
    $stream = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-','').ToLowerInvariant()
    }
    finally {
        $sha.Dispose()
        $stream.Dispose()
    }
}

function New-CacheFirstResultText {
    param(
        [Parameter(Mandatory)] $Marker,
        [Parameter(Mandatory)][ValidateSet('synced','conflict','failed')][string] $Status,
        [AllowNull()] $ObservedSourceSha256,
        [AllowNull()] $ErrorCode,
        [Parameter(Mandatory)][DateTime] $CompletedUtc
    )

    $result = [ordered]@{
        schema_version = 1
        document_id = $Marker.DocumentId
        relative_path = $Marker.WireRelativePath
        generation = $Marker.Generation
        status = $Status
        base_source_sha256 = $Marker.BaseSourceSha256
        working_sha256 = $Marker.WorkingSha256
        observed_source_sha256 = $ObservedSourceSha256
        error_code = $ErrorCode
        not_before_utc = $null
        updated_utc = $CompletedUtc.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    }
    return ($result | ConvertTo-Json -Compress)
}

function Assert-CacheFirstResultMatchesMarker {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)] $Marker
    )

    $file = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($file.PSIsContainer -or [bool]($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $file.Length -lt 2 -or $file.Length -gt 4096) {
        throw 'Existing cache-first result file type or size is invalid.'
    }
    try {
        $result = [IO.File]::ReadAllText($Path, [Text.Encoding]::UTF8) | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw 'Existing cache-first result JSON is invalid.'
    }
    $expectedProperties = @(
        'schema_version','document_id','relative_path','generation','status',
        'base_source_sha256','working_sha256','observed_source_sha256','error_code',
        'not_before_utc','updated_utc'
    )
    if ($null -eq $result -or $result -is [Array]) {
        throw 'Existing cache-first result must be one JSON object.'
    }
    $propertyDifferences = @(Compare-Object -ReferenceObject $expectedProperties -DifferenceObject @($result.PSObject.Properties.Name))
    if ($propertyDifferences.Count -ne 0) {
        throw 'Existing cache-first result properties are invalid.'
    }
    if (($result.schema_version -isnot [int]) -or [int]$result.schema_version -ne 1) {
        throw 'Existing cache-first result schema version is invalid.'
    }
    foreach ($binding in @(
        @('document_id',$Marker.DocumentId),
        @('relative_path',$Marker.WireRelativePath),
        @('generation',$Marker.Generation),
        @('base_source_sha256',$Marker.BaseSourceSha256),
        @('working_sha256',$Marker.WorkingSha256)
    )) {
        $name = [string]$binding[0]
        if ($result.$name -isnot [string]) {
            throw "Existing cache-first result $name binding must be a string."
        }
        if (-not [string]::Equals([string]$result.$name, [string]$binding[1], [StringComparison]::OrdinalIgnoreCase)) {
            throw "Existing cache-first result $name binding is invalid."
        }
    }
    if ($null -ne $result.not_before_utc -or $result.updated_utc -isnot [string]) {
        throw 'Existing cache-first result timing fields are invalid.'
    }
    try {
        [void][DateTime]::ParseExact([string]$result.updated_utc, 'yyyy-MM-ddTHH:mm:ssZ', [Globalization.CultureInfo]::InvariantCulture, ([Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal))
    }
    catch {
        throw 'Existing cache-first result updated_utc is invalid.'
    }
    $status = if ($result.status -is [string]) { [string]$result.status } else { '' }
    if ($status -eq 'synced') {
        if ($result.observed_source_sha256 -isnot [string]) {
            throw 'Existing cache-first synced result requires the final source hash.'
        }
        if (-not [string]::Equals([string]$result.observed_source_sha256, $Marker.WorkingSha256, [StringComparison]::Ordinal)) {
            throw 'Existing cache-first synced result final source hash is invalid.'
        }
        if ($null -ne $result.error_code) {
            throw 'Existing cache-first synced result must not contain an error code.'
        }
    }
    elseif ($status -eq 'conflict') {
        if ($result.observed_source_sha256 -isnot [string]) {
            throw 'Existing cache-first conflict result requires an observed source hash.'
        }
        if (([string]$result.observed_source_sha256) -notmatch '^[0-9a-f]{64}$') {
            throw 'Existing cache-first conflict result observed source hash is invalid.'
        }
        if (-not [string]::Equals([string]$result.error_code, 'SOURCE_CHANGED', [StringComparison]::Ordinal)) {
            throw 'Existing cache-first conflict result error code is invalid.'
        }
    }
    elseif ($status -eq 'failed') {
        if ($null -ne $result.observed_source_sha256) {
            throw 'Existing cache-first failed result must not contain an observed source hash.'
        }
        if ($result.error_code -isnot [string]) {
            throw 'Existing cache-first failed result requires an error code.'
        }
        if (([string]$result.error_code) -notmatch '^[A-Z][A-Z0-9_]{2,63}$') {
            throw 'Existing cache-first failed result error code is invalid.'
        }
    }
    else {
        throw 'Existing cache-first result status is invalid.'
    }
}

function Invoke-CacheFirstDueWritebacks {
    param(
        [Parameter(Mandatory)][string] $SourceRoot,
        [Parameter(Mandatory)][string] $CacheRoot,
        [Parameter(Mandatory)][string] $VersionRoot,
        [Parameter(Mandatory)][string] $RuntimeRoot,
        [Parameter(Mandatory)][hashtable] $Markers,
        [Parameter(Mandatory)][DateTime] $NowUtc
    )

    $created = 0
    foreach ($marker in @($Markers.Values)) {
        if ($marker.Status -ne 'pending') {
            continue
        }
        $notBefore = [DateTime]::ParseExact($marker.NotBeforeUtc, 'yyyy-MM-ddTHH:mm:ssZ', [Globalization.CultureInfo]::InvariantCulture, ([Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal))
        if ($notBefore -gt $NowUtc.ToUniversalTime()) {
            continue
        }
        $stateRoot = Split-Path -Parent $marker.MarkerPath
        $resultRoot = Join-Path $stateRoot 'results'
        $resultPath = Join-Path $resultRoot ($marker.Generation + '.json')
        $operationsRoot = Join-Path $RuntimeRoot 'cache-first-writeback'
        $operationRoot = Join-Path $operationsRoot $marker.Generation
        if (Test-Path -LiteralPath $resultPath) {
            Assert-CacheFirstResultMatchesMarker -Path $resultPath -Marker $marker
            if (Test-Path -LiteralPath $operationRoot) {
                [void](Assert-RealDirectory -Path $operationRoot -Label 'Completed cache-first operation directory')
                Remove-Item -LiteralPath $operationRoot -Recurse -Force -ErrorAction Stop
            }
            continue
        }

        $sourcePath = Get-ValidatedDestinationPath -Root $SourceRoot -RelativePath $marker.RelativePath
        if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
            $text = New-CacheFirstResultText -Marker $marker -Status 'failed' -ObservedSourceSha256 $null -ErrorCode 'SOURCE_MISSING' -CompletedUtc $NowUtc
            Write-ImmutableText -Path $resultPath -Text $text
            Write-BridgeLog -Level warning -Event 'cache_first_writeback_failed' -Generation $marker.Generation -Data @{ error_code = 'SOURCE_MISSING' }
            $created++
            continue
        }
        $observedHash = Get-LockedFileSha256 -Path $sourcePath
        $extension = [IO.Path]::GetExtension($sourcePath).TrimStart('.').ToLowerInvariant()
        $archiveRelative = Join-Path (Join-Path $marker.DocumentId $marker.BaseSourceSha256) ('source.' + $extension)
        $archivePath = Get-ValidatedDestinationPath -Root $VersionRoot -RelativePath $archiveRelative

        if ([string]::Equals($observedHash, $marker.WorkingSha256, [StringComparison]::Ordinal)) {
            if (-not (Test-Path -LiteralPath $archivePath -PathType Leaf) -or (Get-LockedFileSha256 -Path $archivePath) -ne $marker.BaseSourceSha256) {
                $text = New-CacheFirstResultText -Marker $marker -Status 'failed' -ObservedSourceSha256 $null -ErrorCode 'ARCHIVE_EVIDENCE_MISSING' -CompletedUtc $NowUtc
                Write-ImmutableText -Path $resultPath -Text $text
                Write-BridgeLog -Level error -Event 'cache_first_writeback_failed' -Generation $marker.Generation -Data @{ error_code = 'ARCHIVE_EVIDENCE_MISSING' }
                $created++
                continue
            }
            $text = New-CacheFirstResultText -Marker $marker -Status 'synced' -ObservedSourceSha256 $observedHash -ErrorCode $null -CompletedUtc $NowUtc
            Write-ImmutableText -Path $resultPath -Text $text
            if (Test-Path -LiteralPath $operationRoot) {
                [void](Assert-RealDirectory -Path $operationRoot -Label 'Recovered cache-first operation directory')
                Remove-Item -LiteralPath $operationRoot -Recurse -Force -ErrorAction Stop
            }
            $created++
            continue
        }
        if (-not [string]::Equals($observedHash, $marker.BaseSourceSha256, [StringComparison]::Ordinal)) {
            $text = New-CacheFirstResultText -Marker $marker -Status 'conflict' -ObservedSourceSha256 $observedHash -ErrorCode 'SOURCE_CHANGED' -CompletedUtc $NowUtc
            Write-ImmutableText -Path $resultPath -Text $text
            Write-BridgeLog -Level warning -Event 'cache_first_writeback_conflict' -Generation $marker.Generation -Data @{ error_code = 'SOURCE_CHANGED' }
            $created++
            continue
        }

        $archive = Save-TWWaterArchivedVersion -SourcePath $sourcePath -VersionRoot $VersionRoot -DocumentPublicId $marker.DocumentId -RelativePath $marker.RelativePath -Reason 'publish' -ActorReference ('cache-first-bridge:' + $marker.Generation)
        if (-not [string]::Equals([string]$archive.Hash, $marker.BaseSourceSha256, [StringComparison]::Ordinal)) {
            throw 'Archived source hash no longer matches the write-back base.'
        }
        [void](New-RealDirectory -Path $operationsRoot -Label 'Cache-first write-back operations root')
        [void](New-RealDirectory -Path $operationRoot -Label 'Cache-first write-back operation directory')
        $cachePath = Get-ValidatedDestinationPath -Root $CacheRoot -RelativePath $marker.RelativePath
        $candidatePath = Join-Path $operationRoot 'candidate.tmp'
        $rollbackPath = Join-Path $operationRoot 'rollback.tmp'
        $displacedPath = Join-Path $operationRoot 'displaced.tmp'
        if (-not (Test-Path -LiteralPath $candidatePath -PathType Leaf)) {
            [IO.File]::Copy($cachePath, $candidatePath, $false)
        }
        if ((Get-LockedFileSha256 -Path $candidatePath) -ne $marker.WorkingSha256) {
            throw 'Write-back candidate no longer matches the working hash.'
        }
        if (Test-Path -LiteralPath $rollbackPath -PathType Leaf) {
            if ((Get-LockedFileSha256 -Path $rollbackPath) -ne $marker.BaseSourceSha256) {
                throw 'Stale write-back rollback file is inconsistent.'
            }
            Remove-Item -LiteralPath $rollbackPath -Force -ErrorAction Stop
        }
        [IO.File]::Replace($candidatePath, $sourcePath, $rollbackPath, $true)
        $replacedSourceHash = Get-LockedFileSha256 -Path $rollbackPath
        if (-not [string]::Equals($replacedSourceHash, $marker.BaseSourceSha256, [StringComparison]::Ordinal)) {
            if (Test-Path -LiteralPath $displacedPath) {
                Remove-Item -LiteralPath $displacedPath -Force -ErrorAction Stop
            }
            [IO.File]::Replace($rollbackPath, $sourcePath, $displacedPath, $true)
            if ((Get-LockedFileSha256 -Path $sourcePath) -ne $replacedSourceHash) {
                throw 'Write-back race rollback verification failed.'
            }
            if (Test-Path -LiteralPath $displacedPath) {
                Remove-Item -LiteralPath $displacedPath -Force -ErrorAction Stop
            }
            $text = New-CacheFirstResultText -Marker $marker -Status 'conflict' -ObservedSourceSha256 $replacedSourceHash -ErrorCode 'SOURCE_CHANGED' -CompletedUtc $NowUtc
            Write-ImmutableText -Path $resultPath -Text $text
            Remove-Item -LiteralPath $operationRoot -Recurse -Force -ErrorAction Stop
            $created++
            continue
        }
        $finalSourceHash = Get-LockedFileSha256 -Path $sourcePath
        if (-not [string]::Equals($finalSourceHash, $marker.WorkingSha256, [StringComparison]::Ordinal)) {
            $text = New-CacheFirstResultText -Marker $marker -Status 'conflict' -ObservedSourceSha256 $finalSourceHash -ErrorCode 'SOURCE_CHANGED' -CompletedUtc $NowUtc
            Write-ImmutableText -Path $resultPath -Text $text
            $created++
            continue
        }
        $text = New-CacheFirstResultText -Marker $marker -Status 'synced' -ObservedSourceSha256 $finalSourceHash -ErrorCode $null -CompletedUtc $NowUtc
        Write-ImmutableText -Path $resultPath -Text $text
        Remove-Item -LiteralPath $operationRoot -Recurse -Force -ErrorAction Stop
        Write-BridgeLog -Level info -Event 'cache_first_writeback_succeeded' -Generation $marker.Generation -Data @{ previous_hash = $marker.BaseSourceSha256; current_hash = $finalSourceHash }
        $created++
    }
    return $created
}

function New-QuarantinePath {
    param(
        [Parameter(Mandatory)][string] $QuarantineRoot,
        [Parameter(Mandatory)][string] $RelativePath
    )

    $candidate = Get-ValidatedDestinationPath -Root $QuarantineRoot -RelativePath $RelativePath
    $parent = Split-Path -Parent $candidate
    [void](New-RealDirectory -Path $parent -Label 'Quarantine directory')
    if (Test-Path -LiteralPath $candidate) {
        $leaf = [IO.Path]::GetFileName($candidate)
        $candidate = Join-Path $parent ('{0}.{1}.quarantined' -f $leaf, [Guid]::NewGuid().ToString('N'))
    }
    return $candidate
}

function Copy-StableFileAtomic {
    param(
        [Parameter(Mandatory)][string] $SourcePath,
        [Parameter(Mandatory)][string] $DestinationPath,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $QuarantineRoot
    )

    $stableBefore = Get-StableFileState -Path $SourcePath
    $destinationParent = Split-Path -Parent $DestinationPath
    [void](New-RealDirectory -Path $destinationParent -Label 'Cache destination directory')
    $temporaryPath = Join-Path $destinationParent ('.document-bridge-copy-{0}.tmp' -f [Guid]::NewGuid().ToString('N'))
    try {
        [IO.File]::Copy($SourcePath, $temporaryPath, $false)
        $stableAfter = Get-StableFileState -Path $SourcePath
        $temporary = Get-Item -LiteralPath $temporaryPath -Force -ErrorAction Stop
        if ($stableBefore.Length -ne $stableAfter.Length -or
            $stableBefore.LastWriteTimeUtc -ne $stableAfter.LastWriteTimeUtc -or
            [int64]$temporary.Length -ne $stableAfter.Length) {
            throw 'Source file changed during copy; this batch will be retried.'
        }
        $temporary.LastWriteTimeUtc = $stableAfter.LastWriteTimeUtc

        if (Test-Path -LiteralPath $DestinationPath -PathType Leaf) {
            $quarantinePath = New-QuarantinePath -QuarantineRoot $QuarantineRoot -RelativePath $RelativePath
            [IO.File]::Replace($temporaryPath, $DestinationPath, $quarantinePath, $true)
        }
        elseif (Test-Path -LiteralPath $DestinationPath) {
            throw 'A non-file object blocks a cache destination path.'
        }
        else {
            [IO.File]::Move($temporaryPath, $DestinationPath)
        }
    }
    finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Move-FileToQuarantine {
    param(
        [Parameter(Mandatory)][string] $FilePath,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $QuarantineRoot
    )

    $quarantinePath = New-QuarantinePath -QuarantineRoot $QuarantineRoot -RelativePath $RelativePath
    [IO.File]::Move($FilePath, $quarantinePath)
}

function Get-EffectiveFullReconcileSeconds {
    param(
        [Parameter(Mandatory)][int] $FallbackSeconds
    )

    try {
        if ([string]::IsNullOrWhiteSpace($SettingsPath) -or -not (Test-Path -LiteralPath $SettingsPath -PathType Leaf)) {
            return $FallbackSeconds
        }
        $item = Get-Item -LiteralPath $SettingsPath -Force -ErrorAction Stop
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or $item.Length -gt 1024) {
            return $FallbackSeconds
        }
        $settings = Get-Content -LiteralPath $SettingsPath -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        if ($null -eq $settings -or $null -eq $settings.full_reconcile_seconds) {
            return $FallbackSeconds
        }
        $seconds = 0
        if ((-not [int]::TryParse([string]$settings.full_reconcile_seconds, [ref]$seconds)) -or
            $seconds -lt 30 -or $seconds -gt 3600) {
            return $FallbackSeconds
        }
        return $seconds
    }
    catch {
        return $FallbackSeconds
    }
}

function Invoke-PortalWake {
    if ($SkipWake) {
        return
    }
    try {
        $response = Invoke-WebRequest -Uri $EntryUrl -UseBasicParsing -Method Get -TimeoutSec 20
        Write-BridgeLog -Level info -Event 'portal_wake' -Data @{ status = [int]$response.StatusCode }
    }
    catch {
        Write-BridgeLog -Level warning -Event 'portal_wake_failed' -Data @{
            exception = $_.Exception.GetType().FullName
            hresult = $_.Exception.HResult
        }
    }
}

function Invoke-BridgeReconcile {
    param([Parameter(Mandatory)][ValidateSet('initial', 'watcher', 'periodic', 'watcher_error')][string] $Reason)

    $generation = [Guid]::NewGuid().ToString('N')
    $syncingPath = Join-Path $SessionRoot 'document-bridge.syncing'
    $pendingPath = Join-Path $SessionRoot 'document-bridge.pending'
    Write-AtomicText -Path $syncingPath -Text ($generation + "`n")
    Write-Heartbeat -State 'reconciling' -Generation $generation
    Write-BridgeLog -Level info -Event 'reconcile_started' -Generation $generation -Data @{ reason = $Reason }
    $cacheMutationStarted = $false

    try {
        # The complete source inventory is built before cache mutation. Any source
        # enumeration failure therefore leaves cached documents untouched.
        $sourceInventory = Get-SourceInventory -Root $SourceRoot -Limit $MaxDocuments
        Assert-CacheSentinel -Root $CacheRoot
        $dirtyMarkers = @{}
        $writebackResults = 0
        if ($EnableCacheFirstWriteback) {
            $dirtyMarkers = Get-CacheFirstDirtyMarkers -CacheRoot $CacheRoot -OfficialRelativeRoot $OfficialDocumentsRelativeRoot -Limit $MaxDocuments
            $writebackResults = Invoke-CacheFirstDueWritebacks -SourceRoot $SourceRoot -CacheRoot $CacheRoot -VersionRoot $VersionRoot -RuntimeRoot $RuntimeRoot -Markers $dirtyMarkers -NowUtc ([DateTime]::UtcNow)
        }
        if ($writebackResults -gt 0) {
            Write-BridgeLog -Level info -Event 'cache_first_writeback_results_created' -Generation $generation -Data @{ count = $writebackResults }
        }
        $cacheInventory = Get-CacheInventory -Root $CacheRoot
        $batchQuarantineRoot = Join-Path (Join-Path $RuntimeRoot 'quarantine') $generation
        $copied = 0
        $unchanged = 0
        $quarantined = 0
        $protected = 0

        foreach ($relativePath in @($sourceInventory.Keys | Sort-Object)) {
            $source = $sourceInventory[$relativePath]
            if ($dirtyMarkers.ContainsKey($relativePath)) {
                if (-not $cacheInventory.ContainsKey($relativePath)) {
                    throw 'Cache-first marker target disappeared after validation.'
                }
                $protected++
                [void]$cacheInventory.Remove($relativePath)
                Write-Heartbeat -State 'reconciling' -Generation $generation
                continue
            }
            $destination = Get-ValidatedDestinationPath -Root $CacheRoot -RelativePath $relativePath
            $needsCopy = $true
            if ($cacheInventory.ContainsKey($relativePath)) {
                $cached = $cacheInventory[$relativePath]
                if ($cached.Length -eq $source.Length -and $cached.LastWriteTimeUtc -eq $source.LastWriteTimeUtc) {
                    $needsCopy = $false
                }
            }
            if ($needsCopy) {
                $cacheMutationStarted = $true
                Copy-StableFileAtomic -SourcePath $source.FullPath -DestinationPath $destination -RelativePath $relativePath -QuarantineRoot $batchQuarantineRoot
                $copied++
            }
            else {
                $unchanged++
            }
            [void]$cacheInventory.Remove($relativePath)
            Write-Heartbeat -State 'reconciling' -Generation $generation
        }

        foreach ($relativePath in @($cacheInventory.Keys | Sort-Object)) {
            if ($dirtyMarkers.ContainsKey($relativePath)) {
                $protected++
                continue
            }
            $cached = $cacheInventory[$relativePath]
            if (Test-Path -LiteralPath $cached.FullPath -PathType Leaf) {
                $cacheMutationStarted = $true
                Move-FileToQuarantine -FilePath $cached.FullPath -RelativePath $relativePath -QuarantineRoot $batchQuarantineRoot
                $quarantined++
            }
        }

        # Both marker files contain only a short, non-executable ASCII generation.
        if (-not $SkipSignal) {
            Write-AtomicText -Path $pendingPath -Text ($generation + "`n")
        }
        Remove-Item -LiteralPath $syncingPath -Force -ErrorAction Stop
        Write-Heartbeat -State 'idle' -Generation $generation
        Write-BridgeLog -Level info -Event 'reconcile_succeeded' -Generation $generation -Data @{
            source_files = $sourceInventory.Count
            copied = $copied
            unchanged = $unchanged
            quarantined = $quarantined
            protected = $protected
        }
        if (-not $SkipSignal) {
            Invoke-PortalWake
        }
    }
    catch {
        # If the source could not even be inventoried, the prior cache is still
        # coherent and may remain readable. Once cache mutation begins, retain
        # the marker so the portal cannot expose a partially reconciled batch.
        if (-not $cacheMutationStarted) {
            try {
                if (Test-Path -LiteralPath $syncingPath -PathType Leaf) {
                    Remove-Item -LiteralPath $syncingPath -Force -ErrorAction Stop
                }
            }
            catch {
                Write-BridgeLog -Level warning -Event 'syncing_marker_cleanup_failed' -Generation $generation
            }
        }
        Write-Heartbeat -State 'failed' -Generation $generation
        Write-BridgeLog -Level error -Event 'reconcile_failed' -Generation $generation -Data @{
            exception = $_.Exception.GetType().FullName
            hresult = $_.Exception.HResult
        }
        throw
    }
}

function Get-MutexName {
    param([Parameter(Mandatory)][string] $Value)

    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($Value.ToLowerInvariant())
        $hash = $sha.ComputeHash($bytes)
        return 'Local\TWWATER_DocumentBridge_{0}' -f (([BitConverter]::ToString($hash)).Replace('-', '').Substring(0, 24))
    }
    finally {
        $sha.Dispose()
    }
}

function Enter-SingleProcessLock {
    $mutexName = Get-MutexName -Value $CacheRoot
    $script:Mutex = New-Object Threading.Mutex($false, $mutexName)
    try {
        $script:MutexAcquired = $script:Mutex.WaitOne(0)
    }
    catch [Threading.AbandonedMutexException] {
        $script:MutexAcquired = $true
    }
    if (-not $script:MutexAcquired) {
        throw 'Another document-bridge process already owns this cache.'
    }

    $lockPath = Join-Path $RuntimeRoot 'document-bridge.lock'
    try {
        $script:LockStream = New-Object IO.FileStream($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
        $script:LockStream.SetLength(0)
        $lockText = ('pid={0};started_utc={1}' -f $PID, $script:BridgeStartUtc.ToString('o'))
        $lockBytes = [Text.Encoding]::ASCII.GetBytes($lockText)
        $script:LockStream.Write($lockBytes, 0, $lockBytes.Length)
        $script:LockStream.Flush($true)
    }
    catch {
        if ($script:MutexAcquired) {
            $script:Mutex.ReleaseMutex()
            $script:MutexAcquired = $false
        }
        throw 'Another document-bridge process already owns the runtime lock.'
    }
}

$SourceRoot = Assert-RealDirectory -Path $SourceRoot -Label 'SourceRoot'
$RuntimeRoot = New-RealDirectory -Path $RuntimeRoot -Label 'RuntimeRoot'
$SessionRoot = New-RealDirectory -Path $SessionRoot -Label 'SessionRoot'
$StagingRoot = New-RealDirectory -Path $StagingRoot -Label 'StagingRoot'
$StagedCommitRoot = New-RealDirectory -Path $StagedCommitRoot -Label 'StagedCommitRoot'
$null = New-RealDirectory -Path (Join-Path $StagedCommitRoot 'requests') -Label 'StagedCommitRequestRoot'
$null = New-RealDirectory -Path (Join-Path $StagedCommitRoot 'results') -Label 'StagedCommitResultRoot'
$VersionRoot = New-RealDirectory -Path $VersionRoot -Label 'VersionRoot'
$CacheRoot = Initialize-CacheRoot -Path $CacheRoot

foreach ($pair in @(
    [pscustomobject]@{ First = $SourceRoot; Second = $VersionRoot },
    [pscustomobject]@{ First = $CacheRoot; Second = $VersionRoot },
    [pscustomobject]@{ First = $RuntimeRoot; Second = $VersionRoot },
    [pscustomobject]@{ First = $SessionRoot; Second = $VersionRoot }
)) {
    if ($(Test-PathEquals -First $pair.First -Second $pair.Second) -or $(Test-PathIsWithin -Parent $pair.First -Candidate $pair.Second) -or $(Test-PathIsWithin -Parent $pair.Second -Candidate $pair.First)) {
        throw 'VersionRoot must be separate from source, cache, runtime, and session roots.'
    }
}

foreach ($pair in @(
    [pscustomobject]@{ First = $SourceRoot; Second = $StagingRoot },
    [pscustomobject]@{ First = $CacheRoot; Second = $StagingRoot },
    [pscustomobject]@{ First = $RuntimeRoot; Second = $StagingRoot },
    [pscustomobject]@{ First = $SessionRoot; Second = $StagingRoot },
    [pscustomobject]@{ First = $VersionRoot; Second = $StagingRoot }
)) {
    if ($(Test-PathEquals -First $pair.First -Second $pair.Second) -or $(Test-PathIsWithin -Parent $pair.First -Candidate $pair.Second) -or $(Test-PathIsWithin -Parent $pair.Second -Candidate $pair.First)) {
        throw 'StagingRoot must be separate from source, cache, bridge runtime, session, and version roots.'
    }
}

foreach ($pair in @(
    [pscustomobject]@{ First = $SourceRoot; Second = $StagedCommitRoot },
    [pscustomobject]@{ First = $CacheRoot; Second = $StagedCommitRoot },
    [pscustomobject]@{ First = $RuntimeRoot; Second = $StagedCommitRoot },
    [pscustomobject]@{ First = $SessionRoot; Second = $StagedCommitRoot },
    [pscustomobject]@{ First = $VersionRoot; Second = $StagedCommitRoot },
    [pscustomobject]@{ First = $StagingRoot; Second = $StagedCommitRoot }
)) {
    if ($(Test-PathEquals -First $pair.First -Second $pair.Second) -or $(Test-PathIsWithin -Parent $pair.First -Candidate $pair.Second) -or $(Test-PathIsWithin -Parent $pair.Second -Candidate $pair.First)) {
        throw 'StagedCommitRoot must be separate from all other bridge roots.'
    }
}

if ($(Test-PathEquals -First $SourceRoot -Second $CacheRoot) -or $(Test-PathIsWithin -Parent $SourceRoot -Candidate $CacheRoot) -or $(Test-PathIsWithin -Parent $CacheRoot -Candidate $SourceRoot)) {
    throw 'SourceRoot and CacheRoot must be separate, non-nested directories.'
}
if (-not [string]::Equals([IO.Path]::GetPathRoot($CacheRoot), [IO.Path]::GetPathRoot($RuntimeRoot), [StringComparison]::OrdinalIgnoreCase)) {
    throw 'CacheRoot and RuntimeRoot must be on the same volume for atomic quarantine operations.'
}
if ($EnableCacheFirstWriteback -and -not [string]::Equals([IO.Path]::GetPathRoot($SourceRoot), [IO.Path]::GetPathRoot($RuntimeRoot), [StringComparison]::OrdinalIgnoreCase)) {
    throw 'SourceRoot and RuntimeRoot must be on the same volume for atomic cache-first write-back.'
}

$script:LogPath = Join-Path $RuntimeRoot 'document-bridge.jsonl'
$script:HeartbeatPath = Join-Path $RuntimeRoot 'document-bridge.heartbeat.json'

try {
    Enter-SingleProcessLock
    Write-Heartbeat -State 'starting'
    Write-BridgeLog -Level info -Event 'bridge_started' -Data @{ once = [bool]$Once }
    $initialImagePublishRepairs=Repair-TWWaterPreparedImagePublishes -ImageSourceRoot $ImageSourceRoot -ImageArchiveRoot $ImageArchiveRoot -SessionRoot $SessionRoot
    if($initialImagePublishRepairs-gt0){Write-BridgeLog -Level warning -Event 'image_publish_repairs_completed' -Data @{count=$initialImagePublishRepairs}}
    $initialVersionRequests = Invoke-TWWaterDocumentVersionRequests -SourceRoot $SourceRoot -VersionRoot $VersionRoot -ImageSourceRoot $ImageSourceRoot -ImageArchiveRoot $ImageArchiveRoot -ImageCommandKeyPath $ImageCommandKeyPath -SessionRoot $SessionRoot
    if ($initialVersionRequests -gt 0) {
        Write-BridgeLog -Level info -Event 'version_requests_processed' -Data @{ count = $initialVersionRequests }
    }
    $initialStagedCommits = Invoke-TWWaterStagedCommitRequests -SourceRoot $SourceRoot -StagingRoot $StagingRoot -CommitRoot $StagedCommitRoot -VersionRoot $VersionRoot -AllowedRelativeRoot $OfficialDocumentsRelativeRoot
    if ($initialStagedCommits -gt 0) {
        Write-BridgeLog -Level info -Event 'staged_commits_processed' -Data @{ count = $initialStagedCommits }
    }
    Invoke-BridgeReconcile -Reason 'initial'

    if (-not $Once) {
        $watcher = New-Object IO.FileSystemWatcher
        $subscriptions = @()
        $eventPrefix = 'TWWATER.DocumentBridge.{0}' -f [Guid]::NewGuid().ToString('N')
        try {
            $watcher.Path = $SourceRoot
            $watcher.IncludeSubdirectories = $true
            $watcher.NotifyFilter = [IO.NotifyFilters]::FileName -bor [IO.NotifyFilters]::DirectoryName -bor [IO.NotifyFilters]::LastWrite -bor [IO.NotifyFilters]::Size -bor [IO.NotifyFilters]::Attributes
            $watcher.InternalBufferSize = 32768
            foreach ($eventName in @('Changed', 'Created', 'Deleted', 'Renamed')) {
                $subscriptions += Register-ObjectEvent -InputObject $watcher -EventName $eventName -SourceIdentifier ($eventPrefix + '.' + $eventName)
            }
            $subscriptions += Register-ObjectEvent -InputObject $watcher -EventName 'Error' -SourceIdentifier ($eventPrefix + '.Error')
            $watcher.EnableRaisingEvents = $true

            $dirty = $false
            $dirtySince = [DateTime]::UtcNow
            $forceFull = $false
            $lastFull = [DateTime]::UtcNow
            $lastHeartbeat = [DateTime]::UtcNow
            $lastVersionPoll = [DateTime]::UtcNow.AddSeconds(-3)
            $lastSettingsPoll = [DateTime]::UtcNow.AddSeconds(-3)
            $effectiveFullReconcileSeconds = Get-EffectiveFullReconcileSeconds -FallbackSeconds $FullReconcileSeconds
            Write-BridgeLog -Level info -Event 'bridge_setting_changed' -Data @{ full_reconcile_seconds = $effectiveFullReconcileSeconds; source = 'startup' }

            while ($true) {
                Start-Sleep -Milliseconds 500
                $events = @(Get-Event | Where-Object { $_.SourceIdentifier.StartsWith($eventPrefix + '.', [StringComparison]::Ordinal) })
                foreach ($event in $events) {
                    if ($event.SourceIdentifier.EndsWith('.Error', [StringComparison]::Ordinal)) {
                        $forceFull = $true
                        Write-BridgeLog -Level warning -Event 'watcher_buffer_error'
                    }
                    $dirty = $true
                    $dirtySince = [DateTime]::UtcNow
                    Remove-Event -EventIdentifier $event.EventIdentifier -ErrorAction SilentlyContinue
                }

                $now = [DateTime]::UtcNow
                if (($now - $lastSettingsPoll).TotalSeconds -ge 2) {
                    $configuredSeconds = Get-EffectiveFullReconcileSeconds -FallbackSeconds $effectiveFullReconcileSeconds
                    if ($configuredSeconds -ne $effectiveFullReconcileSeconds) {
                        $effectiveFullReconcileSeconds = $configuredSeconds
                        Write-BridgeLog -Level info -Event 'bridge_setting_changed' -Data @{ full_reconcile_seconds = $effectiveFullReconcileSeconds; source = 'runtime' }
                    }
                    $lastSettingsPoll = $now
                }
                if (($now - $lastVersionPoll).TotalSeconds -ge 2) {
                    try {
                        $imagePublishRepairs=Repair-TWWaterPreparedImagePublishes -ImageSourceRoot $ImageSourceRoot -ImageArchiveRoot $ImageArchiveRoot -SessionRoot $SessionRoot
                        if($imagePublishRepairs-gt0){Write-BridgeLog -Level warning -Event 'image_publish_repairs_completed' -Data @{count=$imagePublishRepairs};$dirty=$true;$dirtySince=$now.AddSeconds(-$DebounceSeconds)}
                        $stagedCommitsProcessed = Invoke-TWWaterStagedCommitRequests -SourceRoot $SourceRoot -StagingRoot $StagingRoot -CommitRoot $StagedCommitRoot -VersionRoot $VersionRoot -AllowedRelativeRoot $OfficialDocumentsRelativeRoot
                        if ($stagedCommitsProcessed -gt 0) {
                            Write-BridgeLog -Level info -Event 'staged_commits_processed' -Data @{ count = $stagedCommitsProcessed }
                            $dirty = $true
                            $dirtySince = $now.AddSeconds(-$DebounceSeconds)
                        }
                        $versionRequestsProcessed = Invoke-TWWaterDocumentVersionRequests -SourceRoot $SourceRoot -VersionRoot $VersionRoot -ImageSourceRoot $ImageSourceRoot -ImageArchiveRoot $ImageArchiveRoot -ImageCommandKeyPath $ImageCommandKeyPath -SessionRoot $SessionRoot
                        if ($versionRequestsProcessed -gt 0) {
                            Write-BridgeLog -Level info -Event 'version_requests_processed' -Data @{ count = $versionRequestsProcessed }
                            $dirty = $true
                            $dirtySince = $now.AddSeconds(-$DebounceSeconds)
                        }
                    }
                    catch {
                        Write-BridgeLog -Level warning -Event 'privileged_request_poll_failed' -Data @{ exception = $_.Exception.GetType().FullName; hresult = $_.Exception.HResult }
                    }
                    $lastVersionPoll = $now
                }
                $periodicDue = ($now - $lastFull).TotalSeconds -ge $effectiveFullReconcileSeconds
                $debounced = $dirty -and (($now - $dirtySince).TotalSeconds -ge $DebounceSeconds)
                if ($forceFull -or $periodicDue -or $debounced) {
                    $reason = if ($forceFull) { 'watcher_error' } elseif ($periodicDue) { 'periodic' } else { 'watcher' }
                    Invoke-BridgeReconcile -Reason $reason
                    $dirty = $false
                    $forceFull = $false
                    $lastFull = [DateTime]::UtcNow
                }
                if (($now - $lastHeartbeat).TotalSeconds -ge 15) {
                    Write-Heartbeat -State 'idle'
                    $lastHeartbeat = $now
                }
            }
        }
        finally {
            if ($null -ne $watcher) {
                $watcher.EnableRaisingEvents = $false
            }
            foreach ($subscription in $subscriptions) {
                Unregister-Event -SubscriptionId $subscription.Id -ErrorAction SilentlyContinue
            }
            Get-Event | Where-Object { $_.SourceIdentifier.StartsWith($eventPrefix + '.', [StringComparison]::Ordinal) } | Remove-Event -ErrorAction SilentlyContinue
            if ($null -ne $watcher) {
                $watcher.Dispose()
            }
        }
    }
}
catch {
    Write-BridgeLog -Level error -Event 'bridge_stopped_on_error' -Data @{
        exception = $_.Exception.GetType().FullName
        hresult = $_.Exception.HResult
    }
    throw
}
finally {
    if ($null -ne $script:LockStream) {
        $script:LockStream.Dispose()
        $script:LockStream = $null
    }
    if ($script:MutexAcquired -and $null -ne $script:Mutex) {
        $script:Mutex.ReleaseMutex()
        $script:MutexAcquired = $false
    }
    if ($null -ne $script:Mutex) {
        $script:Mutex.Dispose()
        $script:Mutex = $null
    }
}
