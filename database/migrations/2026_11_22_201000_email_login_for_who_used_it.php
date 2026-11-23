<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with the username (2026-11-22): the logins that have already signed in with their email keep doing it
 * (user's rule: "a los que entraron con correo, como son únicos, permíteme el ingreso por email") -- users.email_login on
 * when login_events shows a successful sign-in typed with that login's email AND the email doesn't repeat behind the same
 * door (staff / customer portal) in all of Murano. Every other login, and every new one, starts off. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('login_events') || ! Schema::hasColumn('users', 'email_login')) {
            return;
        }
        $used = DB::table('login_events as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.event', 'success')->whereColumn('e.email', 'u.email')->whereNull('u.deleted_at')
            ->where('u.email_login', 0)->distinct()->get(['u.id', 'u.email', 'u.type']);
        foreach ($used as $u) {
            $repeats = DB::table('users')->where('email', $u->email)->whereNull('deleted_at')->where('id', '!=', $u->id)
                ->where('type', $u->type === 'customer' ? '=' : '!=', 'customer')->exists();
            if (! $repeats) {
                DB::table('users')->where('id', $u->id)->update(['email_login' => 1]);
            }
        }
    }

    public function down(): void
    {
        // Left as is: switching it off is done in Company logins.
    }
};
