<?php

use App\Services\TransportParcelAssign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A parcel is registered with its date and destination; the system picks its bus and the customer gets an estimate of 1 to
 * 3 days (user's rule, 2026-11-04). So a parcel can wait without a trip. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transport_parcels MODIFY trip_id INT UNSIGNED NULL');
        Schema::table('transport_parcels', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_parcels', 'received_on')) {
                $t->date('received_on')->nullable()->after('warehouse_id');
                $t->date('eta_from')->nullable()->after('received_on');
                $t->date('eta_to')->nullable()->after('eta_from');
            }
        });
        foreach (DB::table('transport_parcels')->whereNull('received_on')->get(['id', 'created_at']) as $p) {
            [$from, $to] = TransportParcelAssign::eta(substr((string) $p->created_at, 0, 10));
            DB::table('transport_parcels')->where('id', $p->id)->update(['received_on' => substr((string) $p->created_at, 0, 10), 'eta_from' => $from, 'eta_to' => $to]);
        }
    }

    public function down(): void
    {
    }
};
