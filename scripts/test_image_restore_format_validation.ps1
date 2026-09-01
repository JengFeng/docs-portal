[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'document_version_engine.ps1')
Add-Type -AssemblyName System.Drawing
function Expect([scriptblock]$Action,[string]$Code){$actual='';try{&$Action}catch{$actual=$_.Exception.Message};if($actual-ne$Code){throw "Expected $Code, got $actual"}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('twwater-restore-format-'+[Guid]::NewGuid().ToString('N'));[void][IO.Directory]::CreateDirectory($root)
try{$png=Join-Path $root 'valid.png';$jpg=Join-Path $root 'valid.jpg';$fake=Join-Path $root 'fake.png';$text=Join-Path $root 'text.png';$gif=Join-Path $root 'image.gif';$webp=Join-Path $root 'image.webp';$b=[Drawing.Bitmap]::new(4,4);try{$b.SetPixel(0,0,[Drawing.Color]::Red);$b.Save($png,[Drawing.Imaging.ImageFormat]::Png);$b.Save($jpg,[Drawing.Imaging.ImageFormat]::Jpeg);$b.Save($fake,[Drawing.Imaging.ImageFormat]::Jpeg);$b.Save($gif,[Drawing.Imaging.ImageFormat]::Gif)}finally{$b.Dispose()};[IO.File]::WriteAllText($text,'not-image');[IO.File]::WriteAllBytes($webp,[byte[]](0x52,0x49,0x46,0x46,0x04,0,0,0,0x57,0x45,0x42,0x50));Assert-TWWaterRestorableImage $png 'png';Assert-TWWaterRestorableImage $jpg 'jpg';Expect {Assert-TWWaterRestorableImage $fake 'png'} 'IMAGE_FORMAT_MISMATCH';Expect {Assert-TWWaterRestorableImage $text 'png'} 'IMAGE_FORMAT_MISMATCH';Expect {Assert-TWWaterRestorableImage $gif 'gif'} 'IMAGE_FORMAT_UNSUPPORTED';Expect {Assert-TWWaterRestorableImage $webp 'webp'} 'IMAGE_FORMAT_UNSUPPORTED';Write-Host '[OK] Restore accepts decoded PNG/JPEG and rejects disguised/unsupported image bytes.'}finally{if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
