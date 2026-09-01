[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'cache_acl_repair.ps1')

function Assert-True {
  param([bool] $Condition,[string] $Message)
  if(-not $Condition){ throw $Message }
}

$root = Join-Path ([IO.Path]::GetTempPath()) ('twwater-cache-acl-' + [Guid]::NewGuid().ToString('N'))
try {
  [void][IO.Directory]::CreateDirectory($root)
  $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
  $rootAcl = New-Object Security.AccessControl.DirectorySecurity
  $rootAcl.SetOwner($identity)
  $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
  $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity,[Security.AccessControl.FileSystemRights]::FullControl,$inheritance,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
  [void]$rootAcl.AddAccessRule($rule)
  [IO.Directory]::SetAccessControl($root,$rootAcl)

  $sentinel = Join-Path $root '.twwater-document-cache.json'
  [IO.File]::WriteAllText($sentinel,'{"kind":"test"}',(New-Object Text.UTF8Encoding($false)))
  $lockedAcl = New-Object Security.AccessControl.FileSecurity
  $lockedAcl.SetOwner($identity)
  $lockedAcl.SetAccessRuleProtection($true,$false)
  [IO.File]::SetAccessControl($sentinel,$lockedAcl)

  $denied = $false
  try { [void][IO.File]::ReadAllText($sentinel) } catch [UnauthorizedAccessException] { $denied = $true }
  Assert-True $denied 'Fixture must reproduce protected-empty child ACL denial.'

  $result = Repair-TWWaterCacheChildAclInheritance -CacheRoot $root
  Assert-True ($result.repaired -eq 1) 'Exactly one protected child ACL should be repaired.'
  Assert-True ([IO.File]::ReadAllText($sentinel) -eq '{"kind":"test"}') 'Repaired sentinel must inherit readable root ACL.'
  Write-Host '[OK] Cache child ACL inheritance repair contract passed.'
}
finally {
  if(Test-Path -LiteralPath $root){
    try {
      $cleanupAcl = Get-Acl -LiteralPath $root
      $cleanupAcl.SetAccessRuleProtection($false,$true)
      Set-Acl -LiteralPath $root -AclObject $cleanupAcl
    } catch {}
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
  }
}
