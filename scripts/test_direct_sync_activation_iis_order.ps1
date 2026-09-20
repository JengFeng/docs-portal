$ErrorActionPreference='Stop'
$text=[Text.Encoding]::UTF8.GetString([IO.File]::ReadAllBytes((Join-Path $PSScriptRoot 'activate_direct_sync_source.ps1')))
$valueIndex=$text.IndexOf("`$entry['value']=`$pair.Value")
$addIndex=$text.IndexOf('[void]$variables.Add($entry)')
if($valueIndex -lt 0 -or $addIndex -lt 0 -or $valueIndex -gt $addIndex){throw 'IIS_ENVIRONMENT_VALUE_MUST_BE_SET_BEFORE_ADD'}
Write-Output 'IIS_ENVIRONMENT_ORDER_CONTRACT_OK'
