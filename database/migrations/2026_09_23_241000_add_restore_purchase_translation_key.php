<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New SuperAdmin-only "Restore" row action on a cancelled purchase
 * (PurchasesController@restore) -- confirmation dialog copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Confirm_Restore_Purchase')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Confirm_Restore_Purchase',
            'is_default' => 1,
            'en' => 'This will put the purchase back to Pending.',
            'es' => 'Esto devolverá la compra a Pendiente.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Confirm_Restore_Purchase')->delete();
    }
};
