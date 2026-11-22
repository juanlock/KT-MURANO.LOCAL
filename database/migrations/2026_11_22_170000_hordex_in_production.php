<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HORDEX EIRL is in production (user, 2026-11-22: "hordex, ruba, newera y kcoltech están en modo producción"): its data
 * lives in production and a developer PC never sends it up (protocol_prod_push reads companies.is_live from production).
 * By RUC / name, not by id. Ruba, New Era and KcolTech already are. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('settings')->where('vat_number', '20536725661')->pluck('company_id')
            ->merge(DB::table('companies')->where('name', 'like', 'HORDEX%')->pluck('id'))->unique()->filter();
        DB::table('companies')->whereIn('id', $ids)->update(['is_live' => 1]);
    }

    public function down(): void
    {
        // Left in production on purpose: taking a company out is done from the Companies list.
    }
};
