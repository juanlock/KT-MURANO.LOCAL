<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The parcels' monthly movement the client gave (user, 2026-11-09: Turismo Cautivo moves over S/ 250,000 a month):
 * the Parcels tab compares the period's income with it. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'transport_parcel_month_ref')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->decimal('transport_parcel_month_ref', 12, 2)->nullable();
            });
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($cid) {
            DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('transport_parcel_month_ref')->update(['transport_parcel_month_ref' => 250000]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'transport_parcel_month_ref')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('transport_parcel_month_ref'));
        }
    }
};
