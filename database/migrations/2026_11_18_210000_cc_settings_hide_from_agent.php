<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call center (user, 2026-11-18): "ocultar al agente el número y el DNI" when the agents call through the telephone
 * exchange (click to call). Off by default; Central telefónica -> switch. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cc_settings') && ! Schema::hasColumn('cc_settings', 'hide_from_agent')) {
            Schema::table('cc_settings', function (Blueprint $t) {
                $t->boolean('hide_from_agent')->default(false)->after('extensions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cc_settings') && Schema::hasColumn('cc_settings', 'hide_from_agent')) {
            Schema::table('cc_settings', fn (Blueprint $t) => $t->dropColumn('hide_from_agent'));
        }
    }
};
