[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()][string]$ImageSourceRoot='C:\web\gary\TWWATER\document-library',
    [ValidateNotNullOrEmpty()][string]$ImageArchiveRoot='C:\TWWATER\runtime\image-source-archive',
    [ValidateNotNullOrEmpty()][string]$ImageCommandKeyPath='C:\TWWATER\runtime\image-source-archive\.keys\image-command.key',
    [ValidateNotNullOrEmpty()][string]$AppPoolName='TWWATER_PortalPool',
    [ValidateNotNullOrEmpty()][string]$TaskName='TWWATER Document Bridge',
    [switch]$Activate
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'

function Require-Administrator{
    $principal=[Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
    if(-not$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'ADMINISTRATOR_REQUIRED'}
}
function Set-ArchiveAcl([string]$Path,[string]$PoolName){
    [void][IO.Directory]::CreateDirectory($Path)
    $item=Get-Item -LiteralPath $Path -Force
    if(($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-ne0){throw 'IMAGE_ARCHIVE_REPARSE_ROOT'}
    $acl=[Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true,$false)
    $inherit=[Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
    $prop=[Security.AccessControl.PropagationFlags]::None
    $allow=[Security.AccessControl.AccessControlType]::Allow
    $current=[Security.Principal.WindowsIdentity]::GetCurrent().Name
    foreach($entry in @(
        @('NT AUTHORITY\SYSTEM',[Security.AccessControl.FileSystemRights]::FullControl),
        @('BUILTIN\Administrators',[Security.AccessControl.FileSystemRights]::FullControl),
        @($current,[Security.AccessControl.FileSystemRights]::Modify),
        @("IIS AppPool\$PoolName",[Security.AccessControl.FileSystemRights]::ReadAndExecute)
    )){$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([string]$entry[0],[Security.AccessControl.FileSystemRights]$entry[1],$inherit,$prop,$allow))}
    Set-Acl -LiteralPath $Path -AclObject $acl
    $readback=Get-Acl -LiteralPath $Path
    $poolRule=$readback.Access|Where-Object{$_.IdentityReference.Value-eq"IIS AppPool\$PoolName"-and($_.FileSystemRights-band[Security.AccessControl.FileSystemRights]::ReadAndExecute)}|Select-Object -First 1
    if($null-eq$poolRule){throw 'IMAGE_ARCHIVE_ACL_READBACK_FAILED'}
}
function Ensure-ImageCommandKey([string]$ArchiveRoot,[string]$KeyPath){
    $archive=[IO.Path]::GetFullPath($ArchiveRoot).TrimEnd('\')
    $key=[IO.Path]::GetFullPath($KeyPath)
    if(-not$key.StartsWith($archive+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'IMAGE_COMMAND_KEY_OUTSIDE_ARCHIVE'}
    [void][IO.Directory]::CreateDirectory((Split-Path -Parent $key))
    if(-not(Test-Path -LiteralPath $key -PathType Leaf)){
        $bytes=New-Object byte[] 32
        $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
        try{$rng.GetBytes($bytes)}finally{$rng.Dispose()}
        $stream=[IO.File]::Open($key,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        try{$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true)}finally{$stream.Dispose()}
    }
    $item=Get-Item -LiteralPath $key -Force
    if($item.PSIsContainer-or$item.Length-ne 32-or[bool]($item.Attributes-band[IO.FileAttributes]::ReparsePoint)){throw 'IMAGE_COMMAND_KEY_INVALID'}
}
function Set-AppPoolEnvironment([string]$PoolName,[string]$ArchiveValue,[string]$KeyPathValue){
    $assembly=Join-Path $env:WINDIR 'System32\inetsrv\Microsoft.Web.Administration.dll'
    Add-Type -Path $assembly
    $manager=[Microsoft.Web.Administration.ServerManager]::new()
    try{
        $section=$manager.GetApplicationHostConfiguration().GetSection('system.applicationHost/applicationPools')
        $pool=$section.GetCollection()|Where-Object{[string]$_.GetAttributeValue('name')-eq$PoolName}|Select-Object -First 1
        if($null-eq$pool){throw 'APPPOOL_NOT_FOUND'}
        $variables=$pool.GetCollection('environmentVariables')
        foreach($pair in @(@('PORTAL_IMAGE_SOURCE_ARCHIVE_ROOT',$ArchiveValue),@('PORTAL_IMAGE_COMMAND_KEY_PATH',$KeyPathValue))){
            $variable=$variables|Where-Object{[string]$_.GetAttributeValue('name')-eq[string]$pair[0]}|Select-Object -First 1
            if($null-eq$variable){
                $variable=$variables.CreateElement('add')
                $variable.SetAttributeValue('name',[string]$pair[0])
                $variable.SetAttributeValue('value',[string]$pair[1])
                [void]$variables.Add($variable)
            }else{$variable.SetAttributeValue('value',[string]$pair[1])}
        }
        $manager.CommitChanges()
    }finally{$manager.Dispose()}
    $verify=[Microsoft.Web.Administration.ServerManager]::new()
    try{
        $section=$verify.GetApplicationHostConfiguration().GetSection('system.applicationHost/applicationPools')
        $pool=$section.GetCollection()|Where-Object{[string]$_.GetAttributeValue('name')-eq$PoolName}|Select-Object -First 1
        $stored=$pool.GetCollection('environmentVariables')
        $actualArchive=$stored|Where-Object{[string]$_.GetAttributeValue('name')-eq'PORTAL_IMAGE_SOURCE_ARCHIVE_ROOT'}|ForEach-Object{[string]$_.GetAttributeValue('value')}|Select-Object -First 1
        $actualKey=$stored|Where-Object{[string]$_.GetAttributeValue('name')-eq'PORTAL_IMAGE_COMMAND_KEY_PATH'}|ForEach-Object{[string]$_.GetAttributeValue('value')}|Select-Object -First 1
        if($actualArchive-ne$ArchiveValue-or$actualKey-ne$KeyPathValue){throw 'APPPOOL_IMAGE_COMMAND_READBACK_FAILED'}
    }finally{$verify.Dispose()}
}
function Set-BridgeAction([string]$Name,[string]$SourceValue,[string]$ArchiveValue,[string]$KeyPathValue){
    $task=Get-ScheduledTask -TaskName $Name -ErrorAction Stop
    if($task.Actions.Count-ne1){throw 'BRIDGE_ACTION_COUNT_CONFLICT'}
    $old=$task.Actions[0];$arguments=[string]$old.Arguments
    foreach($parameter in @('ImageSourceRoot','ImageArchiveRoot','ImageCommandKeyPath')){
        $pattern='(?i)(?:^|\s)-'+$parameter+'\s+(?:"[^"]*"|\S+)'
        $arguments=[regex]::Replace($arguments,$pattern,'').Trim()
    }
    $arguments+=" -ImageSourceRoot `"$SourceValue`" -ImageArchiveRoot `"$ArchiveValue`" -ImageCommandKeyPath `"$KeyPathValue`""
    $workingDirectory=[string]$old.WorkingDirectory
    if([string]::IsNullOrWhiteSpace($workingDirectory)){
        $action=New-ScheduledTaskAction -Execute ([string]$old.Execute) -Argument $arguments
    }else{
        $action=New-ScheduledTaskAction -Execute ([string]$old.Execute) -Argument $arguments -WorkingDirectory $workingDirectory
    }
    [void](Set-ScheduledTask -TaskName $Name -Action $action)
    $stored=Get-ScheduledTask -TaskName $Name
    $storedArguments=[string]$stored.Actions[0].Arguments
    if($storedArguments-notmatch'(?i)-ImageSourceRoot\s+"?'+[regex]::Escape($SourceValue)){throw 'BRIDGE_IMAGE_SOURCE_READBACK_FAILED'}
    if($storedArguments-notmatch'(?i)-ImageArchiveRoot\s+"?'+[regex]::Escape($ArchiveValue)){throw 'BRIDGE_IMAGE_ARCHIVE_READBACK_FAILED'}
    if($storedArguments-notmatch'(?i)-ImageCommandKeyPath\s+"?'+[regex]::Escape($KeyPathValue)){throw 'BRIDGE_IMAGE_KEY_READBACK_FAILED'}
}
Require-Administrator
Set-ArchiveAcl $ImageArchiveRoot $AppPoolName
Ensure-ImageCommandKey $ImageArchiveRoot $ImageCommandKeyPath
Set-AppPoolEnvironment $AppPoolName $ImageArchiveRoot $ImageCommandKeyPath
Set-BridgeAction $TaskName $ImageSourceRoot $ImageArchiveRoot $ImageCommandKeyPath
if($Activate){
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    $deadline=[DateTime]::UtcNow.AddSeconds(20);do{Start-Sleep -Milliseconds 250;$state=(Get-ScheduledTask -TaskName $TaskName).State}while($state-eq'Running'-and[DateTime]::UtcNow-lt$deadline)
    Start-ScheduledTask -TaskName $TaskName
    Restart-WebAppPool -Name $AppPoolName
}
[ordered]@{ok=$true;image_source_root=$ImageSourceRoot;archive_root=$ImageArchiveRoot;app_pool=$AppPoolName;task=$TaskName;activated=[bool]$Activate}|ConvertTo-Json -Compress
