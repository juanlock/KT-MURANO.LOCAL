<?php

namespace App\Console\Commands;

use App\Support\AuraSoftPassengers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turismo's passenger base (transport_passengers, 2026-11-24): rebuilds it from every Liquidación Detallada uploaded
 * (new uploads do it by themselves) and, with --phones, adds the phones of AuraSoft's RedBus / web sales report
 * (a JSON of {name, phone, emitted, travel} records, as extracted from the PDFs). Repeatable.
 */
class AuraSoftClientsSync extends Command
{
    protected $signature = 'transport:passengers {--company= : company id (default: every company with AuraSoft documents)}
        {--phones= : JSON file with the PDF records (name, phone, emitted, travel)}';

    protected $description = 'Rebuild the passenger base from AuraSoft\'s Liquidación Detallada (+ phones from the RedBus / web PDFs)';

    public function handle(): int
    {
        $ids = $this->option('company') ? [(int) $this->option('company')]
            : DB::table('transport_aurasoft_movements')->distinct()->pluck('company_id')->all();
        foreach ($ids as $cid) {
            $r = DB::transaction(fn () => AuraSoftPassengers::sync((int) $cid));
            $this->info("company $cid: {$r['new']} new passengers, {$r['updated']} updated");
            if ($file = $this->option('phones')) {
                $records = json_decode((string) file_get_contents($file), true) ?: [];
                $p = DB::transaction(fn () => AuraSoftPassengers::phones((int) $cid, $records));
                $this->info("company $cid: {$p['records']} online tickets -> {$p['matched']} passengers got their phone, {$p['added']} added by name + phone");
            }
        }

        return self::SUCCESS;
    }
}
