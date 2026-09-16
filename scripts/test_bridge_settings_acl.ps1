param(
    [string]$Path = 'C:\web\gary\TWWATER\app\bridge_settings.json',
    [string]$Identity = 'IIS APPPOOL\TWWATER_PortalPool'
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw 'BRIDGE_SETTINGS_FILE_MISSING' }
$item = Get-Item -LiteralPath $Path -Force
if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'BRIDGE_SETTINGS_REPARSE_POINT_NOT_ALLOWED' }
$acl = Get-Acl -LiteralPath $Path
$rule = $acl.Access | Where-Object { $_.IdentityReference.Value -eq $Identity -and $_.AccessControlType -eq 'Allow' -and (($_.FileSystemRights -band [Security.AccessControl.FileSystemRights]::Modify) -eq [Security.AccessControl.FileSystemRights]::Modify) }
if (-not $rule) { throw 'BRIDGE_SETTINGS_APPPOOL_MODIFY_ACL_MISSING' }
Write-Output 'BRIDGE_SETTINGS_APPPOOL_MODIFY_ACL_OK'
