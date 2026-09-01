[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()]
    [string] $ProjectRoot = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
}

function Assert-Condition {
    param(
        [Parameter(Mandatory)][bool] $Condition,
        [Parameter(Mandatory)][string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Test-PathIsWithin {
    param(
        [Parameter(Mandatory)][string] $Parent,
        [Parameter(Mandatory)][string] $Candidate
    )

    $parentPath = [IO.Path]::GetFullPath($Parent).TrimEnd('\', '/')
    $candidatePath = [IO.Path]::GetFullPath($Candidate).TrimEnd('\', '/')
    return $candidatePath.StartsWith($parentPath + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)
}

function Set-TestProtectedRootAcl {
    param(
        [Parameter(Mandatory)][string] $Path,
        [Parameter(Mandatory)][Security.Principal.SecurityIdentifier] $OwnerSid,
        [Parameter(Mandatory)][object[]] $Entries
    )

    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($OwnerSid)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    $propagation = [Security.AccessControl.PropagationFlags]::None
    $allow = [Security.AccessControl.AccessControlType]::Allow
    foreach ($entry in $Entries) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($entry.Sid, $entry.Rights, $inheritance, $propagation, $allow)
        [void]$acl.AddAccessRule($rule)
    }
    [IO.Directory]::SetAccessControl($Path, $acl)
}

$ProjectRoot = (Get-Item -LiteralPath $ProjectRoot -Force -ErrorAction Stop).FullName.TrimEnd('\', '/')
$bridgeScript = Join-Path $ProjectRoot 'scripts\document_bridge.ps1'
if (-not (Test-Path -LiteralPath $bridgeScript -PathType Leaf)) {
    throw "Document bridge script was not found: $bridgeScript"
}

$testRoot = Join-Path $ProjectRoot ('.document-bridge-test-' + [Guid]::NewGuid().ToString('N'))
if (-not (Test-PathIsWithin -Parent $ProjectRoot -Candidate $testRoot)) {
    throw 'Test root escaped the project root.'
}

try {
    $sourceRoot = Join-Path $testRoot 'source'
    $cacheRoot = Join-Path $testRoot 'cache'
    $runtimeRoot = Join-Path $testRoot 'runtime'
    $sessionRoot = Join-Path $testRoot 'sessions'
    $versionRoot = Join-Path $testRoot 'versions'
    $stagingRoot = Join-Path $testRoot 'staging'
    $stagedCommitRoot = Join-Path $testRoot 'commit-channel'
    foreach ($path in @($sourceRoot, $runtimeRoot, $sessionRoot, $versionRoot, $stagingRoot, $stagedCommitRoot)) {
        [void][IO.Directory]::CreateDirectory($path)
    }

    $sourceFile = Join-Path $sourceRoot '交付文件.md'
    [IO.File]::WriteAllText($sourceFile, 'version 1', (New-Object Text.UTF8Encoding($false)))
    [void][IO.Directory]::CreateDirectory((Join-Path $sourceRoot '.discord_uploads'))
    [IO.File]::WriteAllText((Join-Path $sourceRoot '.discord_uploads\excluded.md'), 'excluded', (New-Object Text.UTF8Encoding($false)))
    [void][IO.Directory]::CreateDirectory((Join-Path $sourceRoot 'maintenance-backups'))
    [IO.File]::WriteAllText((Join-Path $sourceRoot 'maintenance-backups\internal-screenshot.png'), 'internal', (New-Object Text.UTF8Encoding($false)))
    [IO.File]::WriteAllText((Join-Path $sourceRoot '~$draft.docx'), 'temporary', (New-Object Text.UTF8Encoding($false)))

    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $stagedCommitRoot -Once -SkipWake -SkipSignal
    $cachedFile = Join-Path $cacheRoot '交付文件.md'
    Assert-Condition -Condition (Test-Path -LiteralPath $cachedFile -PathType Leaf) -Message 'Allowed document was not copied.'
    Assert-Condition -Condition (-not (Test-Path -LiteralPath (Join-Path $cacheRoot '.discord_uploads\excluded.md'))) -Message 'Excluded Discord upload was copied.'
    Assert-Condition -Condition (-not (Test-Path -LiteralPath (Join-Path $cacheRoot 'maintenance-backups\internal-screenshot.png'))) -Message 'Internal maintenance backup was copied into the document cache.'
    Assert-Condition -Condition (-not (Test-Path -LiteralPath (Join-Path $cacheRoot '~$draft.docx'))) -Message 'Office temporary file was copied.'

    # Reproduce the production install boundary: a fresh PowerShell process
    # must still be able to validate the sentinel after cache ACL hardening.
    $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $adminsSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')
    $systemSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
    $appPoolSid = (New-Object Security.Principal.NTAccount('IIS AppPool\TWWATER_PortalPool')).Translate([Security.Principal.SecurityIdentifier])
    $fullControlEntries = @(
            @{ Sid = $currentSid; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
            @{ Sid = $adminsSid; Rights = [Security.AccessControl.FileSystemRights]::FullControl },
            @{ Sid = $systemSid; Rights = [Security.AccessControl.FileSystemRights]::FullControl }
    )
    Set-TestProtectedRootAcl -Path $cacheRoot -OwnerSid $currentSid -Entries ($fullControlEntries + @(
        @{ Sid = $appPoolSid; Rights = [Security.AccessControl.FileSystemRights]::ReadAndExecute }
    ))
    Set-TestProtectedRootAcl -Path $runtimeRoot -OwnerSid $currentSid -Entries $fullControlEntries
    Set-TestProtectedRootAcl -Path $sessionRoot -OwnerSid $currentSid -Entries ($fullControlEntries + @(
        @{ Sid = $appPoolSid; Rights = [Security.AccessControl.FileSystemRights]::Modify }
    ))

    Start-Sleep -Milliseconds 300
    [IO.File]::WriteAllText($sourceFile, 'version 2', (New-Object Text.UTF8Encoding($false)))
    $powerShellExecutable = Join-Path $env:windir 'System32\WindowsPowerShell\v1.0\powershell.exe'
    & $powerShellExecutable -NoProfile -ExecutionPolicy Bypass -File $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $stagedCommitRoot -Once -SkipWake -SkipSignal
    Assert-Condition -Condition ($LASTEXITCODE -eq 0) -Message "Fresh-process bridge validation failed with exit code $LASTEXITCODE."
    Assert-Condition -Condition ([IO.File]::ReadAllText($cachedFile) -eq 'version 2') -Message 'Changed document was not atomically replaced.'
    $quarantineRoot = Join-Path $runtimeRoot 'quarantine'
    Assert-Condition -Condition ((Get-ChildItem -LiteralPath $quarantineRoot -File -Recurse -ErrorAction SilentlyContinue | Measure-Object).Count -ge 1) -Message 'Prior cache version was not quarantined.'

    Remove-Item -LiteralPath $sourceFile -Force
    & $bridgeScript -SourceRoot $sourceRoot -CacheRoot $cacheRoot -RuntimeRoot $runtimeRoot -SessionRoot $sessionRoot -VersionRoot $versionRoot -StagingRoot $stagingRoot -StagedCommitRoot $stagedCommitRoot -Once -SkipWake -SkipSignal
    Assert-Condition -Condition (-not (Test-Path -LiteralPath $cachedFile -PathType Leaf)) -Message 'Deleted source document remained in the cache.'

    Write-Host '[OK] Document bridge smoke test passed: cache/runtime/session ACL inheritance, fresh-process validation, copy, exclusion, replacement, quarantine, and deletion.'
}
finally {
    if (Test-Path -LiteralPath $testRoot) {
        if (-not (Test-PathIsWithin -Parent $ProjectRoot -Candidate $testRoot)) {
            throw 'Refusing to remove a test path outside the project root.'
        }
        Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
