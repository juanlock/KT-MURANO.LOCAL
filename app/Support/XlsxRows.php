<?php

namespace App\Support;

/**
 * Reads the first sheet of a big .xlsx row by row without loading it whole (2026-11-24: AuraSoft's "Liquidación Detallada"
 * has ~82,000 rows; PhpSpreadsheet's toArray needed more memory than the server gives). Values as text, as Excel shows
 * them stored (numbers as numbers, shared strings resolved); empty cells = null. Dates stored as text stay text.
 */
class XlsxRows
{
    /** @return \Generator<int, array<int, mixed>> row number (1-based) => [column index => value] */
    public static function read(string $path, ?int $limit = null): \Generator
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException(__('The file could not be read.'));
        }
        $strings = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = new \XMLReader();
            $x->XML($ss);
            $cur = null;
            while ($x->read()) {
                if ($x->nodeType === \XMLReader::ELEMENT && $x->localName === 'si') {
                    $cur = '';
                } elseif ($x->nodeType === \XMLReader::ELEMENT && $x->localName === 't' && $cur !== null) {
                    $cur .= $x->readString();
                } elseif ($x->nodeType === \XMLReader::END_ELEMENT && $x->localName === 'si') {
                    $strings[] = $cur;
                    $cur = null;
                }
            }
            $x->close();
            unset($ss);
        }
        $sheet = self::firstSheet($zip);
        $zip->close();
        $x = new \XMLReader();
        if (! $x->open('zip://'.$path.'#'.$sheet)) {
            throw new \RuntimeException(__('The file could not be read.'));
        }
        $row = null; $rowNum = 0; $count = 0;
        while ($x->read()) {
            if ($x->nodeType === \XMLReader::ELEMENT && $x->localName === 'row') {
                $rowNum = (int) $x->getAttribute('r') ?: $rowNum + 1;
                $row = [];
                if ($x->isEmptyElement) {
                    yield $rowNum => [];
                    if ($limit && ++$count >= $limit) {
                        break;
                    }
                    $row = null;
                }
            } elseif ($x->nodeType === \XMLReader::ELEMENT && $x->localName === 'c' && $row !== null) {
                $ref = (string) $x->getAttribute('r');
                $type = (string) $x->getAttribute('t');
                $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                $val = null;
                if (! $x->isEmptyElement) {
                    $depth = $x->depth;
                    while ($x->read() && ! ($x->nodeType === \XMLReader::END_ELEMENT && $x->localName === 'c' && $x->depth === $depth)) {
                        if ($x->nodeType === \XMLReader::ELEMENT && ($x->localName === 'v' || $x->localName === 't')) {
                            $val = ($val ?? '').$x->readString();
                        }
                    }
                }
                if ($val !== null) {
                    if ($type === 's') {
                        $val = $strings[(int) $val] ?? '';
                    } elseif ($type === '' || $type === 'n') {
                        $val = is_numeric($val) ? $val + 0 : $val;
                    }
                }
                $row[$col] = $val;
            } elseif ($x->nodeType === \XMLReader::END_ELEMENT && $x->localName === 'row' && $row !== null) {
                $max = $row ? max(array_keys($row)) : -1;
                $out = [];
                for ($i = 0; $i <= $max; $i++) {
                    $out[$i] = $row[$i] ?? null;
                }
                yield $rowNum => $out;
                $row = null;
                if ($limit && ++$count >= $limit) {
                    break;
                }
            }
        }
        $x->close();
    }

    private static function firstSheet(\ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb && $rels && preg_match('~<sheet\b[^>]*r:id="([^"]+)"~', $wb, $m)
            && preg_match('~<Relationship\b[^>]*Id="'.preg_quote($m[1], '~').'"[^>]*Target="([^"]+)"~', $rels, $t)
            || ($wb && $rels && preg_match('~<sheet\b[^>]*r:id="([^"]+)"~', $wb, $m)
            && preg_match('~<Relationship\b[^>]*Target="([^"]+)"[^>]*Id="'.preg_quote($m[1], '~').'"~', $rels, $t))) {
            $target = ltrim($t[1], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
