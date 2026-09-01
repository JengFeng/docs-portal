Set-StrictMode -Version Latest

$documentVersionEngine=Join-Path $PSScriptRoot 'document_version_engine.ps1'
if(-not(Test-Path -LiteralPath $documentVersionEngine -PathType Leaf)){throw 'Document version engine was not found.'}
. $documentVersionEngine

function Assert-TWWaterImagePathChain {
    param([Parameter(Mandatory)][string]$Root,[Parameter(Mandatory)][string]$Path,[Parameter(Mandatory)][string]$Label)
    $rootFull=Get-TWWaterNormalizedRoot $Root ($Label+' root')
    $full=[IO.Path]::GetFullPath($Path)
    $prefix=$rootFull.TrimEnd('\','/')+[IO.Path]::DirectorySeparatorChar
    if(-not $full.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){throw ($Label+' escaped its root.')}
    $relative=$full.Substring($prefix.Length)
    $cursor=$rootFull
    foreach($segment in $relative.Split([IO.Path]::DirectorySeparatorChar)){
        if([string]::IsNullOrWhiteSpace($segment)-or $segment-eq'.'-or $segment-eq'..'-or $segment.Contains(':')){throw ($Label+' is invalid.')}
        $cursor=Join-Path $cursor $segment
        if(Test-Path -LiteralPath $cursor){$item=Get-Item -LiteralPath $cursor -Force -ErrorAction Stop;if([bool]($item.Attributes-band[IO.FileAttributes]::ReparsePoint)){throw ($Label+' cannot traverse a reparse point.')}}
    }
    return $full
}

function Get-TWWaterImageCommandKey {
    param([Parameter(Mandatory)][string]$ArchiveRoot,[Parameter(Mandatory)][string]$CommandKeyPath)
    $archive=Get-TWWaterNormalizedRoot $ArchiveRoot 'Image archive root'
    $path=Assert-TWWaterImagePathChain $archive $CommandKeyPath 'Image command key'
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if($item.PSIsContainer-or[bool]($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-or$item.Length-ne 32){throw 'IMAGE_COMMAND_KEY_INVALID'}
    $bytes=[IO.File]::ReadAllBytes($path);if($bytes.Length-ne 32){throw 'IMAGE_COMMAND_KEY_INVALID'};return $bytes
}

function Get-TWWaterImageManifest {
    param([string]$ManifestPath,[string]$PreviewRoot)
    $path=Assert-TWWaterImagePathChain $PreviewRoot $ManifestPath 'Image manifest'
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if($item.PSIsContainer-or $item.Length-lt 32-or $item.Length-gt 32768){throw 'IMAGE_MANIFEST_INVALID'}
    try{$manifest=[IO.File]::ReadAllText($path,[Text.Encoding]::UTF8)|ConvertFrom-Json -ErrorAction Stop}catch{throw 'IMAGE_MANIFEST_INVALID'}
    $required=@('schema_version','document_id','relative_path','source_path','source_sha256','candidate_path','candidate_sha256','source_size','roi_px','outside_roi_changed_pixels','validation_status','external_api_used','source_overwritten','binding')|Sort-Object
    $actual=@($manifest.PSObject.Properties.Name|Sort-Object)
    if(($actual-join"`n")-ne($required-join"`n")){throw 'IMAGE_MANIFEST_INVALID'}
    $integerTypes=@([byte],[sbyte],[int16],[uint16],[int32],[uint32],[int64],[uint64])
    if($integerTypes-notcontains$manifest.schema_version.GetType()-or[int64]$manifest.schema_version-ne 2-or
       $manifest.document_id-isnot[string]-or-not(Test-TWWaterUuid $manifest.document_id)-or
       $manifest.relative_path-isnot[string]-or[string]::IsNullOrWhiteSpace($manifest.relative_path)-or$manifest.source_path-isnot[string]-or[string]::IsNullOrWhiteSpace($manifest.source_path)-or$manifest.candidate_path-isnot[string]-or[string]::IsNullOrWhiteSpace($manifest.candidate_path)-or
       $manifest.source_size-isnot[array]-or@($manifest.source_size).Count-ne2-or@($manifest.source_size|Where-Object{$integerTypes-notcontains$_.GetType()}).Count-ne0-or
       $manifest.roi_px-isnot[array]-or@($manifest.roi_px).Count-ne4-or@($manifest.roi_px|Where-Object{$integerTypes-notcontains$_.GetType()}).Count-ne0-or
       $manifest.binding-isnot[pscustomobject]-or
       $manifest.source_sha256-isnot[string]-or-not(Test-TWWaterSha256 $manifest.source_sha256)-or
       $manifest.candidate_sha256-isnot[string]-or-not(Test-TWWaterSha256 $manifest.candidate_sha256)-or
       $manifest.validation_status-isnot[string]-or$manifest.validation_status-ne'passed'-or
       $manifest.external_api_used-isnot[bool]-or
       $manifest.source_overwritten-isnot[bool]-or$manifest.source_overwritten-or
       $integerTypes-notcontains$manifest.outside_roi_changed_pixels.GetType()-or[int64]$manifest.outside_roi_changed_pixels-ne 0){throw 'IMAGE_MANIFEST_INVALID'}
    return $manifest
}

function Get-TWWaterImageMagicFormat {
    param([Parameter(Mandatory)][string]$Path)
    $bytes=New-Object byte[] 12;$stream=[IO.File]::Open($Path,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    try{$read=$stream.Read($bytes,0,$bytes.Length)}finally{$stream.Dispose()}
    if($read-ge 8-and$bytes[0]-eq0x89-and$bytes[1]-eq0x50-and$bytes[2]-eq0x4e-and$bytes[3]-eq0x47-and$bytes[4]-eq0x0d-and$bytes[5]-eq0x0a-and$bytes[6]-eq0x1a-and$bytes[7]-eq0x0a){return 'png'}
    if($read-ge 3-and$bytes[0]-eq0xff-and$bytes[1]-eq0xd8-and$bytes[2]-eq0xff){return 'jpg'}
    if($read-ge 12-and[Text.Encoding]::ASCII.GetString($bytes,0,4)-eq'RIFF'-and[Text.Encoding]::ASCII.GetString($bytes,8,4)-eq'WEBP'){return 'webp'}
    if($read-ge 6-and[Text.Encoding]::ASCII.GetString($bytes,0,6)-in@('GIF87a','GIF89a')){return 'gif'}
    return 'unknown'
}

function Assert-TWWaterImagePixels {
    param([string]$SourcePath,[string]$OutputPath,$Manifest,$Binding)
    $expected=([string]$Binding.extension).ToLowerInvariant();if($expected-eq'jpeg'){$expected='jpg'}
    if((Get-TWWaterImageMagicFormat $SourcePath)-ne$expected-or(Get-TWWaterImageMagicFormat $OutputPath)-ne$expected-or$expected-eq'gif'){throw 'IMAGE_FORMAT_MISMATCH'}
    Add-Type -AssemblyName System.Drawing
    $source=[Drawing.Bitmap]::new($SourcePath);$output=[Drawing.Bitmap]::new($OutputPath)
    try{
        if($source.RawFormat.Guid-ne[Drawing.Imaging.ImageFormat]::Png.Guid-and[IO.Path]::GetExtension($SourcePath).ToLowerInvariant()-eq'.gif'){throw 'IMAGE_FORMAT_UNSUPPORTED'}
        if($source.Width-ne$output.Width-or$source.Height-ne$output.Height){throw 'IMAGE_DIMENSION_MISMATCH'}
        $declared=@($Manifest.source_size);if($declared.Count-ne 2-or[int]$declared[0]-ne$source.Width-or[int]$declared[1]-ne$source.Height-or[int]$Binding.source_width-ne$source.Width-or[int]$Binding.source_height-ne$source.Height){throw 'IMAGE_DIMENSION_MISMATCH'}
        $regionText=[string]$Binding.regions;if($regionText-notmatch'\A\d+,\d+,\d+,\d+(?:;\d+,\d+,\d+,\d+)*\z'){throw 'IMAGE_ROI_INVALID'}
        $regions=@();foreach($part in $regionText.Split(';')){$numbers=@($part.Split(',')|ForEach-Object{[int]$_});if($numbers[0]-lt 0-or$numbers[1]-lt 0-or$numbers[2]-le$numbers[0]-or$numbers[3]-le$numbers[1]-or$numbers[2]-gt$source.Width-or$numbers[3]-gt$source.Height){throw 'IMAGE_ROI_INVALID'};$regions+=,@($numbers)}
        $changed=0;$outside=0
        for($y=0;$y-lt$source.Height;$y++){for($x=0;$x-lt$source.Width;$x++){
            if($source.GetPixel($x,$y).ToArgb()-ne$output.GetPixel($x,$y).ToArgb()){$changed++;$inside=$false;foreach($region in $regions){if($x-ge$region[0]-and$x-lt$region[2]-and$y-ge$region[1]-and$y-lt$region[3]){$inside=$true;break}};if(-not$inside){$outside++}}
        }}
        if($changed-eq 0){throw 'IMAGE_OUTPUT_UNCHANGED'}
        if($outside-ne 0-or$outside-ne[int64]$Manifest.outside_roi_changed_pixels){throw 'IMAGE_ROI_CONFLICT'}
    } finally {$output.Dispose();$source.Dispose()}
}

function Publish-TWWaterImageOutputFromManifest {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string]$ManifestPath,
        [Parameter(Mandatory)][string]$SourceRoot,
        [Parameter(Mandatory)][string]$PreviewRoot,
        [Parameter(Mandatory)][string]$ArchiveRoot,
        [Parameter(Mandatory)][string]$SessionRoot,
        [Parameter(Mandatory)][string]$CommandKeyPath,
        [string]$ActorReference='hermes-agent'
    )
    $SourceRoot=Get-TWWaterNormalizedRoot $SourceRoot 'SourceRoot';$PreviewRoot=Get-TWWaterNormalizedRoot $PreviewRoot 'PreviewRoot';$ArchiveRoot=Get-TWWaterNormalizedRoot $ArchiveRoot 'ImageArchiveRoot';$SessionRoot=Get-TWWaterNormalizedRoot $SessionRoot 'SessionRoot'
    $commandKey=Get-TWWaterImageCommandKey -ArchiveRoot $ArchiveRoot -CommandKeyPath $CommandKeyPath
    $manifest=Get-TWWaterImageManifest $ManifestPath $PreviewRoot
    $publicId=([string]$manifest.document_id).ToLowerInvariant();$relative=[string]$manifest.relative_path;$binding=$manifest.binding
    $documentPreview=Get-TWWaterSafePath $PreviewRoot $publicId 'Image preview directory'
    $manifestParent=[IO.Path]::GetFullPath((Split-Path -Parent ([IO.Path]::GetFullPath($ManifestPath)))).TrimEnd('\')
    if(-not[string]::Equals($manifestParent,$documentPreview.TrimEnd('\'),[StringComparison]::OrdinalIgnoreCase)){throw 'IMAGE_MANIFEST_SCOPE_INVALID'}
    $bindingRequired=@('binding_hmac','binding_id','expires_utc','extension','issued_utc','public_id','regions','relative_path','source_hash','source_height','source_width')|Sort-Object
    $bindingActual=@($binding.PSObject.Properties.Name|Sort-Object)
    if(($bindingActual-join"`n")-ne($bindingRequired-join"`n")){throw 'IMAGE_BINDING_INVALID'}
    foreach($name in @('binding_hmac','binding_id','expires_utc','extension','issued_utc','public_id','regions','relative_path','source_hash')){if($binding.$name-isnot[string]-or[string]::IsNullOrWhiteSpace([string]$binding.$name)){throw 'IMAGE_BINDING_INVALID'}}
    $integerTypes=@([byte],[sbyte],[int16],[uint16],[int32],[uint32],[int64],[uint64])
    if($binding.source_width.GetType()-notin$integerTypes-or$binding.source_height.GetType()-notin$integerTypes-or[int64]$binding.source_width-lt1-or[int64]$binding.source_height-lt1-or-not(Test-TWWaterUuid ([string]$binding.binding_id).ToLowerInvariant())){throw 'IMAGE_BINDING_INVALID'}
    try{$issuedUtc=[DateTime]::ParseExact([string]$binding.issued_utc,'yyyy-MM-ddTHH:mm:ssZ',[Globalization.CultureInfo]::InvariantCulture,([Globalization.DateTimeStyles]::AssumeUniversal-bor[Globalization.DateTimeStyles]::AdjustToUniversal));$expiresUtc=[DateTime]::ParseExact([string]$binding.expires_utc,'yyyy-MM-ddTHH:mm:ssZ',[Globalization.CultureInfo]::InvariantCulture,([Globalization.DateTimeStyles]::AssumeUniversal-bor[Globalization.DateTimeStyles]::AdjustToUniversal))}catch{throw 'IMAGE_BINDING_TIME_INVALID'}
    $nowUtc=[DateTime]::UtcNow
    if($issuedUtc-gt$nowUtc.AddMinutes(2)-or$issuedUtc-lt$nowUtc.AddHours(-24)-or$expiresUtc-le$nowUtc-or$expiresUtc-le$issuedUtc-or$expiresUtc-gt$issuedUtc.AddHours(24).AddMinutes(1)){throw 'IMAGE_BINDING_EXPIRED'}
    $expectedBindingHmac=Get-TWWaterImageBindingHmac -Binding $binding -Key $commandKey
    if(-not(Test-TWWaterFixedHex ([string]$binding.binding_hmac).ToLowerInvariant() $expectedBindingHmac)){throw 'IMAGE_BINDING_INVALID'}
    $extension=[IO.Path]::GetExtension($relative).TrimStart('.').ToLowerInvariant()
    if($extension-notin @('png','jpg','jpeg')-or
       ([string]$binding.public_id).ToLowerInvariant()-ne$publicId-or
       ([string]$binding.relative_path).Replace('\','/')-ne$relative.Replace('\','/')-or
       ([string]$binding.source_hash).ToLowerInvariant()-ne([string]$manifest.source_sha256).ToLowerInvariant()-or
       ([string]$binding.extension).ToLowerInvariant()-ne$extension){throw 'IMAGE_BINDING_INVALID'}
    $sourcePath=Get-TWWaterSafePath $SourceRoot $relative 'Image relative path';[void](Assert-TWWaterImagePathChain $SourceRoot $sourcePath 'Authoritative image')
    $manifestSource=[string]$manifest.source_path
    if([string]::IsNullOrWhiteSpace($manifestSource)){throw 'IMAGE_SOURCE_IDENTITY_CONFLICT'}
    try{$manifestSource=[IO.Path]::GetFullPath($manifestSource)}catch{throw 'IMAGE_SOURCE_IDENTITY_CONFLICT'}
    if(-not [string]::Equals($manifestSource,$sourcePath,[StringComparison]::OrdinalIgnoreCase)){throw 'IMAGE_SOURCE_IDENTITY_CONFLICT'}
    $outputPath=Assert-TWWaterImagePathChain $documentPreview ([string]$manifest.candidate_path) 'Generated image output'
    if(-not(Test-Path -LiteralPath $sourcePath -PathType Leaf)-or-not(Test-Path -LiteralPath $outputPath -PathType Leaf)){throw 'IMAGE_SOURCE_OR_OUTPUT_MISSING'}
    if((Get-TWWaterHash $sourcePath)-ne([string]$manifest.source_sha256).ToLowerInvariant()){throw 'CURRENT_VERSION_CONFLICT'}
    if((Get-TWWaterHash $outputPath)-ne([string]$manifest.candidate_sha256).ToLowerInvariant()){throw 'IMAGE_OUTPUT_HASH_CONFLICT'}
    if([IO.Path]::GetExtension($sourcePath).ToLowerInvariant()-ne[IO.Path]::GetExtension($outputPath).ToLowerInvariant()){throw 'IMAGE_EXTENSION_MISMATCH'}
    Assert-TWWaterImagePixels $sourcePath $outputPath $manifest $binding
    if((Get-TWWaterHash $sourcePath)-ne([string]$manifest.source_sha256).ToLowerInvariant()){throw 'CURRENT_VERSION_CONFLICT'}
    if((Get-TWWaterHash $outputPath)-ne([string]$manifest.candidate_sha256).ToLowerInvariant()){throw 'IMAGE_OUTPUT_HASH_CONFLICT'}
    $operationId=[Guid]::NewGuid().ToString()
    $documentArchive=Join-Path $ArchiveRoot $publicId
    if(-not(Test-Path -LiteralPath $documentArchive)){[void][IO.Directory]::CreateDirectory($documentArchive)}
    [void](Assert-TWWaterImagePathChain $ArchiveRoot $documentArchive 'Image archive document directory')
    $candidateRoot=Join-Path $documentArchive 'candidates'
    if(-not(Test-Path -LiteralPath $candidateRoot)){[void][IO.Directory]::CreateDirectory($candidateRoot)}
    [void](Assert-TWWaterImagePathChain $ArchiveRoot $candidateRoot 'Image candidate snapshot directory')
    $bindingRoot=Join-Path $documentArchive 'bindings'
    if(-not(Test-Path -LiteralPath $bindingRoot)){[void][IO.Directory]::CreateDirectory($bindingRoot)}
    [void](Assert-TWWaterImagePathChain $ArchiveRoot $bindingRoot 'Image binding consumption directory')
    $existingBindingMarker=Join-Path $bindingRoot (([string]$binding.binding_id).ToLowerInvariant()+'.json')
    [void](Assert-TWWaterImagePathChain $bindingRoot $existingBindingMarker 'Image binding consumption marker')
    if(Test-Path -LiteralPath $existingBindingMarker -PathType Leaf){throw 'IMAGE_BINDING_REPLAYED'}
    $snapshotPath=Join-Path $candidateRoot ($operationId+[IO.Path]::GetExtension($sourcePath).ToLowerInvariant())
    $input=$null;$snapshot=$null
    try{
        $input=[IO.File]::Open($outputPath,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
        $snapshot=[IO.File]::Open($snapshotPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        $input.CopyTo($snapshot);$snapshot.Flush($true)
    }finally{if($null-ne$snapshot){$snapshot.Dispose()};if($null-ne$input){$input.Dispose()}}
    [void](Assert-TWWaterImagePathChain $candidateRoot $snapshotPath 'Immutable image candidate snapshot')
    if((Get-TWWaterHash $snapshotPath)-ne([string]$manifest.candidate_sha256).ToLowerInvariant()){throw 'IMAGE_OUTPUT_HASH_CONFLICT'}
    Assert-TWWaterImagePixels $sourcePath $snapshotPath $manifest $binding
    if((Get-TWWaterHash $sourcePath)-ne([string]$manifest.source_sha256).ToLowerInvariant()){throw 'CURRENT_VERSION_CONFLICT'}
    if((Get-TWWaterHash $snapshotPath)-ne([string]$manifest.candidate_sha256).ToLowerInvariant()){throw 'IMAGE_OUTPUT_HASH_CONFLICT'}
    $commitPath=Join-Path (Join-Path (Join-Path $ArchiveRoot $publicId) 'commits') ($operationId+'.json')
    return Publish-TWWaterDocumentVersion -SourceRoot $SourceRoot -VersionRoot $ArchiveRoot -SessionRoot $SessionRoot -DocumentPublicId $publicId -RelativePath $relative -CandidatePath $snapshotPath -CandidateRoot $candidateRoot -ExpectedCandidateHash ([string]$manifest.candidate_sha256).ToLowerInvariant() -CandidateImageExtension ([string]$binding.extension).ToLowerInvariant() -AuthorizationId ([string]$binding.binding_id).ToLowerInvariant() -AuthorizationRoot $bindingRoot -ExpectedCurrentHash ([string]$manifest.source_sha256).ToLowerInvariant() -Reason publish -ActorReference $ActorReference -OperationId $operationId -CompletedResultPath $commitPath
}
