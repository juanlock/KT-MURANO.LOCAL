<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> Booking (per company): "Use decimals" for the
 * booking Price / Amount. Default ON (= today's behavior). Off: the form takes
 * whole numbers only, saved values are rounded, and the Booking List shows
 * them without decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'booking_decimals_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('booking_decimals_enabled')->default(true);
            });
        }

        if (! DB::table('translations')->where('key', 'Use_Decimals')->exists()) {
            DB::table('translations')->insert([
                'key' => 'Use_Decimals', 'is_default' => 1,
                'en' => 'Use decimals', 'es' => 'Usar decimales',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            forget_db_translations();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'booking_decimals_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('booking_decimals_enabled');
            });
        }
        DB::table('translations')->where('key', 'Use_Decimals')->delete();
        forget_db_translations();
    }
};
