<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Distribución B2B's payment methods (2026-11-20): Detracción, Deducción comercial, Depósito bancario. B2b::methodId()
 * creates them on first use, but HORDEX's imported payments went up to production pointing to ids 11 / 12 / 13 while
 * production didn't have them. By name; with that id when it is free, else the next one (payments re-pointed).
 * Safe to run twice.
 */
return new class extends Migration
{
    private const METHODS = [11 => 'Detracción', 12 => 'Deducción comercial', 13 => 'Depósito bancario'];

    public function up(): void
    {
        foreach (self::METHODS as $id => $name) {
            if (DB::table('payment_methods')->where('name', $name)->whereNull('deleted_at')->exists()) {
                continue;
            }
            $row = ['name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()];
            if (! DB::table('payment_methods')->where('id', $id)->exists()) {
                DB::table('payment_methods')->insert($row + ['id' => $id]);

                continue;
            }
            // The id is taken by another method: a new one, and the B2B payments that pointed to the missing one follow it.
            $new = DB::table('payment_methods')->insertGetId($row);
            DB::table('payment_sales')->whereIn('id', DB::table('b2b_payment_info')->select('payment_sale_id'))->where('payment_method_id', $id)
                ->update(['payment_method_id' => $new]);
        }
    }

    public function down(): void {}
};
