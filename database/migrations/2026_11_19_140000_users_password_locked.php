<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.password_locked (user's rule, 2026-11-19, Turismo Cautivo's test logins with one shared password): the person can't change
 * the password themselves (Profile, forgot-password link, portal, forced change -- PasswordPolicy::rules refuses it); an admin still
 * can (Users / Company logins). A locked login seen from 3 or more IPs in 24 h emails the SuperAdmin (murano:shared-login-alert).
 * Turismo: every login of Director, Manager, Operaciones, Agencia and Piloto (not the IT Admin). Safe to run twice; by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_locked')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('password_locked')->default(false)->after('must_change_password');
            });
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $roles = DB::table('roles')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereIn('name', ['Director', 'Manager', 'Operaciones', 'Agencia', 'Piloto'])->pluck('id');
        DB::table('users')->whereIn('role_id', $roles)->whereNull('deleted_at')
            ->update(['password_locked' => true, 'must_change_password' => false]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_locked')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('password_locked'));
        }
    }
};
