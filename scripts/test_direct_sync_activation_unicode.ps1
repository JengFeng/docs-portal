$ErrorActionPreference = 'Stop'
$path = Join-Path $PSScriptRoot 'activate_direct_sync_source.ps1'
$bytes = [IO.File]::ReadAllBytes($path)
$text = [Text.Encoding]::UTF8.GetString($bytes)
if ($text -match '[^\u0000-\u007F]') { throw 'ACTIVATOR_CONTAINS_NON_ASCII_SOURCE_TEXT' }
if ($text -notmatch '\[char\]0x4F9B' -or $text -notmatch '\[char\]0x6C34' -or $text -notmatch '\[char\]0x76E3' -or $text -notmatch '\[char\]0x6E2C') { throw 'UNICODE_SOURCE_COMPONENTS_MISSING' }
. $path -ValidateOnly
if ($script:ApprovedSourceRoot -ne ('C:\web\115' + (-join @([char]0x4F9B,[char]0x6C34,[char]0x76E3,[char]0x6E2C)))) { throw 'UNICODE_SOURCE_PATH_MISMATCH' }
Write-Output 'UNICODE_ACTIVATION_CONTRACT_OK'
