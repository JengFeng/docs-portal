$ErrorActionPreference='Stop'
$root=Join-Path $env:LOCALAPPDATA ('Temp\twwater-atomic-'+[Guid]::NewGuid().ToString('N'))
$folder=Join-Path $root 'folder'
New-Item -ItemType Directory -Path $folder -Force|Out-Null
$relative='folder/report.pdf'
$target=Join-Path $folder 'report.pdf'
$operation=[Guid]::NewGuid().ToString()
$candidateName='.'+$operation+'.replace.pdf'
$candidate=Join-Path $folder $candidateName
[IO.File]::WriteAllText($target,'old-bytes')
[IO.File]::WriteAllText($candidate,'new-bytes')
function Get-TestFileHash([string]$Path) { (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }
$old=Get-TestFileHash $target
$new=Get-TestFileHash $candidate
$env:TWWATER_ATOMIC_HELPER_TEST_MODE='1'
$env:TWWATER_ATOMIC_HELPER_TEST_ROOT=$root
try {
    $helper=Join-Path $PSScriptRoot 'atomic-replace-document.ps1'
    $json=& powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File $helper -RelativePath $relative -CandidateName $candidateName -ExpectedHash $old -CandidateHash $new
    if($LASTEXITCODE-ne0){throw ('success path failed: '+($json -join ' '))}
    $result=$json|ConvertFrom-Json
    if(-not$result.ok -or (Get-TestFileHash $target)-ne$new -or(Test-Path -LiteralPath $candidate)){throw 'success invariant failed'}
    $candidate2='.'+[Guid]::NewGuid().ToString()+'.replace.pdf'
    $path2=Join-Path $folder $candidate2
    [IO.File]::WriteAllText($path2,'third-bytes')
    $third=Get-TestFileHash $path2
    $ignored=& powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File $helper -RelativePath $relative -CandidateName $candidate2 -ExpectedHash $old -CandidateHash $third
    if($LASTEXITCODE-eq0 -or(Get-TestFileHash $target)-ne$new){throw 'conflict path did not fail closed'}
    Write-Output '[OK] Atomic File.Replace success/conflict test passed with unchanged production root.'
} finally {
    $env:TWWATER_ATOMIC_HELPER_TEST_MODE=$null
    $env:TWWATER_ATOMIC_HELPER_TEST_ROOT=$null
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
