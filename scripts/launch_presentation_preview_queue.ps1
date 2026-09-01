#Requires -Version 5.1

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $PhpPath,

    [string] $ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

function Resolve-RegularFile {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][string] $Label
    )

    if ($Path.IndexOfAny([char[]]@('"', "`r", "`n", [char]0)) -ge 0) {
        throw "$Label contains an invalid character."
    }
    $resolved = Resolve-Path -LiteralPath $Path -ErrorAction Stop
    $item = Get-Item -LiteralPath $resolved.ProviderPath -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw "$Label must be a regular file."
    }
    return $item.FullName
}

function ConvertTo-NativeArgument {
    param([Parameter(Mandatory)][string] $Value)

    if ($Value.IndexOfAny([char[]]@('"', "`r", "`n", [char]0)) -ge 0) {
        throw 'A native process argument contains an invalid character.'
    }
    return '"' + $Value + '"'
}

$expectedProjectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
$requestedProjectRoot = [IO.Path]::GetFullPath($ProjectRoot).TrimEnd('\')
if (-not [string]::Equals($expectedProjectRoot, $requestedProjectRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'ProjectRoot must be the deployed TWWATER application root.'
}

$phpExecutable = Resolve-RegularFile -Path $PhpPath -Label 'PHP CLI executable'
if (-not [string]::Equals([IO.Path]::GetFileName($phpExecutable), 'php.exe', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The queue launcher requires php.exe, not php-cgi.exe.'
}

$queuePath = Resolve-RegularFile `
    -Path (Join-Path $expectedProjectRoot 'scripts\process_presentation_preview_queue.php') `
    -Label 'Presentation queue processor'

# The child inherits the AppPool environment in memory. Only fixed, non-secret
# arguments are placed on its command line. Redirected anonymous pipes prevent
# the detached child from holding the IIS request's stdout/stderr handles open.
$startInfo = New-Object Diagnostics.ProcessStartInfo
$startInfo.FileName = $phpExecutable
$startInfo.Arguments = '-f ' + (ConvertTo-NativeArgument -Value $queuePath) + ' -- --quiet'
$startInfo.WorkingDirectory = $expectedProjectRoot
$startInfo.UseShellExecute = $false
$startInfo.CreateNoWindow = $true
$startInfo.RedirectStandardOutput = $true
$startInfo.RedirectStandardError = $true

$process = [Diagnostics.Process]::Start($startInfo)
if ($null -eq $process) {
    throw 'The presentation queue processor could not be started.'
}
$process.Dispose()

Write-Output '[OK] Presentation queue processor started under the IIS AppPool identity.'
