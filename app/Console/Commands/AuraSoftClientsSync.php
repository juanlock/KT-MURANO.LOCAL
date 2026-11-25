<?php

namespace App\Console\Commands;

use App\Support\AuraSoftClients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes the customers of type "Pasajero" from every Liquidación Detallada already uploaded (2026-11-24). New uploads
 * do it by themselves (AuraSoftDetail::run). Repeatable: a DNI / RUC already a customer is never duplicated.
 */
class AuraSoftClientsSync extends Command
{
    protected $signature = 'transport:aurasoft-clients {--company= : company id (default: every company with AuraSoft documents)}';

    protected $description = 'Create the AuraSoft customers (type Pasajero, no portal) from the uploaded Liquidación Detallada';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')]
            : DB::table('transport_aurasoft_movements')->distinct()->pluck('company_id')->all();
        foreach ($ids as $cid) {
            $r = DB::transaction(fn () => AuraSoftClients::sync((int) $cid));
            $this->info("company $cid: {$r['new']} new passengers, {$r['renamed']} renamed");
        }

        return self::SUCCESS;
    }
}
