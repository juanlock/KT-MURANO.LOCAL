<?php

use App\Support\TransportAgencies;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Each transport worker opens their own work screen (user's OK, 2026-11-03),
 * live companies included: ticket office -> Terminal POS (/pos, one agency),
 * pilot -> Piloto, hostess -> Tripulación (their trips), mechanic -> Mecánico
 * (fleet maintenance) -- only logins still on the generic Employee role
 * (TransportAgencies::sync). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('transport_locations')->where('kind', 'agency')->whereNull('deleted_at')->distinct()->pluck('company_id');
        foreach ($ids as $cid) {
            if (DB::table('companies')->where('id', $cid)->whereNull('deleted_at')->exists()) {
                DB::transaction(fn () => TransportAgencies::sync((int) $cid));
                echo "  company {$cid}: transport staff roles\n";
            }
        }
    }

    public function down(): void
    {
        // Role assignments are kept.
    }
};
