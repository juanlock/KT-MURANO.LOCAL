<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Authorization per place (user's rule, 2026-11-20, Hordex's Miguel Barrera): a login with users.two_factor_ip gets the
 * emailed code whenever it signs in from an IP where it never confirmed one (LoginSecurity::rememberedDevice reads
 * login_events code_ok); its known IPs sign in with the password only. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_ip')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('two_factor_ip')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'two_factor_ip')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('two_factor_ip'));
        }
    }
};
