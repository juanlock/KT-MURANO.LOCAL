<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo's Manager login is the owner's for the presentation (user's change on Optimum, 2026-11-11): the name
 * "Manager Turismo Cautivo" becomes "Julio Velarde". Only the name -- the login (turismo-manager@kcoltech.com), its role
 * and its password stay. Turismo is a live company, so this travels as a migration. Only while the old name is there:
 * safe to run twice and never overwrites a name changed in production.
 */
return new class extends Migration
{
    private const EMAIL = 'turismo-manager@kcoltech.com';

    public function up(): void
    {
        DB::table('users')->where('email', self::EMAIL)->where('firstname', 'Manager')->where('lastname', 'Turismo Cautivo')
            ->update(['firstname' => 'Julio', 'lastname' => 'Velarde', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', self::EMAIL)->where('firstname', 'Julio')->where('lastname', 'Velarde')
            ->update(['firstname' => 'Manager', 'lastname' => 'Turismo Cautivo', 'updated_at' => now()]);
    }
};
