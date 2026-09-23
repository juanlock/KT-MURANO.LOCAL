<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the sidebar menu layout per-company instead of one shared layout for
 * the whole install (billing never had this either -- see this table's own
 * create migration). All of today's rows are the KcolTech (company 1)
 * layout built/tested throughout this project's menu work, so they become
 * company 1's explicitly; any other company starts from the static
 * config/menu.js defaults (same "nothing saved yet" fallback every company
 * already gets today) until an admin customizes it.
 *
 * item_key was globally unique (one shared row per key); now every company
 * needs its OWN row for the same key (e.g. every company has a 'dashboard'
 * row), so the unique constraint moves to (company_id, item_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_positions', function (Blueprint $table) {
            $table->dropUnique(['item_key']);
            $table->integer('company_id')->default(1)->after('id');
        });

        DB::table('menu_positions')->update(['company_id' => 1]);

        Schema::table('menu_positions', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::table('menu_positions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'item_key']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->unique('item_key');
        });
    }
};
