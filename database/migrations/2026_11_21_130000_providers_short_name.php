<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's trade name (nombre comercial), like clients.short_name -- Hordex's supplier card shows it (2026-11-21).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('providers', 'short_name')) {
            Schema::table('providers', function (Blueprint $t) {
                $t->string('short_name', 190)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('providers', 'short_name')) {
            Schema::table('providers', fn (Blueprint $t) => $t->dropColumn('short_name'));
        }
    }
};
