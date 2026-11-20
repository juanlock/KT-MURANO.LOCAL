<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A client's short / trade name (2026-11-20, Hordex's board: "CENCOSUD", "TMH", "F Y D" instead of the legal names).
 * Boards and dense lists show it when set, else the name. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'short_name')) {
            Schema::table('clients', fn (Blueprint $t) => $t->string('short_name', 60)->nullable()->after('name'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'short_name')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('short_name'));
        }
    }
};
