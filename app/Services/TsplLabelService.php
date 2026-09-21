<?php

namespace App\Services;

use App\Models\PosSetting;

/**
 * Builds raw TSPL/TSPL2 command payloads for thermal label printers
 * (TSC, 4BARCODE, Xprinter, Rongta… — anything TSPL-compatible).
 *
 * The barcode is rendered by the printer's own BARCODE command, so bars are
 * printed at native resolution instead of being rasterized from HTML.
 * Coordinates are in dots at 203 dpi (8 dots/mm).
 */
class TsplLabelService
{
    /**
     * Set per job from pos_settings.label_printer_dpi (203 or 300). TSPL
     * coordinates are in dots, so a 300 dpi head fed 203 dpi coordinates
     * prints everything at two-thirds scale in the top-left corner.
     */
    private float $dotsPerMm = 8;    // 203 dpi
    private float $pxToDots = 2.1167; // CSS px (96 dpi) -> dots

    /** User position adjustment in dots, baked into all drawing coordinates. */
    private int $offsetX = 0;
    private int $offsetY = 0;

    /**
     * TSPL built-in bitmap fonts: name => [char width, char height] in dots.
     * These are the sizes universally supported by TSPL clones.
     */
    const FONTS = [
        '1' => [8, 12],
        '2' => [12, 20],
        '3' => [16, 24],
        '4' => [24, 32],
    ];

    /**
     * TSPL clones (4BARCODE, Xprinter…) render their bitmap fonts slightly
     * wider than the nominal cell above. Over-estimating the advance keeps a
     * long product name from filling the whole printable width — text that
     * reached both edges was being clipped left and right by the ~1 mm the
     * label roll normally sits off-centre under the head.
     */
    const FONT_WIDTH_SAFETY = 1.10;

    /**
     * Side margin for text lines, in mm — wider than the 1 mm element margin.
     * 3 mm because die-cut stickers have rounded corners: the price sits in
     * the bottom-right corner, and a smaller inset puts its last character
     * inside the corner radius where there is no paper.
     */
    const TEXT_MARGIN_MM = 3;

    /**
     * @param array      $labels  [{name, barcode, Type_barcode, Net_price, qte}]
     * @param array      $design  {width_mm, height_mm, show_name, show_price,
     *                            show_barcode, show_barcode_number, barcode_height,
     *                            font_size, number_font_size, bold_font, currency}
     * @param PosSetting $printer gap/density/speed/direction come from here
     */
    public function build(array $labels, array $design, PosSetting $printer): string
    {
        $dpi = ((int) ($printer->label_printer_dpi ?? 203)) === 300 ? 300 : 203;
        $this->dotsPerMm = $dpi === 300 ? 12 : 8;
        $this->pxToDots = $dpi / 96;

        $widthMm = $this->clampFloat($design['width_mm'] ?? 50, 20, 200);
        $heightMm = $this->clampFloat($design['height_mm'] ?? 30, 10, 200);
        $gapMm = $this->clampFloat($printer->label_printer_gap_mm ?? 2, 0, 10);
        $density = max(0, min(15, (int) ($printer->label_printer_density ?? 8)));
        $speed = max(1, min(8, (int) ($printer->label_printer_speed ?? 3)));
        $direction = ((int) ($printer->label_printer_direction ?? 0)) === 1 ? 1 : 0;

        $out = "SIZE {$widthMm} mm,{$heightMm} mm\r\n";
        $out .= "GAP {$gapMm} mm,0 mm\r\n";
        $out .= "DIRECTION {$direction}\r\n";
        // Position offsets are baked into every drawing coordinate rather
        // than sent as REFERENCE/SHIFT — clone firmwares (NC-TP230 class)
        // ignore those commands. They are still emitted, zeroed, to clear
        // state another program may have stored in the printer.
        $this->offsetX = (int) round(max(-10, min(10, (float) ($printer->label_printer_offset_x_mm ?? 0))) * $this->dotsPerMm);
        $this->offsetY = (int) round(max(-10, min(10, (float) ($printer->label_printer_offset_y_mm ?? 0))) * $this->dotsPerMm);
        $out .= "REFERENCE 0,0\r\n";
        $out .= "SHIFT 0\r\n";
        $out .= "OFFSET 0 mm\r\n";
        $out .= "DENSITY {$density}\r\n";
        $out .= "SPEED {$speed}\r\n";
        // Tear-feed advances the label to the tear bar after the job; clones
        // that don't back-feed then start every following label 15-20 mm too
        // far in. Sent explicitly both ways because the printer remembers the
        // last state.
        // SET BACK ON asks models that support it to reverse to the print
        // position before the next label, so tear mode doesn't shift jobs.
        $out .= ! empty($printer->label_printer_tear)
            ? "SET TEAR ON\r\nSET BACK ON\r\n"
            : "SET TEAR OFF\r\n";

        foreach ($labels as $label) {
            $qty = max(1, min(1000, (int) ($label['qte'] ?? 1)));
            $out .= $this->buildOne($label, $design, $widthMm, $heightMm);
            $out .= "PRINT 1,{$qty}\r\n";
        }

        return $out;
    }

