<?php

namespace App\Console\Commands;

use App\Services\JelafSync;
use App\Services\TransportPassengers;
use App\Support\JelafClient;
use Illuminate\Console\Command;

/**
 * Jelaf's "Reporte de Pasajeros Frecuentes" (2026-11-26): every ticket with the traveller's DNI, phone and email. Downloads
 * a range by half months (files already there are kept unless --fresh), imports them into transport_ticket_docs and rebuilds
 * the passenger base (by DNI). The nightly import runs the next / last days by itself.
 *
 *   php artisan transport:jelaf-passengers {from} {to} [--fresh] [--import-only] [--company=] [--pause=5]
 */
class TransportJelafPassengers extends Command
{
    protected $signature = 'transport:jelaf-passengers {from} {to} {--fresh} {--import-only} {--company=} {--pause=5}';

    protected $description = 'Download / import Jelaf Pasajeros Frecuentes (DNI, phone, email per ticket) and rebuild the passengers.';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: JelafSync::companyId());
        $dir = JelafSync::archiveDir('05 Pasajeros Frecuentes/Descargado_Jelaf_Pasajeros Frecuentes/2026 fecha viaje');
        $client = null;
        $files = [];
        for ($a = strtotime($this->argument('from')); $a <= strtotime($this->argument('to')); ) {
            $d = (int) date('j', $a);
            $end = $d <= 15 ? strtotime(date('Y-m-15', $a)) : strtotime(date('Y-m-t', $a));
            $b = min($end, strtotime($this->argument('to')));
            $file = sprintf('%s/PasajerosFrecuentes %s_%s.xlsx', $dir, date('Y-m-d', $a), date('d', $b));
            if (! $this->option('import-only') && ($this->option('fresh') || ! is_file($file))) {
                $client ??= JelafClient::fromEnvFile();
                $n = $client->frequentPassengers(date('Y-m-d', $a), date('Y-m-d', $b), $file);
                $this->line(date('H:i:s').' '.basename($file).': '.($n ? number_format($n).' bytes' : 'no data'));
                sleep(max(1, (int) $this->option('pause')));
            }
            is_file($file) && $files[] = $file;
            $a = strtotime('+1 day', $b);
        }
        foreach ($files as $file) {
            $r = TransportPassengers::importFrequentFile($cid, $file);
            $this->line(sprintf('%s: %d tickets (new %d)', basename($file), $r['rows'], $r['new']));
        }
        $p = TransportPassengers::rebuild($cid);
        $this->info(sprintf('passengers by DNI %d (new %d); tickets linked %d of %d', $p['by_doc'] ?? 0, $p['doc_new'] ?? 0, $p['tickets_linked'], $p['tickets']));

        return self::SUCCESS;
    }
}
