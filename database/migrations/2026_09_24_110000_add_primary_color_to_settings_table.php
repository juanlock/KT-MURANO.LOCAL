<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Appearance -> Primary Color: lets a company set the
 * official admin-app brand color (drives the Ant Design colorPrimary token
 * in App.vue), instead of it only being a per-browser Customizer preference.
 * Null = no company override, the app falls back to its own built-in
 * default (currently Microsoft Blue #0078D4 -- see stores/ui.js).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'primary_color')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            $table->string('primary_color', 7)->nullable()->after('login_bg_color');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'primary_color')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('primary_color');
            });
        }
    }
};
