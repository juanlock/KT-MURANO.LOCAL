<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A passenger is recognized as a parcel customer too (user's rule, 2026-11-24: "sí, hay que reconocer a un pasajero con
 * un cliente de encomienda"): transport_passengers.tickets (tickets + excess bought) and kind = passenger | parcel | both
 * (one record per DNI / RUC whatever he did). Filled now from what is stored; transport:passengers recomputes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transport_passengers', 'kind')) {
            Schema::table('transport_passengers', function (Blueprint $t) {
                $t->unsignedInteger('tickets')->default(0)->after('purchases');
                $t->string('kind', 10)->default('passenger')->after('is_company');
                $t->index(['company_id', 'kind'], 'tp_company_kind');
            });
        }
        DB::table('transport_passengers')->update(['tickets' => DB::raw('GREATEST(purchases - parcels, 0)')]);
        \App\Support\AuraSoftPassengers::refreshKind();
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_passengers', 'kind')) {
            Schema::table('transport_passengers', function (Blueprint $t) {
                $t->dropIndex('tp_company_kind');
                $t->dropColumn(['tickets', 'kind']);
            });
        }
    }
};
