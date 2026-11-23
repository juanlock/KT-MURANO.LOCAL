<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Manage Menu -> "Solo SuperAdmin" (user, 2026-11-23): the menu keys (an item or a whole group) only the SuperAdmin
 * sees, install-wide, kept in AdminSystem's (999) settings row as a JSON list. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'superadmin_only_menu')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->text('superadmin_only_menu')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'superadmin_only_menu')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('superadmin_only_menu');
            });
        }
    }
};
