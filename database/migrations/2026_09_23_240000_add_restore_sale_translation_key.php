<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New SuperAdmin-only "Restore" row action on a cancelled sale
 * (SalesController@restore) -- confirmation dialog copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Confirm_Restore_Sale')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Confirm_Restore_Sale',
            'is_default' => 1,
            'en' => 'This will put the sale back to Pending and re-apply any loyalty points it used or earned.',
            'es' => 'Esto devolverá la venta a Pendiente y volverá a aplicar los puntos de fidelidad que haya usado u obtenido.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Confirm_Restore_Sale')->delete();
    }
};