    private function buildOne(array $label, array $design, float $widthMm, float $heightMm): string
    {
        $w = (int) round($widthMm * $this->dotsPerMm);
        $h = (int) round($heightMm * $this->dotsPerMm);
        // A positive vertical offset means printing starts that far before
        // the sticker — those last rows never reach it, so lay out within
        // the height that does.
        $h -= max(0, $this->offsetY);
        $margin = (int) round($this->dotsPerMm); // 1 mm safe margin

        // Missing keys default to visible so a sparse design payload still
        // yields a printable label instead of a blank one.
        $showName = (bool) ($design['show_name'] ?? true);
        $showBarcode = (bool) ($design['show_barcode'] ?? true);
        $showNumber = (bool) ($design['show_barcode_number'] ?? true);
        $showPrice = (bool) ($design['show_price'] ?? true);
        $bold = (bool) ($design['bold_font'] ?? true);

        $nameFont = $this->pickFont((int) ($design['font_size'] ?? 10));
        $priceFont = $nameFont;

        $out = "CLS\r\n";
        // Alignment frame for test prints, inset 1 mm — at the extreme edges
        // it would land on the liner of die-cut stickers and stay invisible.
        if (! empty($design['test_frame'])) {
            $out .= sprintf(
                "BOX %d,%d,%d,%d,2\r\n",
                max(0, $margin + $this->offsetX), max(0, $margin + $this->offsetY),
                max(0, $w - $margin + $this->offsetX), max(0, $h - $margin + $this->offsetY)
            );
        }
        $y = $margin;

        // ---- Price line height reserved at the bottom ----
        [$pfw, $pfh] = self::FONTS[$priceFont];
        $priceReserve = $showPrice ? ($pfh + $margin + 2) : $margin;

        // ---- Product name: centered, as many lines as the space allows ----
        // (up to 3), after reserving room for the price and a minimum-height
        // barcode. A fixed 2-line clamp truncated longer product names even
        // when the label had spare room.
        $textMargin = (int) round(self::TEXT_MARGIN_MM * $this->dotsPerMm);
        if ($showName) {
            [$fw, $fh] = self::FONTS[$nameFont];
            $barcodeReserve = $showBarcode
                ? 24 + ($showNumber ? self::FONTS['2'][1] + 2 : 0)
                : 0;
            $maxLines = max(1, min(3, intdiv($h - $margin - $priceReserve - $barcodeReserve, $fh + 2)));
            $usable = $w - 2 * $textMargin;
            $maxChars = max(4, (int) floor($usable / ($fw * self::FONT_WIDTH_SAFETY)));
            $lines = $this->wrapText($this->sanitize((string) ($label['name'] ?? '')), $maxChars, $maxLines);
            foreach ($lines as $line) {
                $x = max($textMargin, (int) round(($w - $this->textWidth($line, $nameFont)) / 2));
                $out .= $this->text($x, $y, $nameFont, $line, $bold);
                $y += $fh + 2;
            }
            $y += 2;
        }

        // ---- Barcode: centered, printer-rendered ----
        if ($showBarcode) {
            $code = $this->sanitize((string) ($label['barcode'] ?? ''));
            if ($code !== '') {
                $type = $this->mapSymbology((string) ($label['Type_barcode'] ?? 'CODE128'), $code);
                $numberDots = $showNumber ? (self::FONTS['2'][1] + 2) : 0;

                // Requested height in dots, clamped to the space that is left
                // and to 45% of the label so an oversized design value cannot
                // swallow the sticker.
                $requested = (int) round($this->clampFloat($design['barcode_height'] ?? 28, 10, 100) * $this->pxToDots);
                $available = $h - $y - $priceReserve - $numberDots;
                $bcHeight = max(24, min($requested, (int) round($h * 0.45), $available));

                // Target ~80% of the label width, like dedicated label
                // software — full edge-to-edge bars also lose their quiet
                // zones and scan worse.
                $narrow = 2;
                $estWidth = $this->estimateBarcodeWidth($type, $code, $narrow);
                if ($estWidth > (int) round($w * 0.8)) {
                    $narrow = 1;
                    $estWidth = $this->estimateBarcodeWidth($type, $code, $narrow);
                }
                $x = max($margin, (int) (($w - $estWidth) / 2));
                $readable = $showNumber ? 2 : 0; // 2 = human-readable text centered below

                $out .= sprintf(
                    "BARCODE %d,%d,\"%s\",%d,%d,0,%d,%d,\"%s\"\r\n",
                    max(0, $x + $this->offsetX), max(0, $y + $this->offsetY),
                    $type, $bcHeight, $readable, $narrow, $narrow * 2, $code
                );
            }
        }

        // ---- Price: bottom-right corner ----
        if ($showPrice) {
            // The bitmap fonts are ASCII-only, so a non-Latin currency symbol
            // (د.إ, ₹…) would be stripped — or reduced to punctuation
            // remnants like the "." inside د.إ — and the price would print
            // bare or as ". 49.99". Fall back to the ASCII code ("AED")
            // whenever nothing recognizable (letter, digit, $/£-style sign)
            // survives sanitization.
            $currency = (string) ($design['currency'] ?? '');
            if ($currency !== '' && ! preg_match('/[A-Za-z0-9$]/', $this->sanitize($currency))) {
                $currency = (string) ($design['currency_ascii'] ?? '');
            }
            $price = $this->sanitize(trim($currency . ' ' . ($label['Net_price'] ?? '')));
            if ($price !== '') {
                // Right edge aligned with the barcode's right edge (10%
                // inset) — clear of the sticker edge/corner on any roll.
                $priceInset = max($textMargin, (int) round($w * 0.1));
                $x = max($textMargin, $w - $priceInset - $this->textWidth($price, $priceFont));
                $out .= $this->text($x, $h - $pfh - $margin, $priceFont, $price, $bold);
            }
        }

        return $out;
    }

