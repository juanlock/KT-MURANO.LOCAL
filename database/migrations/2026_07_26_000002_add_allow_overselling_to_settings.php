<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow Overselling becomes a global feature (Settings > Features) applied to
 * every stock-guarded transaction (POS, sales, quotations, transfers,
 * adjustments, damages, sales import) instead of a POS-only flag. The value
 * is seeded from the previous home on pos_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'allow_overselling')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('allow_overselling')->default(0);
            });
        }

        $pos = DB::table('pos_settings')->whereNull('deleted_at')->first();
        if ($pos && ! empty($pos->allow_overselling)) {
            DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'allow_overselling')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('allow_overselling');
            });
        }
    }
};
