<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Short names for the top menu (user's rule, 2026-11-24): with the side menu collapsed, its groups become buttons in the
 * top bar and long names ("Back Office y Administración") push the rest under "Más". Each company can give a group a
 * short name in Manage Menu (menu_positions.short_label); without one the code's proposal applies (config/menuShort.js).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menu_positions', 'short_label')) {
            Schema::table('menu_positions', function (Blueprint $t) {
                $t->string('short_label', 40)->nullable()->after('label');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('menu_positions', 'short_label')) {
            Schema::table('menu_positions', fn (Blueprint $t) => $t->dropColumn('short_label'));
        }
    }
};
