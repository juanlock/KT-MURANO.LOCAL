<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P.N. in Turismo Cautivo's departure reports is the terminal Puente Nuevo (user's answer, 2026-11-05), not Plaza Lima Norte
 * as migration 2026_11_05_100000 assumed. Plaza Lima Norte stays: it is another place on the client's list. Only the
 * boardings labelled P.N. of the client's reports move. No map position: nobody gave one. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $cid) {
            return;
        }
        $old = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('name', 'PLAZA LIMA NORTE')->first();
        if (! $old) {
            return;
        }
        DB::transaction(function () use ($cid, $old) {
            $id = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('name', 'PUENTE NUEVO')->value('id');
            if (! $id) {
                $id = DB::table('transport_locations')->insertGetId([
                    'company_id' => $cid, 'name' => 'PUENTE NUEVO', 'city' => $old->city, 'hub_id' => $old->hub_id, 'warehouse_id' => $old->warehouse_id,
                    'kind' => 'terminal', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('transport_trip_boardings')->where('company_id', $cid)->where('label', 'P.N.')->where('location_id', $old->id)
                ->update(['location_id' => $id, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
    }
};
