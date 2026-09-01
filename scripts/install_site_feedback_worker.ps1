[CmdletBinding()]
param(
    [ValidateNotNullOrEmpty()][string] $RuntimeRoot = 'C:\TWWATER\runtime\staged-commit-channel',
    [ValidateNotNullOrEmpty()][string] $ProjectRoot = 'C:\web\gary\TWWATER',
    [ValidateNotNullOrEmpty()][string] $AppPoolIdentity = 'IIS AppPool\TWWATER_PortalPool',
    [ValidateNotNullOrEmpty()][string] $AppPoolName = 'TWWATER_PortalPool',
    [ValidateNotNullOrEmpty()][string] $TaskName = 'TWWATER Site Feedback Worker',
    [switch] $DoNotStartTask
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity=[Security.Principal.WindowsIdentity]::GetCurrent()
    $principal=[Security.Principal.WindowsPrincipal]::new($identity)
    if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'Administrator rights are required.'}
}
function Assert-RealDirectory([string]$Path,[string]$Label){
    $item=Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if(-not $item.PSIsContainer -or [bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw "$Label must be a real directory."}
    return $item.FullName.TrimEnd('\','/')
}
function ConvertTo-Sid([string]$Identity){
    if($Identity.StartsWith('S-1-')){return [Security.Principal.SecurityIdentifier]::new($Identity)}
    return [Security.Principal.NTAccount]::new($Identity).Translate([Security.Principal.SecurityIdentifier])
}
function Set-ProtectedAcl([string]$Path,[object[]]$Entries){
    $acl=[Security.AccessControl.DirectorySecurity]::new();$acl.SetAccessRuleProtection($true,$false)
    $acl.SetOwner((ConvertTo-Sid 'S-1-5-32-544'))
    $inherit=[Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    foreach($entry in $Entries){$rule=[Security.AccessControl.FileSystemAccessRule]::new((ConvertTo-Sid ([string]$entry.Identity)),[Security.AccessControl.FileSystemRights]$entry.Rights,$inherit,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow);[void]$acl.AddAccessRule($rule)}
    [IO.Directory]::SetAccessControl($Path,$acl)
}
function Set-ProtectedFileAcl([string]$Path,[object[]]$Entries){
    $acl=[Security.AccessControl.FileSecurity]::new();$acl.SetAccessRuleProtection($true,$false);$acl.SetOwner((ConvertTo-Sid 'S-1-5-32-544'))
    foreach($entry in $Entries){$rule=[Security.AccessControl.FileSystemAccessRule]::new((ConvertTo-Sid ([string]$entry.Identity)),[Security.AccessControl.FileSystemRights]$entry.Rights,[Security.AccessControl.AccessControlType]::Allow);[void]$acl.AddAccessRule($rule)}
    [IO.File]::SetAccessControl($Path,$acl)
}
function Assert-ProtectedRegularFile([string]$Path){
    $item=Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if($item.PSIsContainer -or [bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Site feedback key must be a regular non-reparse file.'}
    $acl=[IO.File]::GetAccessControl($item.FullName)
    if(-not $acl.AreAccessRulesProtected -or $acl.Owner -ne (ConvertTo-Sid 'S-1-5-32-544').Value){throw 'Site feedback key owner or ACL is invalid.'}
}
function Quote-TaskArg([string]$Value){if($Value.Contains('"') -or $Value.IndexOf([char]0)-ge 0){throw 'Unsafe task argument.'};return '"'+$Value+'"'}

Assert-Administrator
Add-Type -AssemblyName System.Security
$runtime=Assert-RealDirectory $RuntimeRoot 'RuntimeRoot'
$project=Assert-RealDirectory $ProjectRoot 'ProjectRoot'
$worker=Join-Path $project 'scripts\site_feedback_worker.py'
if(-not (Test-Path -LiteralPath $worker -PathType Leaf)){throw 'Worker script is missing.'}
$siteRoot=Join-Path $runtime 'site-feedback'
if(-not (Test-Path -LiteralPath $siteRoot)){New-Item -ItemType Directory -Path $siteRoot | Out-Null}
$siteRoot=Assert-RealDirectory $siteRoot 'SiteFeedbackRoot'
$current=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$common=@(
    @{Identity=$current;Rights='FullControl'},@{Identity='S-1-5-32-544';Rights='FullControl'},@{Identity='S-1-5-18';Rights='FullControl'}
)
Set-ProtectedAcl $siteRoot ($common+@(@{Identity=$AppPoolIdentity;Rights='ReadAndExecute'}))
$workerTemp=Join-Path $siteRoot 'worker-tmp'
if(-not(Test-Path -LiteralPath $workerTemp)){New-Item -ItemType Directory -Path $workerTemp|Out-Null}
$workerTemp=Assert-RealDirectory $workerTemp 'WorkerTemp'
Set-ProtectedAcl $workerTemp $common
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll')
$manager=[Microsoft.Web.Administration.ServerManager]::OpenRemote('localhost')
try{
    $pool=$manager.ApplicationPools[$AppPoolName];if($null-eq$pool){throw 'AppPool not found.'}
    $rateKey='';foreach($entry in $pool.GetChildElement('environmentVariables').GetCollection()){if([string]$entry['name']-ceq'PORTAL_RATE_KEY'){$rateKey=[string]$entry['value'];break}}
    if($rateKey.Length-lt 32){throw 'PORTAL_RATE_KEY is unavailable or too short.'}
}finally{$manager.Dispose()}
$keyFile=Join-Path $siteRoot 'worker-key.dpapi'
$entropy=[Text.Encoding]::UTF8.GetBytes('TWWATER-site-feedback-worker-v1')
$protected=[Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($rateKey),$entropy,[Security.Cryptography.DataProtectionScope]::CurrentUser)
[IO.File]::WriteAllText($keyFile,[Convert]::ToBase64String($protected),[Text.UTF8Encoding]::new($false))
Set-ProtectedFileAcl $keyFile $common
Assert-ProtectedRegularFile $keyFile
$rateKey=$null;$protected=$null
foreach($kind in @('analysis')){
    $request=Join-Path $siteRoot ($kind+'-requests');$result=Join-Path $siteRoot ($kind+'-results')
    foreach($path in @($request,$result)){if(-not(Test-Path -LiteralPath $path)){New-Item -ItemType Directory -Path $path|Out-Null};$null=Assert-RealDirectory $path $kind}
    Set-ProtectedAcl $request ($common+@(@{Identity=$AppPoolIdentity;Rights='Modify'}))
    Set-ProtectedAcl $result ($common+@(@{Identity=$AppPoolIdentity;Rights='ReadAndExecute'}))
}
$python=(Get-Command python -ErrorAction Stop).Source
$arguments=@((Quote-TaskArg $worker),'--root',(Quote-TaskArg $siteRoot),'--project-root',(Quote-TaskArg $project),'--key-file',(Quote-TaskArg $keyFile)) -join ' '
$action=New-ScheduledTaskAction -Execute $python -Argument $arguments -WorkingDirectory $project
$trigger=New-ScheduledTaskTrigger -AtLogOn -User $current
$principal=New-ScheduledTaskPrincipal -UserId $current -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
$task=New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Processes authenticated TWWATER visual-feedback analysis only; code execution requires Gary confirmation in Discord.'
[void](Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force)
if(-not $DoNotStartTask){Start-ScheduledTask -TaskName $TaskName}
Write-Output "[OK] Scheduled task installed: $TaskName"
Write-Output "[OK] Queue root: $siteRoot; AppPool requests=Modify, results=ReadAndExecute."
