<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a top-level menu_positions row renders: 'sidebar' (default, the only
 * one with a live renderer today), 'topnav', or 'footer'. Top Nav and Footer
 * have no rendering surface yet in Murano (AppTopbar.vue is fully hardcoded,
 * no footer nav component exists) -- this column only lets Manage Menus tag
 * items for those locations ahead of that follow-up work; the live sidebar
 * simply excludes anything not tagged 'sidebar'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menu_positions', 'location')) {
            Schema::table('menu_positions', function (Blueprint $table) {
                $table->string('location')->default('sidebar')->after('hidden');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('menu_positions', 'location')) {
            Schema::table('menu_positions', function (Blueprint $table) {
                $table->dropColumn('location');
            });
        }
    }
};
