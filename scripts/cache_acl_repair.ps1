Set-StrictMode -Version Latest

function Repair-TWWaterCacheChildAclInheritance {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string] $CacheRoot)

    $rootItem = Get-Item -LiteralPath $CacheRoot -Force -ErrorAction Stop
    if (-not $rootItem.PSIsContainer) { throw 'CacheRoot must be a directory.' }
    if ([bool]($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'CacheRoot must not be a reparse point.' }

    $scanned = 0
    $repaired = 0
    foreach ($item in @(Get-ChildItem -LiteralPath $rootItem.FullName -Force -Recurse -ErrorAction Stop)) {
        $scanned++
        if ([bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'Cache tree contains a reparse point; ACL repair was refused.'
        }
        $acl = if ($item.PSIsContainer) {
            [IO.Directory]::GetAccessControl($item.FullName)
        }
        else {
            [IO.File]::GetAccessControl($item.FullName)
        }
        if (-not $acl.AreAccessRulesProtected) { continue }
        $acl.SetAccessRuleProtection($false, $true)
        if ($item.PSIsContainer) {
            [IO.Directory]::SetAccessControl($item.FullName, $acl)
        }
        else {
            [IO.File]::SetAccessControl($item.FullName, $acl)
        }
        $repaired++
    }
    [pscustomobject]@{ scanned = $scanned; repaired = $repaired }
}
