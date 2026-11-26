<?php

namespace App\Console\Commands;

use App\Services\JelafSync;
use Illuminate\Console\Command;

/**
 * Import the Control de Embarque - Detallado files already downloaded (2026-11-26), one per travel day, without asking Jelaf
 * again: KT-TOOLS/files/turismo/JELAF_INTEGRACION/06 Control de Embarque on a PC, or --dir. A day's import replaces it.
 *
 *   php artisan transport:boarding-import {from} {to} [--dir=] [--company=]
 */
class TransportBoardingImport extends Command
{
    protected $signature = 'transport:boarding-import {from} {to} {--dir= : folder with ControlEmbarqueDetalle_NombrePasajero-YYYY-MM-DD.xlsx} {--company=}';

    protected $description = 'Import downloaded Jelaf Control de Embarque (Detallado) files into transport_boardings.';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: JelafSync::companyId());
        $dir = rtrim($this->option('dir') ?: JelafSync::archiveDir('06 Control de Embarque/Descargado_Jelaf_ControlEmbarqueDetalle'), '\\/');
        $tot = ['days' => 0, 'rows' => 0, 'no_show' => 0, 'missing' => 0, 'amount' => 0.0];
        for ($d = strtotime($this->argument('from')); $d <= strtotime($this->argument('to')); $d += 86400) {
            $day = date('Y-m-d', $d);
            $file = "$dir/ControlEmbarqueDetalle_NombrePasajero-$day.xlsx";
            if (! is_file($file)) {
                $tot['missing']++;
                continue;
            }
            $r = JelafSync::importBoardingFile($cid, $file, $day);
            $tot['days']++;
            $tot['rows'] += $r['rows'];
            $tot['no_show'] += $r['no_show'];
            $tot['amount'] += $r['amount'];
        }
        $this->info(sprintf('imported %d days: %d tickets, %d not boarded, S/ %s; days without file: %d', $tot['days'], $tot['rows'], $tot['no_show'],
            number_format($tot['amount'], 2), $tot['missing']));

        return self::SUCCESS;
    }
}
