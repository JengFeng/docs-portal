Set-StrictMode -Version Latest

function Get-TWWaterNormalizedRoot {
    param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or [bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "$Label must be a real directory."
    }
    return [IO.Path]::GetFullPath($item.FullName).TrimEnd('\', '/')
}

function Test-TWWaterUuid {
    param([string] $Value)
    return $Value -match '\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}\z'
}

function Test-TWWaterSha256 {
    param([string] $Value)
    return $Value -match '\A[0-9a-fA-F]{64}\z'
}

function Get-TWWaterHash {
    param([Parameter(Mandatory)][string] $Path)
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
}

function Get-TWWaterPublishRequestHmac {
    param([Parameter(Mandatory)]$Request,[Parameter(Mandatory)][byte[]]$Key)
    if($Key.Length -lt 32){throw 'PUBLISH_REQUEST_KEY_INVALID'}
    $unsigned=[ordered]@{};foreach($property in @($Request.PSObject.Properties|Where-Object{$_.Name -ne 'request_hmac'}|Sort-Object Name)){$unsigned[$property.Name]=$property.Value}
    $json=$unsigned|ConvertTo-Json -Compress -Depth 8
    $derive=[Security.Cryptography.HMACSHA256]::new($Key);try{$derived=$derive.ComputeHash([Text.Encoding]::UTF8.GetBytes('TWWATER-document-version-publish-v1'))}finally{$derive.Dispose()}
    $mac=[Security.Cryptography.HMACSHA256]::new($derived);try{return ([BitConverter]::ToString($mac.ComputeHash([Text.Encoding]::UTF8.GetBytes($json)))).Replace('-','').ToLowerInvariant()}finally{$mac.Dispose()}
}

function Assert-TWWaterRestorableImage {
    param([Parameter(Mandatory)][string]$Path,[Parameter(Mandatory)][string]$Extension)
    $expected=$Extension.ToLowerInvariant();if($expected-eq'jpeg'){$expected='jpg'}
    if($expected-in@('gif','webp')){throw 'IMAGE_FORMAT_UNSUPPORTED'}
    if($expected-notin@('png','jpg')){throw 'IMAGE_FORMAT_MISMATCH'}
    $bytes=New-Object byte[] 12;$stream=[IO.File]::Open($Path,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    try{$read=$stream.Read($bytes,0,$bytes.Length)}finally{$stream.Dispose()}
    $actual='unknown'
    if($read-ge8-and$bytes[0]-eq0x89-and$bytes[1]-eq0x50-and$bytes[2]-eq0x4e-and$bytes[3]-eq0x47-and$bytes[4]-eq0x0d-and$bytes[5]-eq0x0a-and$bytes[6]-eq0x1a-and$bytes[7]-eq0x0a){$actual='png'}
    elseif($read-ge3-and$bytes[0]-eq0xff-and$bytes[1]-eq0xd8-and$bytes[2]-eq0xff){$actual='jpg'}
    if($actual-ne$expected){throw 'IMAGE_FORMAT_MISMATCH'}
    Add-Type -AssemblyName System.Drawing
    $file=$null;$image=$null;$bitmap=$null
    try{$file=[IO.File]::Open($Path,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read);$image=[Drawing.Image]::FromStream($file,$true,$true);if($image.Width-le0-or$image.Height-le0){throw 'IMAGE_DECODE_FAILED'};$bitmap=[Drawing.Bitmap]::new($image);[void]$bitmap.GetPixel(0,0);[void]$bitmap.GetPixel($bitmap.Width-1,$bitmap.Height-1)}catch{if($_.Exception.Message-eq'IMAGE_DECODE_FAILED'){throw};throw 'IMAGE_DECODE_FAILED'}finally{if($null-ne$bitmap){$bitmap.Dispose()};if($null-ne$image){$image.Dispose()};if($null-ne$file){$file.Dispose()}}
}

function Test-TWWaterFixedHex {
    param([string]$Left,[string]$Right)
    if($Left.Length -ne $Right.Length){return $false};$difference=0;for($index=0;$index-lt$Left.Length;$index++){$difference=$difference-bor([int][char]$Left[$index]-bxor[int][char]$Right[$index])};return $difference-eq 0
}

function Get-TWWaterImageBindingHmac {
    param([Parameter(Mandatory)]$Binding,[Parameter(Mandatory)][byte[]]$Key)
    if($Key.Length-ne 32){throw 'IMAGE_COMMAND_KEY_INVALID'}
    $values=[ordered]@{
        binding_id=([string]$Binding.binding_id).ToLowerInvariant()
        issued_utc=[string]$Binding.issued_utc
        expires_utc=[string]$Binding.expires_utc
        public_id=([string]$Binding.public_id).ToLowerInvariant()
        relative_path=([string]$Binding.relative_path).Replace('\','/')
        source_hash=([string]$Binding.source_hash).ToLowerInvariant()
        extension=([string]$Binding.extension).ToLowerInvariant()
        source_width=[string][int64]$Binding.source_width
        source_height=[string][int64]$Binding.source_height
        regions=[string]$Binding.regions
    }
    foreach($value in $values.Values){if([string]::IsNullOrWhiteSpace($value)-or$value.Contains("`r")-or$value.Contains("`n")){throw 'IMAGE_BINDING_INVALID'}}
    $message="twwater-image-binding-v2`nbinding_id=$($values.binding_id)`nissued_utc=$($values.issued_utc)`nexpires_utc=$($values.expires_utc)`npublic_id=$($values.public_id)`nrelative_path=$($values.relative_path)`nsource_hash=$($values.source_hash)`nextension=$($values.extension)`nsource_width=$($values.source_width)`nsource_height=$($values.source_height)`nregions=$($values.regions)"
    $hmac=[Security.Cryptography.HMACSHA256]::new($Key);try{return ([BitConverter]::ToString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($message)))).Replace('-','').ToLowerInvariant()}finally{$hmac.Dispose()}
}

function Get-TWWaterImageRestoreHmac {
    param([Parameter(Mandatory)]$Request,[Parameter(Mandatory)][byte[]]$Key)
    if($Key.Length-ne 32){throw 'IMAGE_COMMAND_KEY_INVALID'}
    $values=[ordered]@{
        schema_version=[string][int64]$Request.schema_version
        operation=[string]$Request.operation
        archive_scope=[string]$Request.archive_scope
        operation_id=([string]$Request.operation_id).ToLowerInvariant()
        document_public_id=([string]$Request.document_public_id).ToLowerInvariant()
        document_relative_path=([string]$Request.document_relative_path).Replace('\','/')
        extension=([string]$Request.extension).ToLowerInvariant()
        target_content_hash=([string]$Request.target_content_hash).ToLowerInvariant()
        expected_current_hash=([string]$Request.expected_current_hash).ToLowerInvariant()
        requested_by_user_id=[string][int64]$Request.requested_by_user_id
        requested_utc=[string]$Request.requested_utc
    }
    foreach($value in $values.Values){if([string]::IsNullOrWhiteSpace($value)-or$value.Contains("`r")-or$value.Contains("`n")){throw 'IMAGE_RESTORE_REQUEST_INVALID'}}
    $message="twwater-image-restore-v1`nschema_version=$($values.schema_version)`noperation=$($values.operation)`narchive_scope=$($values.archive_scope)`noperation_id=$($values.operation_id)`ndocument_public_id=$($values.document_public_id)`ndocument_relative_path=$($values.document_relative_path)`nextension=$($values.extension)`ntarget_content_hash=$($values.target_content_hash)`nexpected_current_hash=$($values.expected_current_hash)`nrequested_by_user_id=$($values.requested_by_user_id)`nrequested_utc=$($values.requested_utc)"
    $hmac=[Security.Cryptography.HMACSHA256]::new($Key);try{return ([BitConverter]::ToString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($message)))).Replace('-','').ToLowerInvariant()}finally{$hmac.Dispose()}
}

