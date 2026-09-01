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
$MaxBytes=524288000L
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public static class TWWaterFinalPath {
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 public static extern uint GetFinalPathNameByHandle(SafeFileHandle hFile, System.Text.StringBuilder path, uint length, uint flags);
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 static extern SafeFileHandle CreateFile(string name, uint access, uint share, IntPtr security, uint creation, uint flags, IntPtr template);
 public static SafeFileHandle OpenDirectory(string path) {
  var h=CreateFile(path,0,1|2|4,IntPtr.Zero,3,0x02000000,IntPtr.Zero);
  if(h.IsInvalid) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
  return h;
 }
 public static string Read(SafeFileHandle handle) { var b=new System.Text.StringBuilder(32768); uint n=GetFinalPathNameByHandle(handle,b,(uint)b.Capacity,0); if(n==0 || n>=b.Capacity) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error()); return b.ToString(); }
}
'@
function Fail([string]$Code) { @{ok=$false;error=$Code}|ConvertTo-Json -Compress; exit 2 }
function Hash-Stream([System.IO.Stream]$Stream) {
    $Stream.Position=0
    $sha=[Security.Cryptography.SHA256]::Create()
    try { ([BitConverter]::ToString($sha.ComputeHash($Stream))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose(); $Stream.Position=0 }
}
function Normalize-FinalPath([string]$Path) {
    $value=$Path
    if($value.StartsWith('\\?\UNC\',[StringComparison]::OrdinalIgnoreCase)) { $value='\\'+$value.Substring(8) }
    elseif($value.StartsWith('\\?\',[StringComparison]::OrdinalIgnoreCase)) { $value=$value.Substring(4) }
    return [IO.Path]::GetFullPath($value).TrimEnd('\')
}
$rootHandle=$null;$directoryHandle=$null;$targetStream=$null;$candidateStream=$null;$readback=$null
try {
    if(-not [IO.Path]::IsPathRooted($Root) -or $RelativePath.Contains([char]0) -or $RelativePath.Contains(':') -or $RelativePath.StartsWith('\') -or $RelativePath.StartsWith('/')) { Fail 'PATH_INVALID' }
    $parts=$RelativePath -split '[\\/]'
    if($parts.Count -lt 1 -or ($parts|Where-Object { $_ -eq '' -or $_ -eq '.' -or $_ -eq '..' -or $_.StartsWith('.') }).Count -gt 0) { Fail 'PATH_INVALID' }
    if($CandidateName -notmatch '^\.[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.replace\.[a-z0-9]{1,10}$') { Fail 'CANDIDATE_INVALID' }
    $target=[IO.Path]::GetFullPath([IO.Path]::Combine($Root,($parts -join [IO.Path]::DirectorySeparatorChar)))
    $rootFull=[IO.Path]::GetFullPath($Root).TrimEnd('\')
    if(-not $target.StartsWith($rootFull+'\',[StringComparison]::OrdinalIgnoreCase)) { Fail 'PATH_ESCAPE' }
    $directory=[IO.Path]::GetDirectoryName($target); $candidate=[IO.Path]::Combine($directory,$CandidateName); $backup=$candidate+'.backup';$backupName=[IO.Path]::GetFileName($backup)
    if(Test-Path -LiteralPath $backup) { Fail 'BACKUP_EXISTS' }
    if([IO.Path]::GetExtension($candidate) -ine [IO.Path]::GetExtension($target)) { Fail 'EXTENSION_MISMATCH' }
    $walk=$Root
    foreach($part in $parts[0..([Math]::Max(0,$parts.Count-2))]) {
        $walk=[IO.Path]::Combine($walk,$part)
        if(Test-Path -LiteralPath $walk) { $item=Get-Item -LiteralPath $walk -Force; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Fail 'REPARSE_POINT' } }
    }
    foreach($path in @($Root,$directory,$target,$candidate)) { $item=Get-Item -LiteralPath $path -Force; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Fail 'REPARSE_POINT' } }

    $rootHandle=[TWWaterFinalPath]::OpenDirectory($Root)
    $directoryHandle=[TWWaterFinalPath]::OpenDirectory($directory)
    $share=[IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete
    $targetStream=[IO.File]::Open($target,[IO.FileMode]::Open,[IO.FileAccess]::Read,$share)
    $candidateStream=[IO.File]::Open($candidate,[IO.FileMode]::Open,[IO.FileAccess]::Read,$share)
    $rootFinal=Normalize-FinalPath ([TWWaterFinalPath]::Read($rootHandle))
    $directoryFinal=Normalize-FinalPath ([TWWaterFinalPath]::Read($directoryHandle))
    $targetFinal=Normalize-FinalPath ([TWWaterFinalPath]::Read($targetStream.SafeFileHandle))
    $candidateFinal=Normalize-FinalPath ([TWWaterFinalPath]::Read($candidateStream.SafeFileHandle))
    $expectedTarget=[IO.Path]::GetFullPath([IO.Path]::Combine($rootFinal,($parts -join [IO.Path]::DirectorySeparatorChar)))
    if($rootFinal -ine $rootFull -or $directoryFinal -ine [IO.Path]::GetDirectoryName($expectedTarget) -or $targetFinal -ine $expectedTarget -or [IO.Path]::GetDirectoryName($candidateFinal) -ine $directoryFinal) { Fail 'FINAL_PATH_MISMATCH' }
    if($targetStream.Length -gt $MaxBytes -or $candidateStream.Length -gt $MaxBytes) { Fail 'SIZE_LIMIT' }
    if((Hash-Stream $targetStream) -ne $ExpectedHash.ToLowerInvariant()) { Fail 'EXPECTED_HASH_CONFLICT' }
    if((Hash-Stream $candidateStream) -ne $CandidateHash.ToLowerInvariant()) { Fail 'CANDIDATE_HASH_CONFLICT' }

    # File.Replace itself refuses open source/destination handles on supported
    # Windows versions. Keep the handle-derived root and directory identities
    # pinned across the replace, then compare them to an exact handle-derived
    # target identity afterward.
    $candidateStream.Dispose();$candidateStream=$null
    $targetStream.Dispose();$targetStream=$null
    [IO.File]::Replace($candidate,$target,$backup,$true)
    $readback=[IO.File]::Open($target,[IO.FileMode]::Open,[IO.FileAccess]::Read,$share)
    $actual=Hash-Stream $readback; $size=$readback.Length; $readbackFinal=Normalize-FinalPath ([TWWaterFinalPath]::Read($readback.SafeFileHandle))
    $rootAfter=Normalize-FinalPath ([TWWaterFinalPath]::Read($rootHandle));$directoryAfter=Normalize-FinalPath ([TWWaterFinalPath]::Read($directoryHandle))
    $backupHash=(Get-FileHash -LiteralPath $backup -Algorithm SHA256).Hash.ToLowerInvariant()
    if($actual -ne $CandidateHash.ToLowerInvariant() -or $size -gt $MaxBytes -or $readbackFinal -ine $expectedTarget -or $rootAfter -ine $rootFinal -or $directoryAfter -ine $directoryFinal -or $backupHash -ne $ExpectedHash.ToLowerInvariant()) { Fail 'READBACK_MISMATCH' }
    if(-not(Test-Path -LiteralPath $backup -PathType Leaf) -or (Get-FileHash -LiteralPath $backup -Algorithm SHA256).Hash.ToLowerInvariant() -ne $backupHash) { Fail 'BACKUP_READBACK_MISMATCH' }
    $modified=(Get-Item -LiteralPath $target -Force).LastWriteTimeUtc.ToString('yyyy-MM-ddTHH:mm:ss.fffZ')
    @{ok=$true;actual_hash=$actual;size=$size;modified_utc=$modified;backup_name=$backupName;backup_hash=$backupHash}|ConvertTo-Json -Compress
    exit 0
} catch {
    if($env:TWWATER_ATOMIC_HELPER_TEST_MODE -eq '1') { @{ok=$false;error='ATOMIC_REPLACE_FAILED';detail=$_.Exception.Message}|ConvertTo-Json -Compress; exit 2 }
    Fail 'ATOMIC_REPLACE_FAILED'
} finally {
    if($null-ne$readback){$readback.Dispose()}
    if($null-ne$candidateStream){$candidateStream.Dispose()}
    if($null-ne$targetStream){$targetStream.Dispose()}
    if($null-ne$directoryHandle){$directoryHandle.Dispose()}
    if($null-ne$rootHandle){$rootHandle.Dispose()}
}
