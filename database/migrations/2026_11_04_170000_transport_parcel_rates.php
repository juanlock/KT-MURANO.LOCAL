<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A parcel's price by volume, per route (user's data, 2026-11-04: 1 m x 70 cm x 30 cm from Lima to
 * Chepén = S/ 50, so S/ 238.10 per m³). Turismo Cautivo's other routes: in proportion to
 * their km (an assumption, editable on each route). The demo's parcels follow, S/ 10 at
 * least (rows tagged as demo). Safe to run twice.
 */
return new class extends Migration
{
    private const LIMA_CHEPEN_RATE = 238.10;

    private const LIMA_CHEPEN_KM = 698.2;

    private const DEMO_MINIMUM = 10;

    public function up(): void
    {
        if (! Schema::hasColumn('transport_routes', 'parcel_rate_m3')) {
            Schema::table('transport_routes', function (Blueprint $t) {
                $t->decimal('parcel_rate_m3', 10, 2)->nullable()->after('toll_cost'); // S/ per m³, suggested on the parcel form
            });
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        foreach (DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('parcel_rate_m3')->where('distance_km', '>', 0)->get(['id', 'distance_km']) as $r) {
            DB::table('transport_routes')->where('id', $r->id)->update(['parcel_rate_m3' => round(self::LIMA_CHEPEN_RATE * (float) $r->distance_km / self::LIMA_CHEPEN_KM, 2), 'updated_at' => now()]);
        }
        $parcels = DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'transport_parcels')->pluck('record_id')->all();
        foreach (array_chunk($parcels, 500) as $chunk) {
            DB::table('transport_parcels as p')->join('transport_trips as t', 't.id', '=', 'p.trip_id')->join('transport_routes as r', 'r.id', '=', 't.route_id')->whereIn('p.id', $chunk)
                ->update(['p.price' => DB::raw('GREATEST('.self::DEMO_MINIMUM.', ROUND(p.volume_m3 * COALESCE(r.parcel_rate_m3, 0), 2))')]);
        }
    }

    public function down(): void
    {
    }
};