function Get-TWWaterSafePath {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $Label
    )
    if ([string]::IsNullOrWhiteSpace($RelativePath) -or [IO.Path]::IsPathRooted($RelativePath) -or $RelativePath.IndexOf([char]0) -ge 0) {
        throw "$Label is invalid."
    }
    $normalizedRelative = $RelativePath.Replace('/', '\')
    foreach ($segment in $normalizedRelative.Split('\')) {
        if ([string]::IsNullOrWhiteSpace($segment) -or $segment -eq '.' -or $segment -eq '..' -or $segment.Contains(':')) {
            throw "$Label is invalid."
        }
    }
    $candidate = [IO.Path]::GetFullPath((Join-Path $Root $normalizedRelative))
    $prefix = $Root.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $candidate.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "$Label escaped its root."
    }
    return $candidate
}

function Assert-TWWaterPathChain {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $Path
    )
    $rootFull = Get-TWWaterNormalizedRoot $Root 'Path root'
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $rootFull.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'PATH_ESCAPED_ROOT' }
    $cursor = $rootFull
    foreach ($segment in $full.Substring($prefix.Length).Split([IO.Path]::DirectorySeparatorChar)) {
        if ([string]::IsNullOrWhiteSpace($segment) -or $segment -eq '.' -or $segment -eq '..' -or $segment.Contains(':')) { throw 'PATH_INVALID' }
        $cursor = Join-Path $cursor $segment
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
            if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'PATH_REPARSE_POINT' }
        }
    }
    return $full
}

function Get-TWWaterImageCommandKey {
    param([Parameter(Mandatory)][string]$ArchiveRoot,[Parameter(Mandatory)][string]$CommandKeyPath)
    $archive=Get-TWWaterNormalizedRoot $ArchiveRoot 'Image archive root'
    $path=[IO.Path]::GetFullPath($CommandKeyPath)
    [void](Assert-TWWaterPathChain -Root $archive -Path $path)
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if($item.PSIsContainer-or[bool]($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-or$item.Length-ne 32){throw 'IMAGE_COMMAND_KEY_INVALID'}
    $bytes=[IO.File]::ReadAllBytes($path);if($bytes.Length-ne 32){throw 'IMAGE_COMMAND_KEY_INVALID'};return $bytes
}

function New-TWWaterRealDirectory {
    param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)
    if (-not (Test-Path -LiteralPath $Path)) {
        [void][IO.Directory]::CreateDirectory($Path)
    }
    return Get-TWWaterNormalizedRoot -Path $Path -Label $Label
}

function Write-TWWaterAtomicJson {
    param([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)] $Value)
    $parent = New-TWWaterRealDirectory -Path (Split-Path -Parent $Path) -Label 'JSON parent'
    $temporary = Join-Path $parent ('.version-json-' + [Guid]::NewGuid().ToString('N') + '.tmp')
    $encoding = New-Object Text.UTF8Encoding($false)
    try {
        $json = $Value | ConvertTo-Json -Compress -Depth 8
        [IO.File]::WriteAllText($temporary, $json, $encoding)
        if (Test-Path -LiteralPath $Path -PathType Leaf) {
            $backup = Join-Path $parent ('.version-json-backup-' + [Guid]::NewGuid().ToString('N') + '.tmp')
            try { [IO.File]::Replace($temporary, $Path, $backup, $true) }
            finally { if (Test-Path -LiteralPath $backup) { Remove-Item -LiteralPath $backup -Force } }
        }
        else {
            [IO.File]::Move($temporary, $Path)
        }
    }
    finally {
        if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue }
    }
}

function Save-TWWaterArchivedVersion {
    param(
        [Parameter(Mandatory)][string] $SourcePath,
        [Parameter(Mandatory)][string] $VersionRoot,
        [Parameter(Mandatory)][string] $DocumentPublicId,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][ValidateSet('publish','restore')][string] $Reason,
        [Parameter(Mandatory)][string] $ActorReference
    )
    $hash = Get-TWWaterHash -Path $SourcePath
    $extension = [IO.Path]::GetExtension($SourcePath).TrimStart('.').ToLowerInvariant()
    if ($extension -notin @('md','txt','pdf','docx','xlsx','pptx','png','jpg','jpeg','webp','gif')) { throw 'Unsupported document extension.' }
    $documentRoot = New-TWWaterRealDirectory -Path (Join-Path $VersionRoot $DocumentPublicId) -Label 'Document version directory'
    $destination = Join-Path $documentRoot $hash
    $artifactName = 'source.' + $extension
    if (Test-Path -LiteralPath $destination -PathType Container) {
        $existingArtifact = Join-Path $destination $artifactName
        if (-not (Test-Path -LiteralPath $existingArtifact -PathType Leaf) -or (Get-TWWaterHash $existingArtifact) -ne $hash) {
            throw 'Existing version archive is inconsistent.'
        }
        return [pscustomobject]@{ Hash = $hash; ArtifactPath = $existingArtifact }
    }

    $staging = Join-Path $documentRoot ('.staging-' + [Guid]::NewGuid().ToString('N'))
    [void][IO.Directory]::CreateDirectory($staging)
    try {
        $artifact = Join-Path $staging $artifactName
        [IO.File]::Copy($SourcePath, $artifact, $false)
        if ((Get-TWWaterHash $artifact) -ne $hash) { throw 'Archived document hash verification failed.' }
        $sourceItem = Get-Item -LiteralPath $SourcePath -Force
        $manifest = [ordered]@{
            schema_version = 1
            document_public_id = $DocumentPublicId
            document_relative_path = $RelativePath.Replace('\', '/')
            original_file_name = [IO.Path]::GetFileName($SourcePath)
            extension = $extension
            content_hash = $hash
            file_size_bytes = [int64]$sourceItem.Length
            source_modified_utc = $sourceItem.LastWriteTimeUtc.ToString('yyyy-MM-ddTHH:mm:ssZ')
            archived_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
            archived_reason = $Reason
            archived_by = $ActorReference
            artifact_file = $artifactName
        }
        Write-TWWaterAtomicJson -Path (Join-Path $staging 'manifest.json') -Value $manifest
        [IO.Directory]::Move($staging, $destination)
        $staging = $null
        return [pscustomobject]@{ Hash = $hash; ArtifactPath = (Join-Path $destination $artifactName) }
    }
    finally {
        if ($null -ne $staging -and (Test-Path -LiteralPath $staging)) { Remove-Item -LiteralPath $staging -Recurse -Force }
    }
}

