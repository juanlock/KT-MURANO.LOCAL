<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS Settings -> "Who can open / close the register" (2026-11-01): roles and
 * users chosen per company, on top of the roles with the "Open / close register"
 * permission. {"roles": [ids], "users": [ids]}. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'pos_register_openers')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->json('pos_register_openers')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'pos_register_openers')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->dropColumn('pos_register_openers');
            });
        }
    }
};
