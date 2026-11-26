<?php

namespace App\Support;

/**
 * A small text extractor for simple report PDFs (FlateDecode content streams with Tj / TJ literal strings), enough for
 * Jelaf's "Reporte de ventas web" (one text item per line). Not a general PDF reader: no CID fonts, no hex strings.
 */
class PdfText
{
    /** @return string[] the text lines in page order */
    public static function lines(string $file): array
    {
        $raw = (string) file_get_contents($file);
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $raw, $m);
        unset($raw);
        $out = [];
        foreach ($m[1] as $s) {
            $d = @gzuncompress($s);
            if ($d === false) {
                $d = @gzinflate(substr($s, 2));
            }
            if ($d === false || (! str_contains($d, 'Tj') && ! str_contains($d, 'TJ'))) {
                continue;
            }
            $line = '';
            foreach (preg_split('/\r?\n/', $d) as $op) {
                $op = trim($op);
                if (preg_match('/T[jJ]$/', $op) && preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $op, $t)) {
                    foreach ($t[0] as $x) {
                        $line .= stripcslashes(substr($x, 1, -1));
                    }
                    $line .= ' ';
                } elseif (preg_match('/\b(Td|TD|T\*|Tm)$/', $op) && trim($line) !== '') {
                    $out[] = trim($line);
                    $line = '';
                }
            }
            if (trim($line) !== '') {
                $out[] = trim($line);
            }
        }

        return array_map(fn ($l) => mb_check_encoding($l, 'UTF-8') ? $l : mb_convert_encoding($l, 'UTF-8', 'ISO-8859-1'), $out);
    }
}
