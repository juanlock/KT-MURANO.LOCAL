# Recorta las capturas al area de contenido (sin menu lateral ni barra superior: el logo del menu es de otra compania)
# y las guarda como JPEG de 1100 px en img/. powershell -File KT-TOOLS/murano/flyer/crop.ps1
Add-Type -AssemblyName System.Drawing
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$src = Join-Path $dir 'shots'; $dst = Join-Path $dir 'img'
New-Item -ItemType Directory -Force $dst | Out-Null
# name = x, y, w, h (pixels of the 2160x1350 capture); cover = rectangles painted white
$crops = @{
  dashboard    = @(380, 130, 1760, 910)
  sales        = @(380, 130, 1760, 1000)
  sale_detail  = @(380, 130, 1760, 1060)
  accounting   = @(380, 130, 1760, 960)
  assets       = @(380, 130, 1760, 960)
  attendance   = @(380, 130, 1760, 900)
  employees    = @(380, 130, 1760, 960)
  payrolls     = @(380, 130, 1760, 960)
  products     = @(380, 130, 1760, 960)
  stock        = @(380, 130, 1760, 960)
  purchases    = @(380, 130, 1760, 960)
  store_orders = @(380, 130, 1760, 820)
}
$cover = @{ sale_detail = @(, @(415, 245, 190, 115)) }
$enc = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
$ep = New-Object System.Drawing.Imaging.EncoderParameters 1
$ep.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality), 84L
foreach ($k in $crops.Keys) {
  $im = [System.Drawing.Image]::FromFile((Join-Path $src "$k.png"))
  $c = $crops[$k]; $w = 1100; $h = [int]($c[3] * $w / $c[2])
  $bmp = New-Object System.Drawing.Bitmap $w, $h; $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.InterpolationMode = 'HighQualityBicubic'; $g.PixelOffsetMode = 'HighQuality'
  $g.DrawImage($im, (New-Object System.Drawing.Rectangle 0, 0, $w, $h), (New-Object System.Drawing.Rectangle $c[0], $c[1], $c[2], $c[3]), 'Pixel')
  if ($cover.ContainsKey($k)) { foreach ($r in $cover[$k]) { $s = $w / $c[2]; $g.FillRectangle([System.Drawing.Brushes]::White, [int](($r[0] - $c[0]) * $s), [int](($r[1] - $c[1]) * $s), [int]($r[2] * $s), [int]($r[3] * $s)) } }
  $bmp.Save((Join-Path $dst "$k.jpg"), $enc, $ep); $g.Dispose(); $bmp.Dispose(); $im.Dispose()
  "$k ${w}x$h"
}