    /** TEXT command; bold is simulated by overprinting shifted 1 dot right. */
    private function text(int $x, int $y, string $font, string $content, bool $bold): string
    {
        $x = max(0, $x + $this->offsetX);
        $y = max(0, $y + $this->offsetY);
        $cmd = sprintf("TEXT %d,%d,\"%s\",0,1,1,\"%s\"\r\n", $x, $y, $font, $content);
        if ($bold) {
            $cmd .= sprintf("TEXT %d,%d,\"%s\",0,1,1,\"%s\"\r\n", $x + 1, $y, $font, $content);
        }
        return $cmd;
    }

    private function pickFont(int $px): string
    {
        if ($px <= 7) return '1';
        if ($px <= 11) return '2';
        if ($px <= 15) return '3';
        return '4';
    }

    private function mapSymbology(string $type, string $code = ''): string
    {
        $mapped = match (strtoupper($type)) {
            'EAN13' => 'EAN13',
            'EAN8' => 'EAN8',
            'UPC', 'UPCA' => 'UPCA',
            'CODE39' => '39',
            default => '128',
        };
        // The printer prints nothing when the data doesn't fit the symbology
        // (wrong digit count for EAN/UPC, lowercase in CODE39…). Fall back to
        // CODE128, which accepts anything, so the label never comes out blank.
        return $this->symbologyFits($mapped, $code) ? $mapped : '128';
    }

