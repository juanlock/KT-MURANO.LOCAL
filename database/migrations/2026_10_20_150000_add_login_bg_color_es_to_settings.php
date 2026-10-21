<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Login page background colour per language (user's request, 2026-10-20):
 * login_bg_color = English (and any other language), login_bg_color_es =
 * Spanish (empty = same as English). Settings -> Login Page. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && ! Schema::hasColumn('settings', 'login_bg_color_es')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->string('login_bg_color_es', 7)->nullable()->after('login_bg_color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'login_bg_color_es')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('login_bg_color_es');
            });
        }
    }
};
