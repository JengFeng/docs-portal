$ErrorActionPreference = 'Stop'
$path = 'C:\web\gary\TWWATER\app\settings\sync-tool-web-trigger.json'
$identity = 'IIS APPPOOL\TWWATER_PortalPool'
if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw 'SYNC_WEB_TRIGGER_SETTINGS_FILE_MISSING' }
$acl = Get-Acl -LiteralPath $path
$rule = @($acl.Access | Where-Object { $_.IdentityReference.Value -eq $identity -and $_.AccessControlType -eq 'Allow' -and (($_.FileSystemRights -band [System.Security.AccessControl.FileSystemRights]::Modify) -eq [System.Security.AccessControl.FileSystemRights]::Modify) }) | Select-Object -First 1
if ($null -eq $rule) { throw 'SYNC_WEB_TRIGGER_SETTINGS_MODIFY_ACL_MISSING' }
Write-Output 'SYNC_WEB_TRIGGER_SETTINGS_ACL_OK'
