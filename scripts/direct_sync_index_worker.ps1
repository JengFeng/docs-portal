[CmdletBinding()]
param([string]$AppPoolName='TWWATER_PortalPool',[string]$PhpExecutable='C:\PHP\8.5.9\php.exe',[switch]$Diagnostic)
$ErrorActionPreference='Stop'
Add-Type -Path (Join-Path $env:windir 'System32\inetsrv\Microsoft.Web.Administration.dll') -ErrorAction SilentlyContinue
$manager=New-Object Microsoft.Web.Administration.ServerManager
try {
  $pool=$manager.ApplicationPools[$AppPoolName]; if($null -eq $pool){throw 'APP_POOL_NOT_FOUND'}
  foreach($entry in $pool.GetChildElement('environmentVariables').GetCollection()) {
    $name=[string]$entry['name']; if($name -like 'PORTAL_*') { Set-Item -Path ('Env:'+ $name) -Value ([string]$entry['value']) }
  }
} finally { $manager.Dispose() }
if(-not(Test-Path -LiteralPath $PhpExecutable -PathType Leaf)){ throw 'IIS_PHP_CLI_NOT_FOUND' }
$script=Join-Path $PSScriptRoot 'sync_documents.php'
$arguments=@($script)
if($Diagnostic){$arguments += '--diagnostic'}
& $PhpExecutable @arguments
exit $LASTEXITCODE
