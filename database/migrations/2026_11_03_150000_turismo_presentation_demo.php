<?php

use App\Services\DemoData\DemoRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo's presentation is given in production (user's request,
 * 2026-11-03, presentation 2026-11-04 11:00): Turismo is live, so its demo
 * can't travel from a developer PC -- this migration makes it on the server.
 *  - modules: POS, Sales, Products, Customers on; Website, Portfolio, Gallery off
 *    (tourism type, 2026-11-03; its own website keeps the Murano forms);
 *  - the agencies as Locations with their Terminal POS (TransportAgencies);
 *  - the transport demo (buses, crew, trips, route tickets, a cashier per
 *    agency, passengers, 30 days of ticket sales) -- only when it has none;
 *  - "Update the demo every day" on: the hourly demo:daily keeps the trips and
 *    the ticket sales current up to the hour.
 * Remove demo data (Settings -> Demo Data) takes it all away afterwards.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $row = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'module_flags']);
        if ($row) {
            $flags = json_decode((string) $row->module_flags, true) ?: [];
            foreach (['pos', 'sales', 'products', 'customers', 'transport', 'fleet'] as $k) {
                $flags[$k] = true;
            }
            foreach (['website', 'portfolio', 'gallery'] as $k) {
                $flags[$k] = false;
            }
            DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
        }
        DB::table('pos_settings')->where('company_id', $cid)->update(['show_pos_button' => 1]);
        DB::table('companies')->where('id', $cid)->update(['demo_daily' => 1]);
        DB::transaction(fn () => \App\Support\TransportAgencies::sync($cid));

        if (DB::table('demo_records')->where('company_id', $cid)->where('module', 'transport')->exists()) {
            echo "  Turismo: transport demo already there (kept)\n";

            return;
        }
        $admin = (int) DB::table('users')->where('company_id', $cid)->where('type', 'admin')->whereNull('deleted_at')->orderBy('id')->value('id');
        $res = DemoRunner::run($cid, $admin, ['transport']);
        echo '  Turismo demo: '.json_encode($res['transport']['created'] ?? $res['transport'] ?? $res, JSON_UNESCAPED_UNICODE)."\n";
    }

    public function down(): void
    {
        // Settings -> Demo Data -> Remove takes the demo away.
    }
};
