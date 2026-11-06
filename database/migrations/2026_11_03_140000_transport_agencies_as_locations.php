<?php

use App\Support\TransportAgencies;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A transport company's agencies are its Locations (user's rule, 2026-11-03):
 * transport_locations.warehouse_id, a warehouse per agency and the role
 * Terminal POS (TransportAgencies::sync) for every company that has agencies.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transport_locations', 'warehouse_id')) {
            Schema::table('transport_locations', function (Blueprint $t) {
                $t->unsignedInteger('warehouse_id')->nullable()->after('hub_id')->index();
            });
        }
        $ids = DB::table('transport_locations')->where('kind', 'agency')->whereNull('deleted_at')->distinct()->pluck('company_id');
        foreach ($ids as $cid) {
            if (DB::table('companies')->where('id', $cid)->whereNull('deleted_at')->exists()) {
                $map = DB::transaction(fn () => TransportAgencies::sync((int) $cid));
                echo "  company {$cid}: ".count($map)." agencies -> locations\n";
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_locations', 'warehouse_id')) {
            Schema::table('transport_locations', fn (Blueprint $t) => $t->dropColumn('warehouse_id'));
        }
    }
};
