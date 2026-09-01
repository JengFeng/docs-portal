[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$CandidatePath)
$ErrorActionPreference='Stop'
$stream=$null
try {
    if(-not [IO.Path]::IsPathRooted($CandidatePath) -or -not (Test-Path -LiteralPath $CandidatePath -PathType Leaf)) { exit 2 }
    $item=Get-Item -LiteralPath $CandidatePath -Force
    if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 2 }
    $stream=New-Object IO.FileStream($item.FullName,[IO.FileMode]::Open,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None,4096,[IO.FileOptions]::DeleteOnClose)
    $stream.Dispose();$stream=$null
    if(Test-Path -LiteralPath $CandidatePath){exit 2}
    Write-Output 'DELETED'
    exit 0
} catch { exit 2 }
finally { if($null-ne$stream){$stream.Dispose()} }