function Set-TWWaterAuthoritativeSource {
    param(
        [Parameter(Mandatory)][string] $SourcePath,
        [Parameter(Mandatory)][string] $CandidatePath,
        [Parameter(Mandatory)][string] $ExpectedCandidateHash,
        [string] $RollbackPath = '',
        [switch] $RetainRollback
    )
    $candidateItem = Get-Item -LiteralPath $CandidatePath -Force -ErrorAction Stop
    if ($candidateItem.PSIsContainer -or [bool]($candidateItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Candidate must be a regular file.'
    }
    if ((Get-TWWaterHash $CandidatePath) -ne $ExpectedCandidateHash) { throw 'Candidate hash changed before publish.' }
    $parent = Split-Path -Parent $SourcePath
    $temporary = Join-Path $parent ('.document-version-' + [Guid]::NewGuid().ToString('N') + '.tmp')
    $rollback = if ([string]::IsNullOrWhiteSpace($RollbackPath)) { Join-Path $parent ('.document-version-rollback-' + [Guid]::NewGuid().ToString('N') + '.tmp') } else { [IO.Path]::GetFullPath($RollbackPath) }
    if ((Split-Path -Parent $rollback) -ne [IO.Path]::GetFullPath($parent) -or (Test-Path -LiteralPath $rollback)) { throw 'Rollback path is invalid.' }
    try {
        [IO.File]::Copy($CandidatePath, $temporary, $false)
        if ((Get-TWWaterHash $temporary) -ne $ExpectedCandidateHash) { throw 'Staged candidate hash verification failed.' }
        [IO.File]::Replace($temporary, $SourcePath, $rollback, $true)
        if ((Get-TWWaterHash $SourcePath) -ne $ExpectedCandidateHash) {
            throw 'Published source hash verification failed.'
        }
        if ($RetainRollback) { return $rollback }
    }
    catch {
        if (Test-Path -LiteralPath $rollback -PathType Leaf) {
            try {
                if (Test-Path -LiteralPath $SourcePath -PathType Leaf) { Remove-Item -LiteralPath $SourcePath -Force }
                [IO.File]::Move($rollback, $SourcePath)
            } catch { }
        }
        throw
    }
    finally {
        foreach ($path in @($temporary)) {
            if (Test-Path -LiteralPath $path -PathType Leaf) { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }
        }
        if (-not $RetainRollback -and (Test-Path -LiteralPath $rollback -PathType Leaf)) { Remove-Item -LiteralPath $rollback -Force -ErrorAction SilentlyContinue }
    }
}

function Restore-TWWaterRollbackIfCurrentCandidate {
    param(
        [Parameter(Mandatory)][string] $SourcePath,
        [Parameter(Mandatory)][string] $RollbackPath,
        [Parameter(Mandatory)][string] $ExpectedCandidateHash,
        [Parameter(Mandatory)][string] $ExpectedPreviousHash
    )
    if (-not (Test-Path -LiteralPath $RollbackPath -PathType Leaf)) { return }
    if (Test-Path -LiteralPath $SourcePath -PathType Leaf) {
        if ((Get-TWWaterHash $SourcePath) -ne $ExpectedCandidateHash) { throw 'ROLLBACK_SUPERSEDED' }
        Remove-Item -LiteralPath $SourcePath -Force
    }
    [IO.File]::Move($RollbackPath,$SourcePath)
    if ((Get-TWWaterHash $SourcePath) -ne $ExpectedPreviousHash) { throw 'ROLLBACK_VERIFICATION_FAILED' }
}

function Write-TWWaterVersionApproval {
    param(
        [Parameter(Mandatory)][string] $SessionRoot,
        [Parameter(Mandatory)][string] $DocumentPublicId,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $ContentHash,
        [Parameter(Mandatory)][string] $OperationId
    )
    $root = New-TWWaterRealDirectory -Path (Join-Path $SessionRoot 'document-version-approved') -Label 'Version approval directory'
    $path = Join-Path $root ($DocumentPublicId + '-' + $ContentHash + '.json')
    Write-TWWaterAtomicJson -Path $path -Value ([ordered]@{
        schema_version = 1
        operation_id = $OperationId
        document_public_id = $DocumentPublicId
        document_relative_path = $RelativePath.Replace('\', '/')
        approved_content_hash = $ContentHash
        approved_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    })
}

function Invoke-TWWaterDocumentVersionLocked {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string] $SourceRoot,
        [Parameter(Mandatory)][string] $VersionRoot,
        [Parameter(Mandatory)][string] $SessionRoot,
        [Parameter(Mandatory)][string] $DocumentPublicId,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $CandidatePath,
        [string] $CandidateRoot = '',
        [string] $ExpectedCandidateHash = '',
        [string] $CandidateImageExtension = '',
        [string] $AuthorizationId = '',
        [string] $AuthorizationRoot = '',
        [Parameter(Mandatory)][string] $ExpectedCurrentHash,
        [Parameter(Mandatory)][ValidateSet('publish','restore')][string] $Reason,
        [Parameter(Mandatory)][string] $ActorReference,
        [string] $OperationId = '',
        [string] $CompletedResultPath = '',
        [ValidateSet('','ApprovalDirectory','ApprovalWrite','ResultWrite','CrashAfterReplace')][string] $FailurePoint = ''
    )
    $SourceRoot = Get-TWWaterNormalizedRoot $SourceRoot 'SourceRoot'
    $VersionRoot = Get-TWWaterNormalizedRoot $VersionRoot 'VersionRoot'
    $SessionRoot = Get-TWWaterNormalizedRoot $SessionRoot 'SessionRoot'
    $DocumentPublicId = $DocumentPublicId.ToLowerInvariant()
    $ExpectedCurrentHash = $ExpectedCurrentHash.ToLowerInvariant()
    $ExpectedCandidateHash = $ExpectedCandidateHash.ToLowerInvariant()
    if (-not (Test-TWWaterUuid $DocumentPublicId) -or -not (Test-TWWaterSha256 $ExpectedCurrentHash)) { throw 'Version publish identifiers are invalid.' }
    if ($ExpectedCandidateHash -ne '' -and -not (Test-TWWaterSha256 $ExpectedCandidateHash)) { throw 'IMAGE_OUTPUT_HASH_CONFLICT' }
    if ([string]::IsNullOrWhiteSpace($OperationId)) { $OperationId = [Guid]::NewGuid().ToString() }
    if (-not (Test-TWWaterUuid $OperationId)) { throw 'Operation id is invalid.' }
    $authorizationMarker='';$authorizationMarkerWritten=$false
    if(([string]::IsNullOrWhiteSpace($AuthorizationId))-xor([string]::IsNullOrWhiteSpace($AuthorizationRoot))){throw 'IMAGE_BINDING_INVALID'}
    if(-not[string]::IsNullOrWhiteSpace($AuthorizationId)){
        $AuthorizationId=$AuthorizationId.ToLowerInvariant()
        if(-not(Test-TWWaterUuid $AuthorizationId)){throw 'IMAGE_BINDING_INVALID'}
        $AuthorizationRoot=Get-TWWaterNormalizedRoot $AuthorizationRoot 'AuthorizationRoot'
        [void](Assert-TWWaterPathChain -Root $VersionRoot -Path $AuthorizationRoot)
        $authorizationMarker=Join-Path $AuthorizationRoot ($AuthorizationId+'.json')
        [void](Assert-TWWaterPathChain -Root $AuthorizationRoot -Path $authorizationMarker)
        if(Test-Path -LiteralPath $authorizationMarker -PathType Leaf){throw 'IMAGE_BINDING_REPLAYED'}
    }
    $sourcePath = Get-TWWaterSafePath $SourceRoot $RelativePath 'Document relative path'
    [void](Assert-TWWaterPathChain -Root $SourceRoot -Path $sourcePath)
    if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) { throw 'Authoritative source file was not found.' }
    $sourceItem = Get-Item -LiteralPath $sourcePath -Force
    if ([bool]($sourceItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Authoritative source cannot be a reparse point.' }
    $actualCurrentHash = Get-TWWaterHash $sourcePath
    if ($actualCurrentHash -ne $ExpectedCurrentHash) { throw 'CURRENT_VERSION_CONFLICT' }
    if ($CandidateRoot -ne '') {
        $CandidateRoot=Get-TWWaterNormalizedRoot $CandidateRoot 'CandidateRoot'
        [void](Assert-TWWaterPathChain -Root $CandidateRoot -Path $CandidatePath)
    }
    $candidateItem=Get-Item -LiteralPath $CandidatePath -Force -ErrorAction Stop
    if($candidateItem.PSIsContainer-or[bool]($candidateItem.Attributes-band[IO.FileAttributes]::ReparsePoint)){throw 'IMAGE_OUTPUT_PATH_INVALID'}
    $candidateHash = Get-TWWaterHash $CandidatePath
    if($ExpectedCandidateHash-ne''-and$candidateHash-ne$ExpectedCandidateHash){throw 'IMAGE_OUTPUT_HASH_CONFLICT'}
    if($CandidateImageExtension-ne''){Assert-TWWaterRestorableImage -Path $CandidatePath -Extension $CandidateImageExtension}
    if ($candidateHash -eq $actualCurrentHash) { throw 'Candidate is already the current version.' }
    $sourceExtension = [IO.Path]::GetExtension($sourcePath).ToLowerInvariant()
    if ([IO.Path]::GetExtension($CandidatePath).ToLowerInvariant() -ne $sourceExtension) { throw 'Candidate extension does not match the source.' }

    $archive = Save-TWWaterArchivedVersion -SourcePath $sourcePath -VersionRoot $VersionRoot -DocumentPublicId $DocumentPublicId -RelativePath $RelativePath -Reason $Reason -ActorReference $ActorReference
    $rollbackPath=Join-Path (Split-Path -Parent $sourcePath) ('.document-version-rollback-'+$OperationId+'.tmp')
    if($Reason-eq'publish'-and-not[string]::IsNullOrWhiteSpace($CompletedResultPath)){
        Write-TWWaterAtomicJson -Path $CompletedResultPath -Value ([ordered]@{schema_version=2;operation_id=$OperationId;operation='publish';status='prepared';document_public_id=$DocumentPublicId;relative_path=$RelativePath.Replace('\','/');source_path=$sourcePath;candidate_path=$CandidatePath;candidate_root=$CandidateRoot;authorization_id=$AuthorizationId;authorization_root=$AuthorizationRoot;image_extension=$CandidateImageExtension;previous_hash=$archive.Hash;candidate_hash=$candidateHash;rollback_path=$rollbackPath;actor_reference=$ActorReference;prepared_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')})
    }
    $rollback = Set-TWWaterAuthoritativeSource -SourcePath $sourcePath -CandidatePath $CandidatePath -ExpectedCandidateHash $candidateHash -RollbackPath $rollbackPath -RetainRollback
    if($FailurePoint-eq'CrashAfterReplace'){throw 'INJECTED_PROCESS_CRASH'}
    try {
        if($authorizationMarker-ne''){
            Write-TWWaterAtomicJson -Path $authorizationMarker -Value ([ordered]@{schema_version=1;binding_id=$AuthorizationId;operation_id=$OperationId;document_public_id=$DocumentPublicId;candidate_hash=$candidateHash;consumed_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')})
            $authorizationMarkerWritten=$true
        }
        if ($FailurePoint -eq 'ApprovalDirectory') { throw 'INJECTED_APPROVAL_DIRECTORY_FAILURE' }
        if ($FailurePoint -eq 'ApprovalWrite') { throw 'INJECTED_APPROVAL_WRITE_FAILURE' }
        Write-TWWaterVersionApproval -SessionRoot $SessionRoot -DocumentPublicId $DocumentPublicId -RelativePath $RelativePath -ContentHash $candidateHash -OperationId $OperationId
        if ($FailurePoint -eq 'ResultWrite') { throw 'INJECTED_RESULT_WRITE_FAILURE' }
        if (-not [string]::IsNullOrWhiteSpace($CompletedResultPath)) {
            $completedStatus=if($Reason-eq'publish'){'local-published'}else{'local-restored'}
            Write-TWWaterAtomicJson -Path $CompletedResultPath -Value ([ordered]@{
                schema_version = 1; operation_id = $OperationId; operation = $Reason; status = $completedStatus; previous_hash = $archive.Hash; current_hash = $candidateHash; expected_current_hash = $ExpectedCurrentHash; target_content_hash = $candidateHash; completed_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
            })
        }
        if (Test-Path -LiteralPath $rollback -PathType Leaf) { Remove-Item -LiteralPath $rollback -Force }
    }
    catch {
        if($authorizationMarkerWritten-and(Test-Path -LiteralPath $authorizationMarker -PathType Leaf)){Remove-Item -LiteralPath $authorizationMarker -Force -ErrorAction SilentlyContinue}
        if (-not [string]::IsNullOrWhiteSpace($CompletedResultPath) -and (Test-Path -LiteralPath $CompletedResultPath -PathType Leaf)) { Remove-Item -LiteralPath $CompletedResultPath -Force -ErrorAction SilentlyContinue }
        $approvalPath=Join-Path (Join-Path $SessionRoot 'document-version-approved') ($DocumentPublicId+'-'+$candidateHash+'.json')
        if(Test-Path -LiteralPath $approvalPath -PathType Leaf){Remove-Item -LiteralPath $approvalPath -Force -ErrorAction SilentlyContinue}
        if (Test-Path -LiteralPath $rollback -PathType Leaf) {
            Restore-TWWaterRollbackIfCurrentCandidate -SourcePath $sourcePath -RollbackPath $rollback -ExpectedCandidateHash $candidateHash -ExpectedPreviousHash $actualCurrentHash
        }
        throw
    }
    return [pscustomobject]@{
        OperationId = $OperationId
        DocumentPublicId = $DocumentPublicId
        PreviousHash = $archive.Hash
        CurrentHash = $candidateHash
        SourcePath = $sourcePath
        ArchivedArtifactPath = $archive.ArtifactPath
    }
}

function Publish-TWWaterDocumentVersion {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string] $SourceRoot,
        [Parameter(Mandatory)][string] $VersionRoot,
        [Parameter(Mandatory)][string] $SessionRoot,
        [Parameter(Mandatory)][string] $DocumentPublicId,
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $CandidatePath,
        [string] $CandidateRoot = '',
        [string] $ExpectedCandidateHash = '',
        [string] $CandidateImageExtension = '',
        [string] $AuthorizationId = '',
        [string] $AuthorizationRoot = '',
        [Parameter(Mandatory)][string] $ExpectedCurrentHash,
        [Parameter(Mandatory)][ValidateSet('publish','restore')][string] $Reason,
        [Parameter(Mandatory)][string] $ActorReference,
        [string] $OperationId = '',
        [string] $CompletedResultPath = '',
        [ValidateSet('','ApprovalDirectory','ApprovalWrite','ResultWrite','CrashAfterReplace')][string] $FailurePoint = ''
    )
    $normalizedSourceRoot=Get-TWWaterNormalizedRoot $SourceRoot 'SourceRoot'
    $normalizedVersionRoot=Get-TWWaterNormalizedRoot $VersionRoot 'VersionRoot'
    $sourcePath=Get-TWWaterSafePath $normalizedSourceRoot $RelativePath 'Document relative path'
    [void](Assert-TWWaterPathChain -Root $normalizedSourceRoot -Path $sourcePath)
    $canonicalSource=[IO.Path]::GetFullPath($sourcePath).ToLowerInvariant()
    $sha=[Security.Cryptography.SHA256]::Create()
    try{$lockKey=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonicalSource)))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}
    $lockRoot=New-TWWaterRealDirectory (Join-Path $normalizedVersionRoot '.locks') 'Version source lock root'
    $lockPath=Join-Path $lockRoot ($lockKey+'.lock')
    try{$sourceLock=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)}catch{throw 'VERSION_SOURCE_BUSY'}
    try{return Invoke-TWWaterDocumentVersionLocked @PSBoundParameters}finally{$sourceLock.Dispose()}
}

