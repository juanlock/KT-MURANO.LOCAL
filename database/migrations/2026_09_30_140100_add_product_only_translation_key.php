<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Singular counterpart of Products_Only: key Product_Only = "Product" /
 * "Producto", for screens where the word means a physical good (batches,
 * expiry, stock aging, dead stock, warranty, manufacturing, loyalty "product"
 * reward) -- the shared "Product" label now reads "Product/Service"
 * (2026_09_30_140000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('translations')->where('key', 'Product_Only')->exists()) {
            DB::table('translations')->insert([
                'key' => 'Product_Only', 'is_default' => 1, 'en' => 'Product', 'es' => 'Producto',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Product_Only')->delete();
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
