<?php

use App\Support\TransportDemoDay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Turismo Cautivo's costs as the user gave them (2026-11-04): tolls for a 3-axle bus and
 * distances per stretch, S/ 50 a day per agency. The demo's rows follow: fuel of the two-level
 * Marcopolo (TransportDemoDay), one hostess a trip (pilot, copilot, hostess), seats 30 % to
 * 100 % sold, and approximate bus data (the company has no figures: price, useful life).
 * Safe to run twice.
 */
return new class extends Migration
{
    /** Pairs of agencies, both ways: [km or null to keep it, tolls of a 3-axle bus: the Marcopolo Paradiso 1600 G8 in the company's photos]. */
    private const STRETCHES = [
        ['LIMA', 'TRUJILLO', 560, 190.10],   // Lima-Chepén without Chicama (6 tolls)
        ['LIMA', 'CHEPEN', 698, 220.80],     // given: 7 tolls
        ['LIMA', 'CHICLAYO', null, 251.20],  // Lima-Chepén + Pacanguilla (8 tolls)
        ['TRUJILLO', 'CHEPEN', 140, 30.70],  // given: Chicama
        ['TRUJILLO', 'CHICLAYO', 208, 61.10], // given: Chicama and Pacanguilla
        ['CHEPEN', 'CHICLAYO', 78, 30.40],   // Trujillo-Chiclayo without Chicama (Pacanguilla)
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('transport_locations', 'daily_cost')) {
            Schema::table('transport_locations', function (Blueprint $t) {
                $t->decimal('daily_cost', 10, 2)->nullable()->after('active'); // an agency's cost per day
            });
        }

        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->whereNull('daily_cost')->update(['daily_cost' => 50, 'updated_at' => now()]);

        $ids = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->pluck('id', 'name');
        foreach (self::STRETCHES as [$a, $b, $km, $tolls]) {
            if (! isset($ids[$a], $ids[$b])) {
                continue;
            }
            foreach ([[$a, $b], [$b, $a]] as [$o, $d]) {
                DB::table('transport_routes')->where('company_id', $cid)->where('origin_id', $ids[$o])->where('destination_id', $ids[$d])->whereNull('deleted_at')
                    ->update(array_filter(['distance_km' => $km, 'toll_cost' => $tolls, 'updated_at' => now()], fn ($v) => $v !== null));
            }
        }

        // From here on, rows tagged as demo only.
        $demo = fn (string $type) => DB::table('demo_records')->where('company_id', $cid)->where('record_type', $type)->pluck('record_id')->all();
        $trips = $demo('transport_trips');
        // Pilot, copilot and one hostess.
        foreach (DB::table('transport_trip_crew')->whereIn('trip_id', $trips)->where('role', 'hostess')->orderBy('id')->get(['id', 'trip_id'])->groupBy('trip_id') as $crew) {
            DB::table('transport_trip_crew')->whereIn('id', $crew->slice(1)->pluck('id'))->delete();
        }
        // Approximate: the company has no figures for these (less than 10 years old).
        DB::table('vehicles')->whereIn('id', $demo('vehicles'))->update(['purchase_price' => 1100000, 'residual_value' => 220000, 'useful_life_km' => 1500000, 'updated_at' => now()]);
        // Fuel again, at the new distances and cost per km.
        DB::table('transport_trips')->whereIn('id', $trips)->update(['fuel_cost' => null]);
        echo '  Turismo demo: '.json_encode(TransportDemoDay::profitData($cid, true))."\n";
    }

    public function down(): void
    {
    }
};
