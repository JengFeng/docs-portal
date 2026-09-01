#Requires -Version 5.1

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $SignalRoot,

    [string] $TriggerUri = 'https://aiwork.ddns.net/gary/TWWATER/?action=presentation_queue_internal',

    [string] $ResolveHost = 'aiwork.ddns.net',

    [ValidateRange(1, 65535)]
    [int] $ResolvePort = 443,

    [string] $CurlPath = (Join-Path $env:SystemRoot 'System32\curl.exe'),

    [string] $ResultPath = '',

    [switch] $HealthCheck
)

$ErrorActionPreference = 'Stop'

function Resolve-RegularPath {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][bool] $Container,
        [Parameter(Mandatory)][string] $Label
    )

    $resolved = Resolve-Path -LiteralPath $Path -ErrorAction Stop
    $item = Get-Item -LiteralPath $resolved.ProviderPath -Force
    if ($item.PSIsContainer -ne $Container -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw "$Label is not a regular protected path."
    }
    return $item.FullName
}

function Get-SafeEndpointCode {
    param([AllowEmptyString()][string] $ResponseText)

    if ([string]::IsNullOrWhiteSpace($ResponseText) -or $ResponseText.Length -gt 4096) {
        return ''
    }
    try {
        $payload = $ResponseText | ConvertFrom-Json -ErrorAction Stop
        $candidate = [string] $payload.code
        if ($candidate -match '\A[A-Z][A-Z0-9_]{2,63}\z') {
            return $candidate
        }
    }
    catch {
        return ''
    }
    return ''
}

function Write-SafeHealthResult {
    param(
        [AllowNull()][IO.FileStream] $Stream,
        [Parameter(Mandatory)][ValidateSet('ready', 'failed')][string] $Status,
        [Parameter(Mandatory)][string] $Code
    )

    if ($null -eq $Stream) {
        return
    }
    if ($Code -notmatch '\A[A-Z][A-Z0-9_]{2,63}\z') {
        throw 'The health result code is invalid.'
    }
    $payload = [ordered]@{
        status = $Status
        code = $Code
    }
    $resultJson = $payload | ConvertTo-Json -Compress
    $resultEncoding = New-Object Text.UTF8Encoding($false)
    $resultBytes = $resultEncoding.GetBytes($resultJson)
    try {
        $Stream.Position = 0
        $Stream.SetLength(0)
        $Stream.Write($resultBytes, 0, $resultBytes.Length)
        $Stream.Flush($true)
    }
    finally {
        [Array]::Clear($resultBytes, 0, $resultBytes.Length)
    }
}

