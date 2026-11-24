<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * AuraSoft's departures reach the boards (user, 2026-11-23: "procede ... adecua los tableros para que se muestren como
 * producción por bus y reporte del día de ventas"):
 *  - transport_trips.reported_parcels / reported_parcel_income / reported_excess_income: a trip from a report also brings
 *    its parcels and excess luggage (TransportProfit counts them as parcel income); source = where the figures came from
 *    (aurasoft, report, ...);
 *  - transport_aurasoft_rows.trip_id: the Murano departure each AuraSoft row made or updated (AuraSoftTrips::sync).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('transport_trips', 'reported_parcels')) {
                $table->unsignedInteger('reported_parcels')->nullable()->after('reported_income');
            }
            if (! Schema::hasColumn('transport_trips', 'reported_parcel_income')) {
                $table->decimal('reported_parcel_income', 12, 2)->nullable()->after('reported_parcels');
            }
            if (! Schema::hasColumn('transport_trips', 'reported_excess_income')) {
                $table->decimal('reported_excess_income', 12, 2)->nullable()->after('reported_parcel_income');
            }
            if (! Schema::hasColumn('transport_trips', 'source')) {
                $table->string('source', 20)->nullable()->after('notes');
            }
        });
        if (Schema::hasTable('transport_aurasoft_rows') && ! Schema::hasColumn('transport_aurasoft_rows', 'trip_id')) {
            Schema::table('transport_aurasoft_rows', function (Blueprint $table) {
                $table->unsignedBigInteger('trip_id')->nullable()->after('vehicle_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_aurasoft_rows', 'trip_id')) {
            Schema::table('transport_aurasoft_rows', fn (Blueprint $t) => $t->dropColumn('trip_id'));
        }
        Schema::table('transport_trips', function (Blueprint $table) {
            foreach (['reported_parcels', 'reported_parcel_income', 'reported_excess_income', 'source'] as $c) {
                if (Schema::hasColumn('transport_trips', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