    private function symbologyFits(string $mapped, string $code): bool
    {
        return match ($mapped) {
            'EAN13' => (bool) preg_match('/^\d{12,13}$/', $code),
            'EAN8' => (bool) preg_match('/^\d{7,8}$/', $code),
            'UPCA' => (bool) preg_match('/^\d{11,12}$/', $code),
            '39' => (bool) preg_match('#^[0-9A-Z\-. $/+%]+$#', $code),
            default => true,
        };
    }

    /** Printed width of a text line in dots, including the safety factor. */
    private function textWidth(string $text, string $font): int
    {
        return (int) ceil(strlen($text) * self::FONTS[$font][0] * self::FONT_WIDTH_SAFETY);
    }

    /** Module-count estimate, used only to center the barcode. */
    private function estimateBarcodeWidth(string $type, string $code, int $narrow): int
    {
        $len = strlen($code);
        $modules = match ($type) {
            'EAN13', 'UPCA' => 95,
            'EAN8' => 67,
            // 9 elements per character of which 3 are wide, plus a narrow
            // inter-character gap; with wide = 2 x narrow that is 13 narrow
            // units per character, and start/stop add two more characters.
            '39' => 13 * ($len + 2),
            default => $this->code128Modules($code),
        };
        return (int) round($modules * $narrow);
    }

    /**
     * CODE128 width in modules: start + data + checksum symbols at 11 modules
     * each, plus the 13-module stop bar. Numeric data is packed two digits per
     * symbol by code set C, so assuming the code-set-B worst case made an
     * all-digit barcode look ~40% wider than it prints and pushed it visibly
     * left of center.
     */
    private function code128Modules(string $code): int
    {
        $len = strlen($code);
        if ($len > 0 && ctype_digit($code)) {
            // An odd digit count leaves one digit in set B: one extra symbol
            // for the digit and one for the code-set switch.
            $symbols = intdiv($len, 2) + ($len % 2 === 0 ? 0 : 2);
        } else {
            $symbols = $len;
        }
        return 11 * ($symbols + 2) + 13;
    }

    /** Wrap on word boundaries into at most $maxLines lines; truncate the rest. */
    private function wrapText(string $text, int $maxChars, int $maxLines): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return [];
        }
        $wrapped = explode("\n", wordwrap($text, $maxChars, "\n", true));
        $lines = array_slice($wrapped, 0, $maxLines);
        if (count($wrapped) > $maxLines) {
            $last = $lines[$maxLines - 1];
            $lines[$maxLines - 1] = substr($last, 0, max(1, $maxChars - 1)) . '.';
        }
        return $lines;
    }

    /**
     * TSPL bitmap fonts are ASCII; strip quotes/backslashes (command syntax)
     * and transliterate anything non-ASCII.
     */
    private function sanitize(string $value): string
    {
        $value = str_replace(['"', '\\'], '', $value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '', $value);
        } elseif (strpos($value, '?') === false) {
            // iconv renders anything it cannot transliterate as '?'. Drop those
            // instead of printing junk on the label — a currency symbol the
            // bitmap fonts don't have (₹, ﷼, د.م.…) would otherwise come out
            // as "?49.99". Only safe when the source had no '?' of its own.
            $ascii = str_replace('?', '', $ascii);
        }
        return trim($ascii);
    }

    private function clampFloat($value, float $min, float $max): float
    {
        return max($min, min($max, (float) $value));
    }
}