$signalDirectory = Resolve-RegularPath -Path $SignalRoot -Container $true -Label 'Signal root'
$healthResultStream = $null
if (-not [string]::IsNullOrWhiteSpace($ResultPath)) {
    if (-not $HealthCheck) {
        throw 'ResultPath is permitted only for a health check.'
    }
    $requestedResultPath = [IO.Path]::GetFullPath($ResultPath)
    $requestedResultParent = [IO.Path]::GetDirectoryName($requestedResultPath)
    $requestedResultName = [IO.Path]::GetFileName($requestedResultPath)
    if (-not [string]::Equals($requestedResultParent, $signalDirectory, [StringComparison]::OrdinalIgnoreCase) -or
        $requestedResultName -notmatch '\Apresentation-preview-validation-[0-9a-f]{32}\.json\z') {
        throw 'ResultPath must be a protected validation result below SignalRoot.'
    }
    $healthResultPath = Resolve-RegularPath `
        -Path $requestedResultPath `
        -Container $false `
        -Label 'Health result'
    $candidateResultStream = $null
    $initialResultBytes = $null
    try {
        $candidateResultStream = New-Object IO.FileStream(
            $healthResultPath,
            [IO.FileMode]::Open,
            [IO.FileAccess]::ReadWrite,
            [IO.FileShare]::ReadWrite,
            4096,
            [IO.FileOptions]::WriteThrough
        )
        if ($candidateResultStream.Length -lt 24 -or $candidateResultStream.Length -gt 512) {
            throw 'The initial health result is invalid.'
        }
        $initialResultBytes = New-Object byte[] ([int]$candidateResultStream.Length)
        $offset = 0
        while ($offset -lt $initialResultBytes.Length) {
            $read = $candidateResultStream.Read(
                $initialResultBytes,
                $offset,
                $initialResultBytes.Length - $offset
            )
            if ($read -le 0) {
                throw 'The initial health result could not be read completely.'
            }
            $offset += $read
        }
        $strictUtf8 = New-Object Text.UTF8Encoding($false, $true)
        $initialResultJson = $strictUtf8.GetString($initialResultBytes)
        $initialResult = $initialResultJson | ConvertFrom-Json -ErrorAction Stop
        $initialProperties = @($initialResult.PSObject.Properties.Name)
        if ($initialProperties.Count -ne 2 -or
            $initialProperties -notcontains 'status' -or
            $initialProperties -notcontains 'code' -or
            [string]$initialResult.status -ne 'failed' -or
            [string]$initialResult.code -ne 'TASK_NOT_STARTED') {
            throw 'The initial health result placeholder is invalid.'
        }
        $healthResultStream = $candidateResultStream
        $candidateResultStream = $null
    }
    finally {
        if ($null -ne $candidateResultStream) {
            $candidateResultStream.Dispose()
        }
        if ($null -ne $initialResultBytes) {
            [Array]::Clear($initialResultBytes, 0, $initialResultBytes.Length)
        }
    }
}

try {
Write-SafeHealthResult -Stream $healthResultStream -Status 'failed' -Code 'SIGNAL_STARTED'
$curlExecutable = Resolve-RegularPath -Path $CurlPath -Container $false -Label 'curl executable'
$uri = [Uri]$TriggerUri
if ($uri.Scheme -ne 'https' -or
    -not [string]::Equals($uri.DnsSafeHost, $ResolveHost, [StringComparison]::OrdinalIgnoreCase) -or
    $uri.Port -ne $ResolvePort -or
    $uri.AbsolutePath -ne '/gary/TWWATER/' -or
    $uri.Query -ne '?action=presentation_queue_internal' -or
    -not [string]::IsNullOrEmpty($uri.UserInfo) -or
    -not [string]::IsNullOrEmpty($uri.Fragment)) {
    throw 'TriggerUri must be the fixed HTTPS presentation queue endpoint.'
}
if ($ResolveHost -notmatch '\A[A-Za-z0-9.-]{1,253}\z') {
    throw 'ResolveHost is invalid.'
}

$markerPath = Join-Path $signalDirectory 'presentation-preview-queue.pending.json'
if (Test-Path -LiteralPath $markerPath) {
    $existing = Get-Item -LiteralPath $markerPath -Force
    if ($existing.PSIsContainer -or (($existing.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw 'The pending marker is not a regular file.'
    }
}
Write-SafeHealthResult -Stream $healthResultStream -Status 'failed' -Code 'SIGNAL_PREFLIGHT_READY'

$mode = if ($HealthCheck) { 'health' } else { 'process' }
$marker = [ordered]@{
    kind = 'TWWATER_PRESENTATION_QUEUE_WAKE'
    version = 1
    mode = $mode
    nonce = [Guid]::NewGuid().ToString('N')
    issuedUtc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
}
$json = $marker | ConvertTo-Json -Compress
$encoding = New-Object Text.UTF8Encoding($false)
$stream = New-Object IO.FileStream(
    $markerPath,
    [IO.FileMode]::Create,
    [IO.FileAccess]::Write,
    [IO.FileShare]::Read,
    4096,
    [IO.FileOptions]::WriteThrough
)
try {
    $bytes = $encoding.GetBytes($json)
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Flush($true)
}
finally {
    $stream.Dispose()
    if ($null -ne $bytes) {
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
}
Write-SafeHealthResult -Stream $healthResultStream -Status 'failed' -Code 'MARKER_WRITTEN'

$resolve = '{0}:{1}:127.0.0.1' -f $ResolveHost, $ResolvePort
$response = & $curlExecutable `
    '--silent' `
    '--show-error' `
    '--fail-with-body' `
    '--max-time' '30' `
    '--request' 'POST' `
    '--header' 'Cache-Control: no-store' `
    '--header' 'Content-Length: 0' `
    '--noproxy' '*' `
    '--resolve' $resolve `
    $TriggerUri

$curlExitCode = $LASTEXITCODE
$responseText = $response -join ''
if ($curlExitCode -ne 0) {
    $safeEndpointCode = Get-SafeEndpointCode -ResponseText $responseText
    $failureCode = if ($safeEndpointCode -ne '') {
        $safeEndpointCode
    }
    elseif ($curlExitCode -ge 1 -and $curlExitCode -le 255) {
        'CURL_EXIT_' + [string] $curlExitCode
    }
    else {
        'CURL_FAILED'
    }
    Write-SafeHealthResult -Stream $healthResultStream -Status 'failed' -Code $failureCode
    throw "The loopback presentation queue trigger failed [$failureCode]; the protected marker may be retried."
}

Write-SafeHealthResult -Stream $healthResultStream -Status 'ready' -Code 'READY'
if ($HealthCheck -and -not [string]::IsNullOrWhiteSpace($responseText)) {
    Write-Output $responseText
}

Write-Output ('[OK] Presentation queue {0} signal was accepted over loopback HTTPS.' -f $mode)
}
finally {
    if ($null -ne $healthResultStream) {
        $healthResultStream.Dispose()
    }
}
