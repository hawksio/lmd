# Downloads every photo listed in images.json from the Unsplash CDN as
# pre-cropped, pre-compressed AVIF + WebP (+ JPG fallback) variants.
#   lg/md = desktop aspect ratio, sm = mobile art-directed crop.
# Usage:  powershell -ExecutionPolicy Bypass -File tools/fetch-images.ps1
$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot
$out    = Join-Path $root 'public/images'
$cfg    = Get-Content (Join-Path $PSScriptRoot 'images.json') -Raw | ConvertFrom-Json

foreach ($img in $cfg.images) {
  $dir = Join-Path $out (Split-Path $img.file -Parent)
  New-Item -ItemType Directory -Force $dir | Out-Null
  foreach ($vName in 'lg', 'md', 'sm') {
    $v = $cfg.variants.$vName
    $r = if ($v.ratio -eq 'mobile') { $img.mobile } else { $img.desktop }
    $w = $v.w; $h = [int]([math]::Round($w * $r[1] / $r[0]))
    $formats = @('avif', 'webp'); if ($vName -eq 'md') { $formats += 'jpg' }
    foreach ($fm in $formats) {
      $q = if ($fm -eq 'avif') { 55 } else { 72 }
      $url  = "https://images.unsplash.com/photo-$($img.id)?w=$w&h=$h&fit=crop&crop=$($img.crop)&fm=$fm&q=$q"
      $dest = Join-Path $out "$($img.file)-$vName.$fm"
      if (-not (Test-Path $dest)) { Invoke-WebRequest -Uri $url -OutFile $dest -UseBasicParsing }
    }
  }
  Write-Output "ok  $($img.file)"
}
