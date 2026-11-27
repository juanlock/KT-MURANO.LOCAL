<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Turismo Cautivo works only with real data (the user's rule and yes, 2026-11-27: "solo trabajamos con la data que tenemos ...
 * si no tenemos data ponle cero y un helper"). Removes what the transport demo of 2026-11-04 or our own assumptions left in the
 * live company -- the boards then show 0 with a "sin datos" helper until the real figure arrives:
 *  1. vehicle maintenance plans and workshop parts (invented km / dates -> "Vencimientos de los buses");
 *  2. the buses' insurance / registration / inspection dates (demo);
 *  3. purchase price, useful life and residual value (demo S/ 1,100,000 -> "Depreciación");
 *  4. employees' basic salary (our S/ 2,500 / S/ 2,000 -> "Planilla");
 *  5. the agencies' daily cost (our S/ 50);
 *  6. the pilots' swaps of the demo crews;
 *  7. the monthly parcels reference (S/ 250,000).
 * Real data stays: AuraSoft / Jelaf imports, Mayte's fuel, tolls, day prices, complaints, Daniel's timetable, passengers.
 * Production is backed up by the deploy before this runs. Safe to run twice; no down() (the backup is the way back).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $vehicles = DB::table('vehicles')->where('company_id', $cid)->pluck('id')->all();

        DB::transaction(function () use ($cid, $vehicles) {
            // 1. Maintenance plans and parts (and any part movement / job tied to them).
            if ($vehicles) {
                DB::table('vehicle_maintenance_plans')->whereIn('vehicle_id', $vehicles)->delete();
            }
            DB::table('vehicle_maintenance_plans')->where('company_id', $cid)->delete();
            $parts = DB::table('vehicle_parts')->where('company_id', $cid)->pluck('id')->all();
            if ($parts && Schema::hasTable('vehicle_part_moves')) {
                DB::table('vehicle_part_moves')->whereIn('part_id', $parts)->delete();
            }
            DB::table('vehicle_parts')->where('company_id', $cid)->delete();

            // 2 + 3. The buses' papers and value.
            $clear = array_values(array_filter(['insurance_expiry', 'registration_expiry', 'inspection_expiry', 'purchase_price', 'useful_life_km', 'residual_value'],
                fn ($c) => Schema::hasColumn('vehicles', $c)));
            if ($vehicles && $clear) {
                DB::table('vehicles')->whereIn('id', $vehicles)->update(array_fill_keys($clear, null));
            }

            // 4. Salaries.
            DB::table('employees')->where('company_id', $cid)->where('basic_salary', '>', 0)->update(['basic_salary' => 0]);

            // 5. Agencies' daily cost.
            DB::table('transport_locations')->where('company_id', $cid)->where('daily_cost', '>', 0)->update(['daily_cost' => 0]);

            // 6. Pilots' swaps (only the demo crews had them).
            if (Schema::hasTable('transport_trip_swaps')) {
                DB::table('transport_trip_swaps')->where('company_id', $cid)->delete();
            }

            // 7. The monthly parcels reference.
            if (Schema::hasColumn('settings', 'transport_parcel_month_ref')) {
                DB::table('settings')->where('company_id', $cid)->update(['transport_parcel_month_ref' => null]);
            }
        });
    }

    public function down(): void
    {
    }
};
