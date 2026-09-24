<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payment Status's legacy 'pending' value reused the shared 'Pending' key
 * (also used by the document Status column) -- fine textually, but the
 * request is to relabel it specifically for Payment Status without touching
 * Status, so it needs its own key instead of sharing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'PaymentPending')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'PaymentPending',
            'is_default' => 1,
            'en' => 'NoPaid',
            'es' => 'No pagado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'PaymentPending')->delete();
    }
};
