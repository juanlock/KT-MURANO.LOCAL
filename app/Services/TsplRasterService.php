<?php

namespace App\Services;

use App\Models\PosSetting;
use InvalidArgumentException;

/**
 * Wraps browser-rendered label images in a TSPL BITMAP job.
 *
 * The alternative to TsplLabelService: instead of TEXT/BARCODE commands drawn
 * with the printer's built-in fonts, the browser rasterizes the label design
 * at 203 dpi (see resources/src/lib/labelRaster.js) and the printer just
 * stamps the resulting image. The print then matches the on-screen preview
 * exactly — real fonts, any language — which the printer's bitmap fonts can
 * never do.
 *
 * The client only ever supplies image bits: every TSPL command around them is
 * built here, so a hostile payload cannot inject printer commands.
 */
class TsplRasterService
{
    const MAX_DOTS = 2400;          // 200 mm at 300 dpi — far beyond any label
    const MAX_LABELS = 200;

    /**
     * @param array $rasters [{data: base64 1-bit rows, width_bytes, height, qte}]
     * @param array $design  {width_mm, height_mm}
     */
    public function build(array $rasters, array $design, PosSetting $printer): string
    {
        if ($rasters === [] || count($rasters) > self::MAX_LABELS) {
            throw new InvalidArgumentException('No label images to print.');
        }

        $widthMm = $this->clampFloat($design['width_mm'] ?? 50, 20, 200);
        $heightMm = $this->clampFloat($design['height_mm'] ?? 30, 10, 200);
        $gapMm = $this->clampFloat($printer->label_printer_gap_mm ?? 2, 0, 10);
        $density = max(0, min(15, (int) ($printer->label_printer_density ?? 8)));
        $speed = max(1, min(8, (int) ($printer->label_printer_speed ?? 3)));
        $direction = ((int) ($printer->label_printer_direction ?? 0)) === 1 ? 1 : 0;

        $out = "SIZE {$widthMm} mm,{$heightMm} mm\r\n";
        $out .= "GAP {$gapMm} mm,0 mm\r\n";
        $out .= "DIRECTION {$direction}\r\n";
        // Position offsets are baked into the client-rendered bitmap (clone
        // firmwares ignore REFERENCE/SHIFT); these are sent zeroed only to
        // clear state another program may have stored in the printer.
        $out .= "REFERENCE 0,0\r\n";
        $out .= "SHIFT 0\r\n";
        $out .= "OFFSET 0 mm\r\n";
        $out .= "DENSITY {$density}\r\n";
        $out .= "SPEED {$speed}\r\n";
        // Explicit both ways — the printer stores the last state, and clones
        // without back-feed shift every following label when tear is on.
        // SET BACK ON asks models that support it to reverse to the print
        // position before the next label, so tear mode doesn't shift jobs.
        $out .= ! empty($printer->label_printer_tear)
            ? "SET TEAR ON\r\nSET BACK ON\r\n"
            : "SET TEAR OFF\r\n";

        foreach ($rasters as $raster) {
            $out .= $this->buildOne($raster);
        }

        return $out;
    }

    private function buildOne(array $raster): string
    {
        $widthBytes = (int) ($raster['width_bytes'] ?? 0);
        $height = (int) ($raster['height'] ?? 0);
        $qty = max(1, min(1000, (int) ($raster['qte'] ?? 1)));

        if ($widthBytes < 1 || $height < 1
            || $widthBytes * 8 > self::MAX_DOTS || $height > self::MAX_DOTS) {
            throw new InvalidArgumentException('Label image dimensions are out of range.');
        }

        $bits = base64_decode((string) ($raster['data'] ?? ''), true);
        if ($bits === false || strlen($bits) !== $widthBytes * $height) {
            throw new InvalidArgumentException('Label image data does not match its dimensions.');
        }

        // BITMAP x,y,width_in_bytes,height_in_dots,mode,<raw bytes>
        // mode 0 = OVERWRITE. A cleared bit prints a dot, so the client sends
        // 1 for white and 0 for black.
        return "CLS\r\n"
            . "BITMAP 0,0,{$widthBytes},{$height},0," . $bits . "\r\n"
            . "PRINT 1,{$qty}\r\n";
    }

    private function clampFloat($value, float $min, float $max): float
    {
        return max($min, min($max, (float) $value));
    }
}
