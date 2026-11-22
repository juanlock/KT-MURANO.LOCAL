<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Distribución B2B reports as Excel, formatted like Hordex's earlier exports (2026-11-21: "los excel que bota el
 * aplicativo deben estar formateados, con color en el header"): optional title lines (merged, bold, centred), the header
 * black with white bold text, zebra rows (F0F0F0), a "Total" row with SUM formulas, 2-decimal number columns, auto filter
 * on the header and auto-sized columns.
 */
class B2bReportExport implements FromArray, WithEvents
{
    public function __construct(
        private array $head,
        private array $rows,
        private array $titles = [],     // lines above the header (a product's report)
        private array $sum = [],        // column letters that get =SUM() in the Total row
        private ?string $totalAt = null, // column letter where the word "Total" goes
        private array $num = [],        // column letters formatted 0.00
    ) {}

    public function array(): array
    {
        return array_merge(array_map(fn ($t) => [$t], $this->titles), [$this->head], $this->rows);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $cols = max(count($this->head), 1);
            $last = Coordinate::stringFromColumnIndex($cols);
            $headRow = count($this->titles) + 1;
            $first = $headRow + 1;
            $lastRow = $headRow + count($this->rows);

            foreach (array_keys($this->titles) as $i) {
                $r = $i + 1;
                $sheet->mergeCells("A$r:$last$r");
                $sheet->getStyle("A$r:$last$r")->getFont()->setBold(true);
                $sheet->getStyle("A$r:$last$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            }

            $header = "A$headRow:$last$headRow";
            $sheet->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
            $sheet->getStyle($header)->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));

            for ($r = $first; $r <= $lastRow; $r += 2) {
                $sheet->getStyle("A$r:$last$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F0F0');
            }

            $end = $lastRow;
            if ($this->sum && $this->totalAt) {
                $end = $lastRow + 1;
                $sheet->setCellValue($this->totalAt.$end, 'Total');
                foreach ($this->sum as $c) {
                    $sheet->setCellValue($c.$end, $lastRow >= $first ? "=SUM($c$first:$c$lastRow)" : 0);
                }
                $sheet->getStyle("A$end:$last$end")->getFont()->setBold(true);
                $sheet->getStyle("A$end:$last$end")->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            }
            foreach ($this->num as $c) {
                $sheet->getStyle("$c$first:$c$end")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            }

            $sheet->setAutoFilter("A$headRow:$last".max($lastRow, $headRow));
            for ($i = 1; $i <= $cols; $i++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
            }
            if ($this->titles) {
                // Title lines are merged: don't let them widen column A.
                $sheet->getColumnDimension('A')->setAutoSize(true);
            }
            $sheet->freezePane('A'.$first);
        }];
    }
}
