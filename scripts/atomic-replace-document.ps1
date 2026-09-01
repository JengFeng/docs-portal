[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$RelativePath,
    [Parameter(Mandatory=$true)][string]$CandidateName,
    [Parameter(Mandatory=$true)][ValidatePattern('^[0-9a-fA-F]{64}$')][string]$ExpectedHash,
    [Parameter(Mandatory=$true)][ValidatePattern('^[0-9a-fA-F]{64}$')][string]$CandidateHash
)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$Root='C:\web\gary\TWWATER\document-library'
if($env:TWWATER_ATOMIC_HELPER_TEST_MODE -eq '1' -and -not [string]::IsNullOrWhiteSpace($env:TWWATER_ATOMIC_HELPER_TEST_ROOT)) {
    $Root=[IO.Path]::GetFullPath($env:TWWATER_ATOMIC_HELPER_TEST_ROOT)
}
# expected and candidate hashes are independently verified before replacement.
$MaxBytes=524288000L
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public static class TWWaterFinalPath {
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 public static extern uint GetFinalPathNameByHandle(SafeFileHandle hFile, System.Text.StringBuilder path, uint length, uint flags);
 public static string Read(SafeFileHandle handle) { var b=new System.Text.StringBuilder(32768); uint n=GetFinalPathNameByHandle(handle,b,(uint)b.Capacity,0); if(n==0 || n>=b.Capacity) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error()); return b.ToString(); }
}
'@
function Fail([string]$Code) { @{ok=$false;error=$Code}|ConvertTo-Json -Compress; exit 2 }
function Hash-Stream([System.IO.Stream]$Stream) {
    $Stream.Position=0
    $sha=[Security.Cryptography.SHA256]::Create()
    try { ([BitConverter]::ToString($sha.ComputeHash($Stream))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose(); $Stream.Position=0 }
}
try {
    if(-not [IO.Path]::IsPathRooted($Root) -or $RelativePath.Contains([char]0) -or $RelativePath.Contains(':') -or $RelativePath.StartsWith('\') -or $RelativePath.StartsWith('/')) { Fail 'PATH_INVALID' }
    $parts=$RelativePath -split '[\\/]'
    if($parts.Count -lt 1 -or ($parts|Where-Object { $_ -eq '' -or $_ -eq '.' -or $_ -eq '..' -or $_.StartsWith('.') }).Count -gt 0) { Fail 'PATH_INVALID' }
    if($CandidateName -notmatch '^\.[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.replace\.[a-z0-9]{1,10}$') { Fail 'CANDIDATE_INVALID' }
    $target=[IO.Path]::GetFullPath([IO.Path]::Combine($Root,($parts -join [IO.Path]::DirectorySeparatorChar)))
    $rootFull=[IO.Path]::GetFullPath($Root).TrimEnd('\')+'\'
    if(-not $target.StartsWith($rootFull,[StringComparison]::OrdinalIgnoreCase)) { Fail 'PATH_ESCAPE' }
    $directory=[IO.Path]::GetDirectoryName($target); $candidate=[IO.Path]::Combine($directory,$CandidateName); $backup=$candidate+'.backup'
    if(Test-Path -LiteralPath $backup) { Fail 'BACKUP_EXISTS' }
    if([IO.Path]::GetExtension($candidate) -ine [IO.Path]::GetExtension($target)) { Fail 'EXTENSION_MISMATCH' }
    $walk=$Root
    foreach($part in $parts[0..([Math]::Max(0,$parts.Count-2))]) {
        $walk=[IO.Path]::Combine($walk,$part)
        if(Test-Path -LiteralPath $walk) { $item=Get-Item -LiteralPath $walk -Force; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Fail 'REPARSE_POINT' } }
    }
    foreach($path in @($Root,$directory,$target,$candidate)) { $item=Get-Item -LiteralPath $path -Force; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Fail 'REPARSE_POINT' } }
    $targetStream=[IO.File]::Open($target,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    $candidateStream=[IO.File]::Open($candidate,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    try {
        if($targetStream.Length -gt $MaxBytes -or $candidateStream.Length -gt $MaxBytes) { Fail 'SIZE_LIMIT' }
        $targetFinal=[TWWaterFinalPath]::Read($targetStream.SafeFileHandle); $candidateFinal=[TWWaterFinalPath]::Read($candidateStream.SafeFileHandle)
        if(-not $targetFinal.EndsWith($RelativePath.Replace('/','\'),[StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetDirectoryName($targetFinal) -ine [IO.Path]::GetDirectoryName($candidateFinal)) { Fail 'FINAL_PATH_MISMATCH' }
        if((Hash-Stream $targetStream) -ne $ExpectedHash.ToLowerInvariant()) { Fail 'EXPECTED_HASH_CONFLICT' }
        if((Hash-Stream $candidateStream) -ne $CandidateHash.ToLowerInvariant()) { Fail 'CANDIDATE_HASH_CONFLICT' }
    } finally { $candidateStream.Dispose(); $targetStream.Dispose() }
    [IO.File]::Replace($candidate,$target,$backup,$true)
    $readback=[IO.File]::Open($target,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    try { $actual=Hash-Stream $readback; $size=$readback.Length; $final=[TWWaterFinalPath]::Read($readback.SafeFileHandle) } finally { $readback.Dispose() }
    if($actual -ne $CandidateHash.ToLowerInvariant() -or $size -gt $MaxBytes -or -not $final.EndsWith($RelativePath.Replace('/','\'),[StringComparison]::OrdinalIgnoreCase)) { Fail 'READBACK_MISMATCH' }
    Remove-Item -LiteralPath $backup -Force -ErrorAction Stop
    $modified=(Get-Item -LiteralPath $target -Force).LastWriteTimeUtc.ToString('yyyy-MM-ddTHH:mm:ss.fffZ')
    @{ok=$true;actual_hash=$actual;size=$size;modified_utc=$modified}|ConvertTo-Json -Compress
    exit 0
} catch {
    if($env:TWWATER_ATOMIC_HELPER_TEST_MODE -eq '1') { @{ok=$false;error='ATOMIC_REPLACE_FAILED';detail=$_.Exception.Message}|ConvertTo-Json -Compress; exit 2 }
    Fail 'ATOMIC_REPLACE_FAILED'
}
