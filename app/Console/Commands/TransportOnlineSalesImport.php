<?php

namespace App\Console\Commands;

use App\Services\JelafSync;
use App\Services\TransportPassengers;
use Illuminate\Console\Command;

/**
 * Jelaf's "Reporte de ventas web" PDFs (WEB / RED BUS with the buyer's phone; Daniel downloads them by hand) into
 * transport_online_sales, then the passengers rebuilt (2026-11-26). Repeatable: overlapping files never double a sale.
 *
 *   php artisan transport:online-sales-import [--dir=] [--company=] [--rebuild-only]
 */
class TransportOnlineSalesImport extends Command
{
    protected $signature = 'transport:online-sales-import {--dir= : folder with the PDFs} {--company=} {--rebuild-only}';

    protected $description = 'Import Jelaf online sales PDFs (web / RedBus, with phones) and rebuild Turismo\'s passengers.';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: JelafSync::companyId());
        if (! $this->option('rebuild-only')) {
            $dir = rtrim($this->option('dir') ?: JelafSync::archiveDir('07 Ventas web y RedBus'), '\\/');
            foreach (glob($dir.'/*.pdf') ?: [] as $file) {
                $r = TransportPassengers::importOnlinePdf($cid, $file);
                $this->line(sprintf('%s: %s %d rows (%s..%s), new %d', $r['file'], $r['channel'], $r['rows'], $r['from'], $r['to'], $r['new']));
            }
        }
        $p = TransportPassengers::rebuild($cid, true);
        if ($p['phones']) {
            $this->line(sprintf('phones: %d online tickets -> %d passengers got their phone, %d added by name + phone', $p['phones']['records'], $p['phones']['matched'], $p['phones']['added']));
        }
        $this->line("travellers added to the base by name: {$p['added']}");
        $this->info(sprintf('tickets linked to a passenger %d of %d; online sales linked to a ticket %d of %d', $p['tickets_linked'], $p['tickets'], $p['online_linked'], $p['online']));

        return self::SUCCESS;
    }
}
