<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms dashboard, the 8 cards (2026-10-24): per form and day also
 *  - users    unique visitors (one per browser + connection per day)
 *  - fill_ms  total time from the first keystroke to "Send" (milliseconds)
 *  - timed    how many submissions carried that time (average = fill_ms / timed)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_stats_daily', function (Blueprint $t) {
            if (! Schema::hasColumn('form_stats_daily', 'users')) {
                $t->unsignedInteger('users')->default(0)->after('day');
            }
            if (! Schema::hasColumn('form_stats_daily', 'fill_ms')) {
                $t->unsignedBigInteger('fill_ms')->default(0);
            }
            if (! Schema::hasColumn('form_stats_daily', 'timed')) {
                $t->unsignedInteger('timed')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('form_stats_daily', function (Blueprint $t) {
            $t->dropColumn(['users', 'fill_ms', 'timed']);
        });
    }
};