function Repair-TWWaterPreparedImagePublishes {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$ImageSourceRoot,[Parameter(Mandatory)][string]$ImageArchiveRoot,[Parameter(Mandatory)][string]$SessionRoot)
    $ImageSourceRoot=Get-TWWaterNormalizedRoot $ImageSourceRoot 'ImageSourceRoot';$ImageArchiveRoot=Get-TWWaterNormalizedRoot $ImageArchiveRoot 'ImageArchiveRoot';$SessionRoot=Get-TWWaterNormalizedRoot $SessionRoot 'SessionRoot';$repaired=0
    foreach($documentDirectory in @(Get-ChildItem -LiteralPath $ImageArchiveRoot -Directory -Force -ErrorAction SilentlyContinue)){
        $publicId=$documentDirectory.Name.ToLowerInvariant();if(-not(Test-TWWaterUuid $publicId)-or[bool]($documentDirectory.Attributes-band[IO.FileAttributes]::ReparsePoint)){continue}
        $commitRoot=Join-Path $documentDirectory.FullName 'commits';if(-not(Test-Path -LiteralPath $commitRoot -PathType Container)){continue}
        foreach($commitFile in @(Get-ChildItem -LiteralPath $commitRoot -Filter '*.json' -File -Force -ErrorAction SilentlyContinue)){
            try{
                if($commitFile.Length-lt64-or$commitFile.Length-gt16384-or[bool]($commitFile.Attributes-band[IO.FileAttributes]::ReparsePoint)){continue}
                $intent=Get-Content -LiteralPath $commitFile.FullName -Raw -Encoding UTF8|ConvertFrom-Json -ErrorAction Stop
                if([int]$intent.schema_version-ne2-or[string]$intent.operation-ne'publish'-or[string]$intent.status-ne'prepared'){continue}
                $operationId=([string]$intent.operation_id).ToLowerInvariant();$bindingId=([string]$intent.authorization_id).ToLowerInvariant();$previousHash=([string]$intent.previous_hash).ToLowerInvariant();$candidateHash=([string]$intent.candidate_hash).ToLowerInvariant();$relative=[string]$intent.relative_path;$extension=([string]$intent.image_extension).ToLowerInvariant()
                if(-not(Test-TWWaterUuid $operationId)-or-not(Test-TWWaterUuid $bindingId)-or-not(Test-TWWaterSha256 $previousHash)-or-not(Test-TWWaterSha256 $candidateHash)-or$extension-notin@('png','jpg','jpeg')){continue}
                if($commitFile.BaseName.ToLowerInvariant()-ne$operationId){continue}
                $sourcePath=Get-TWWaterSafePath $ImageSourceRoot $relative 'Prepared publish source';[void](Assert-TWWaterPathChain -Root $ImageSourceRoot -Path $sourcePath);if([IO.Path]::GetFullPath([string]$intent.source_path)-ne[IO.Path]::GetFullPath($sourcePath)){continue}
                $candidateRoot=Get-TWWaterNormalizedRoot ([string]$intent.candidate_root) 'Prepared candidate root';[void](Assert-TWWaterPathChain -Root $documentDirectory.FullName -Path $candidateRoot);$candidatePath=[IO.Path]::GetFullPath([string]$intent.candidate_path);[void](Assert-TWWaterPathChain -Root $candidateRoot -Path $candidatePath)
                $authorizationRoot=Get-TWWaterNormalizedRoot ([string]$intent.authorization_root) 'Prepared authorization root';[void](Assert-TWWaterPathChain -Root $documentDirectory.FullName -Path $authorizationRoot);$authorizationMarker=Join-Path $authorizationRoot ($bindingId+'.json');[void](Assert-TWWaterPathChain -Root $authorizationRoot -Path $authorizationMarker)
                $canonical=[IO.Path]::GetFullPath($sourcePath).ToLowerInvariant();$sha=[Security.Cryptography.SHA256]::Create();try{$lockKey=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical)))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()};$lockRoot=New-TWWaterRealDirectory (Join-Path $ImageArchiveRoot '.locks') 'Version source lock root';$lockPath=Join-Path $lockRoot ($lockKey+'.lock');$lock=$null
                try{$lock=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None);if(-not(Test-Path -LiteralPath $sourcePath -PathType Leaf)){continue};$currentHash=Get-TWWaterHash $sourcePath;$rollback=[string]$intent.rollback_path
                    if($currentHash-eq$candidateHash){if(-not(Test-Path -LiteralPath $candidatePath -PathType Leaf)-or(Get-TWWaterHash $candidatePath)-ne$candidateHash){continue};Assert-TWWaterRestorableImage -Path $candidatePath -Extension $extension;$previousDirectory=Get-TWWaterSafePath $ImageArchiveRoot ($publicId+'\'+$previousHash) 'Prepared previous archive';[void](Assert-TWWaterPathChain -Root $ImageArchiveRoot -Path $previousDirectory);$previousArtifact=Join-Path $previousDirectory ('source.'+$extension);if(-not(Test-Path -LiteralPath $previousArtifact -PathType Leaf)-or(Get-TWWaterHash $previousArtifact)-ne$previousHash){continue};if(Test-Path -LiteralPath $authorizationMarker -PathType Leaf){$existing=Get-Content -LiteralPath $authorizationMarker -Raw|ConvertFrom-Json;if(([string]$existing.operation_id).ToLowerInvariant()-ne$operationId){continue}}else{Write-TWWaterAtomicJson -Path $authorizationMarker -Value ([ordered]@{schema_version=1;binding_id=$bindingId;operation_id=$operationId;document_public_id=$publicId;candidate_hash=$candidateHash;consumed_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ');recovered=$true})};Write-TWWaterVersionApproval -SessionRoot $SessionRoot -DocumentPublicId $publicId -RelativePath $relative -ContentHash $candidateHash -OperationId $operationId;Write-TWWaterAtomicJson -Path $commitFile.FullName -Value ([ordered]@{schema_version=1;operation_id=$operationId;operation='publish';status='local-published';previous_hash=$previousHash;current_hash=$candidateHash;expected_current_hash=$previousHash;target_content_hash=$candidateHash;recovered=$true;completed_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')});if($rollback-ne''-and(Test-Path -LiteralPath $rollback -PathType Leaf)-and(Get-TWWaterHash $rollback)-eq$previousHash){Remove-Item -LiteralPath $rollback -Force};$repaired++}
                    elseif($currentHash-eq$previousHash){if(Test-Path -LiteralPath $authorizationMarker -PathType Leaf){$existing=Get-Content -LiteralPath $authorizationMarker -Raw|ConvertFrom-Json;if(([string]$existing.operation_id).ToLowerInvariant()-eq$operationId){Remove-Item -LiteralPath $authorizationMarker -Force}};Write-TWWaterAtomicJson -Path $commitFile.FullName -Value ([ordered]@{schema_version=2;operation_id=$operationId;operation='publish';status='failed';error_code='PUBLISH_CRASH_BEFORE_REPLACE';previous_hash=$previousHash;candidate_hash=$candidateHash;completed_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')});if($rollback-ne''-and(Test-Path -LiteralPath $rollback -PathType Leaf)-and(Get-TWWaterHash $rollback)-eq$previousHash){Remove-Item -LiteralPath $rollback -Force};$repaired++}
                    else{Write-TWWaterAtomicJson -Path $commitFile.FullName -Value ([ordered]@{schema_version=2;operation_id=$operationId;operation='publish';status='failed';error_code='ROLLBACK_SUPERSEDED';previous_hash=$previousHash;candidate_hash=$candidateHash;observed_hash=$currentHash;completed_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')});$repaired++}
                }finally{if($null-ne$lock){$lock.Dispose()}}
            }catch{continue}
        }
    }
    if($repaired-gt0){$pending=Join-Path $SessionRoot 'document-bridge.pending';if(-not(Test-Path -LiteralPath $pending -PathType Leaf)){try{$stream=[IO.File]::Open($pending,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None);try{$bytes=[Text.Encoding]::UTF8.GetBytes('{"reason":"image-publish-recovery"}');$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true)}finally{$stream.Dispose()}}catch{}}}
    return $repaired
}

function Assert-TWWaterImageRestoreRequestSchema {
    param([Parameter(Mandatory)]$Request)
    $required=@('archive_scope','document_public_id','document_relative_path','expected_current_hash','extension','operation','operation_id','original_file_name','request_hmac','requested_by_user_id','requested_utc','schema_version','target_content_hash')|Sort-Object
    $actual=@($Request.PSObject.Properties.Name|Sort-Object)
    if(($actual-join"`n")-ne($required-join"`n")){throw 'IMAGE_RESTORE_REQUEST_INVALID'}
    $integerTypes=@([byte],[sbyte],[int16],[uint16],[int32],[uint32],[int64],[uint64])
    if($Request.schema_version.GetType()-notin$integerTypes-or[int64]$Request.schema_version-ne2-or$Request.requested_by_user_id.GetType()-notin$integerTypes-or[int64]$Request.requested_by_user_id-lt1){throw 'IMAGE_RESTORE_REQUEST_INVALID'}
    foreach($name in @('archive_scope','document_public_id','document_relative_path','expected_current_hash','extension','operation','operation_id','original_file_name','request_hmac','requested_utc','target_content_hash')){if($Request.$name-isnot[string]-or[string]::IsNullOrWhiteSpace([string]$Request.$name)){throw 'IMAGE_RESTORE_REQUEST_INVALID'}}
    if([string]$Request.operation-ne'restore'-or[string]$Request.archive_scope-ne'image-source'){throw 'IMAGE_RESTORE_REQUEST_INVALID'}
}

function Invoke-TWWaterDocumentVersionRequests {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string] $SourceRoot,
        [Parameter(Mandatory)][string] $VersionRoot,
        [AllowEmptyString()][string] $ImageSourceRoot = '',
        [AllowEmptyString()][string] $ImageArchiveRoot = '',
        [AllowEmptyString()][string] $ImageCommandKeyPath = '',
        [Parameter(Mandatory)][string] $SessionRoot,
        [ValidateRange(1,100)][int] $Limit = 20
    )
    $SourceRoot = Get-TWWaterNormalizedRoot $SourceRoot 'SourceRoot'
    $VersionRoot = Get-TWWaterNormalizedRoot $VersionRoot 'VersionRoot'
    if (-not [string]::IsNullOrWhiteSpace($ImageSourceRoot)) {
        $ImageSourceRoot = Get-TWWaterNormalizedRoot $ImageSourceRoot 'ImageSourceRoot'
    }
    if (-not [string]::IsNullOrWhiteSpace($ImageArchiveRoot)) {
        $ImageArchiveRoot = Get-TWWaterNormalizedRoot $ImageArchiveRoot 'ImageArchiveRoot'
    }
    $SessionRoot = Get-TWWaterNormalizedRoot $SessionRoot 'SessionRoot'
    $requestRoot = New-TWWaterRealDirectory (Join-Path $SessionRoot 'document-version-requests') 'Version request root'
    $pendingRoot = New-TWWaterRealDirectory (Join-Path $requestRoot 'pending') 'Version pending root'
    $processingRoot = New-TWWaterRealDirectory (Join-Path $requestRoot 'processing') 'Version processing root'
    $completedRoot = New-TWWaterRealDirectory (Join-Path $requestRoot 'completed') 'Version completed root'
    $failedRoot = New-TWWaterRealDirectory (Join-Path $requestRoot 'failed') 'Version failed root'
    foreach($stale in @(Get-ChildItem -LiteralPath $processingRoot -File -Filter '*.json'|Where-Object{$_.LastWriteTimeUtc-lt[DateTime]::UtcNow.AddSeconds(-30)})){
        $terminal=(Test-Path -LiteralPath (Join-Path $completedRoot $stale.Name) -PathType Leaf)-or(Test-Path -LiteralPath (Join-Path $failedRoot $stale.Name) -PathType Leaf)
        $pendingPath=Join-Path $pendingRoot $stale.Name
        if($terminal-or(Test-Path -LiteralPath $pendingPath -PathType Leaf)){Remove-Item -LiteralPath $stale.FullName -Force -ErrorAction SilentlyContinue}else{try{[IO.File]::Move($stale.FullName,$pendingPath)}catch{}}
    }
    $processed = 0
    foreach ($pending in @(Get-ChildItem -LiteralPath $pendingRoot -File -Filter '*.json' | Sort-Object Name | Select-Object -First $Limit)) {
        if (-not (Test-TWWaterUuid $pending.BaseName) -or $pending.Length -lt 32 -or $pending.Length -gt 16384) {
            Move-Item -LiteralPath $pending.FullName -Destination (Join-Path $failedRoot $pending.Name) -Force
            continue
        }
        if((Test-Path -LiteralPath (Join-Path $completedRoot $pending.Name) -PathType Leaf)-or(Test-Path -LiteralPath (Join-Path $failedRoot $pending.Name) -PathType Leaf)){
            Remove-Item -LiteralPath $pending.FullName -Force
            continue
        }
        $claimed = Join-Path $processingRoot $pending.Name
        try { [IO.File]::Move($pending.FullName, $claimed) } catch { continue }
        try {
            $request = Get-Content -LiteralPath $claimed -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
            $publicId = ([string]$request.document_public_id).ToLowerInvariant()
            $targetHash = ([string]$request.target_content_hash).ToLowerInvariant()
            $expectedHash = ([string]$request.expected_current_hash).ToLowerInvariant()
            $relativePath = [string]$request.document_relative_path
            $extension = ([string]$request.extension).ToLowerInvariant()
            $schemaVersion = [int]$request.schema_version
            $archiveScope = if ($null -ne $request.PSObject.Properties['archive_scope']) { [string]$request.archive_scope } else { 'document' }
            $imageRestore = $schemaVersion -eq 2 -and $archiveScope -eq 'image-source'
            $documentRestore = $schemaVersion -eq 1 -and $archiveScope -eq 'document'
            if($documentRestore-and$extension-in@('png','jpg','jpeg','webp','gif')){throw 'IMAGE_ASSET_REQUIRES_SIGNED_IMAGE_PIPELINE'}
            if ((-not $imageRestore -and -not $documentRestore) -or [string]$request.operation -ne 'restore' -or [string]$request.operation_id -ne $pending.BaseName -or
                -not (Test-TWWaterUuid $publicId) -or -not (Test-TWWaterSha256 $targetHash) -or -not (Test-TWWaterSha256 $expectedHash) -or
                $targetHash -eq $expectedHash -or $extension -notin @('md','txt','pdf','docx','xlsx','pptx','png','jpg','jpeg','webp','gif') -or
                ($imageRestore -and ($extension -notin @('png','jpg','jpeg','webp') -or [string]::IsNullOrWhiteSpace($ImageSourceRoot) -or [string]::IsNullOrWhiteSpace($ImageArchiveRoot)))) {
                throw 'INVALID_REQUEST'
            }
            if ($imageRestore) {
                Assert-TWWaterImageRestoreRequestSchema $request
                $requestHmac=if($null-ne$request.PSObject.Properties['request_hmac']){([string]$request.request_hmac).ToLowerInvariant()}else{''}
                if([string]::IsNullOrWhiteSpace($ImageCommandKeyPath)-or-not(Test-TWWaterSha256 $requestHmac)){throw 'IMAGE_REQUEST_SIGNATURE_INVALID'}
                $commandKey=Get-TWWaterImageCommandKey -ArchiveRoot $ImageArchiveRoot -CommandKeyPath $ImageCommandKeyPath
                $expectedRequestHmac=Get-TWWaterImageRestoreHmac -Request $request -Key $commandKey
                if(-not(Test-TWWaterFixedHex $requestHmac $expectedRequestHmac)){throw 'IMAGE_REQUEST_SIGNATURE_INVALID'}
                try{$requestedUtc=[DateTime]::ParseExact([string]$request.requested_utc,'yyyy-MM-ddTHH:mm:ssZ',[Globalization.CultureInfo]::InvariantCulture,([Globalization.DateTimeStyles]::AssumeUniversal-bor[Globalization.DateTimeStyles]::AdjustToUniversal))}catch{throw 'IMAGE_REQUEST_TIME_INVALID'}
                $nowUtc=[DateTime]::UtcNow;$requestExpired=$requestedUtc-lt$nowUtc.AddMinutes(-10)-or$requestedUtc-gt$nowUtc.AddMinutes(2)
            }
            $selectedSourceRoot = if ($imageRestore) { $ImageSourceRoot } else { $SourceRoot }
            $selectedVersionRoot = if ($imageRestore) { $ImageArchiveRoot } else { $VersionRoot }
            $versionDirectory = Get-TWWaterSafePath $selectedVersionRoot ($publicId + '\' + $targetHash) 'Archived version path'
            [void](Assert-TWWaterPathChain -Root $selectedVersionRoot -Path $versionDirectory)
            $artifact = Join-Path $versionDirectory ('source.' + $extension)
            $manifestPath = Join-Path $versionDirectory 'manifest.json'
            [void](Assert-TWWaterPathChain -Root $selectedVersionRoot -Path $artifact)
            [void](Assert-TWWaterPathChain -Root $selectedVersionRoot -Path $manifestPath)
            if (-not (Test-Path -LiteralPath $artifact -PathType Leaf) -or -not (Test-Path -LiteralPath $manifestPath -PathType Leaf) -or (Get-TWWaterHash $artifact) -ne $targetHash) { throw 'ARCHIVE_NOT_FOUND' }
            $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
            if ([string]$manifest.document_public_id -ne $publicId -or [string]$manifest.document_relative_path -ne $relativePath.Replace('\','/') -or [string]$manifest.content_hash -ne $targetHash -or [string]$manifest.artifact_file -ne ('source.' + $extension)) { throw 'ARCHIVE_MANIFEST_MISMATCH' }
            if($imageRestore){
                $recoverySource=Get-TWWaterSafePath $selectedSourceRoot $relativePath 'Recovery source path';[void](Assert-TWWaterPathChain -Root $selectedSourceRoot -Path $recoverySource)
                if(Test-Path -LiteralPath $recoverySource -PathType Leaf){
                    $canonical=[IO.Path]::GetFullPath($recoverySource).ToLowerInvariant();$sha=[Security.Cryptography.SHA256]::Create();try{$recoveryLockKey=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical)))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}
                    $recoveryLockRoot=New-TWWaterRealDirectory (Join-Path $selectedVersionRoot '.locks') 'Version source lock root';$recoveryLockPath=Join-Path $recoveryLockRoot ($recoveryLockKey+'.lock');$recoveryLock=$null
                    try{$recoveryLock=[IO.File]::Open($recoveryLockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None);$recoveryHash=Get-TWWaterHash $recoverySource;if($recoveryHash-eq$targetHash){if($imageRestore){Assert-TWWaterRestorableImage -Path $artifact -Extension $extension};$expectedDirectory=Get-TWWaterSafePath $selectedVersionRoot ($publicId+'\'+$expectedHash) 'Recovery previous archive';[void](Assert-TWWaterPathChain -Root $selectedVersionRoot -Path $expectedDirectory);$expectedArtifact=Join-Path $expectedDirectory ('source.'+$extension);$expectedManifestPath=Join-Path $expectedDirectory 'manifest.json';if(-not(Test-Path -LiteralPath $expectedArtifact -PathType Leaf)-or-not(Test-Path -LiteralPath $expectedManifestPath -PathType Leaf)-or(Get-TWWaterHash $expectedArtifact)-ne$expectedHash){throw 'RESTORE_RECOVERY_EVIDENCE_MISSING'};$expectedManifest=Get-Content -LiteralPath $expectedManifestPath -Raw -Encoding UTF8|ConvertFrom-Json -ErrorAction Stop;if([string]$expectedManifest.document_public_id-ne$publicId-or[string]$expectedManifest.document_relative_path-ne$relativePath.Replace('\','/')-or[string]$expectedManifest.content_hash-ne$expectedHash){throw 'RESTORE_RECOVERY_EVIDENCE_MISSING'};Write-TWWaterVersionApproval -SessionRoot $SessionRoot -DocumentPublicId $publicId -RelativePath $relativePath -ContentHash $targetHash -OperationId $pending.BaseName;Write-TWWaterAtomicJson -Path (Join-Path $completedRoot $pending.Name) -Value ([ordered]@{schema_version=1;operation_id=$pending.BaseName;status='local-restored';previous_hash=$expectedHash;current_hash=$targetHash;recovered=$true;completed_utc=[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')});$processed++;continue}}finally{if($null-ne$recoveryLock){$recoveryLock.Dispose()}}
                }
            }
            if($imageRestore-and$requestExpired){throw 'IMAGE_REQUEST_EXPIRED'}
            $result = Publish-TWWaterDocumentVersion -SourceRoot $selectedSourceRoot -VersionRoot $selectedVersionRoot -SessionRoot $SessionRoot -DocumentPublicId $publicId -RelativePath $relativePath -CandidatePath $artifact -CandidateRoot $versionDirectory -ExpectedCandidateHash $targetHash -CandidateImageExtension $(if($imageRestore){$extension}else{''}) -ExpectedCurrentHash $expectedHash -Reason 'restore' -ActorReference ('portal-user:' + [string]$request.requested_by_user_id) -OperationId $pending.BaseName
            Write-TWWaterAtomicJson -Path (Join-Path $completedRoot $pending.Name) -Value ([ordered]@{
                schema_version = 1; operation_id = $pending.BaseName; status = 'local-restored'; previous_hash = $result.PreviousHash; current_hash = $result.CurrentHash; completed_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
            })
            $processed++
        }
        catch {
            $code = if ($_.Exception.Message -match '\A[A-Z_]+\z') { $_.Exception.Message } else { 'RESTORE_FAILED' }
            $failureStatus = if ($code -eq 'CURRENT_VERSION_CONFLICT') { 'conflict' } else { 'failed' }
            Write-TWWaterAtomicJson -Path (Join-Path $failedRoot $pending.Name) -Value ([ordered]@{
                schema_version = 1; operation_id = $pending.BaseName; status = $failureStatus; error_code = $code; completed_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
            })
        }
        finally {
            if (Test-Path -LiteralPath $claimed -PathType Leaf) { Remove-Item -LiteralPath $claimed -Force }
        }
    }
    return $processed
}
