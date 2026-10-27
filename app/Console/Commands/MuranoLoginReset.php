<?php

namespace App\Console\Commands;

use App\Support\LoginSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * Login security (2026-10-27) way back in for a login that can't enter (the SuperAdmin
 * included -- e.g. the two-step code email can't be delivered): unlocks it, removes its
 * authenticator app and its own "email codes" choice, forgets remembered computers.
 * The company's 2-step rule still applies; with --no-2fa-types the company's (or, for a
 * SuperAdmin, AdminSystem's) two-step types are cleared too. Run on the server (SSH).
 *
 *   php artisan murano:login-reset someone@example.com [--no-2fa-types]
 */
class MuranoLoginReset extends Command
{
    protected $signature = 'murano:login-reset {email} {--no-2fa-types : Also clear the company\'s two-step types}';

    protected $description = 'Unlock a login and remove its two-step setup (way back in when it cannot sign in)';

    public function handle(): int
    {
        $users = DB::table('users')->where('email', $this->argument('email'))->whereNull('deleted_at')->get();
        if ($users->isEmpty()) {
            $this->error('No login with that email.');

            return self::FAILURE;
        }
        foreach ($users as $u) {
            DB::table('users')->where('id', $u->id)->update([
                'failed_logins' => 0, 'last_failed_at' => null, 'locked_until' => null,
                'two_factor_secret' => null, 'two_factor_recovery' => null, 'two_factor_confirmed_at' => null, 'two_factor_email' => false,
            ]);
            DB::table('login_devices')->where('user_id', $u->id)->update(['remember_until' => null]);
            LoginSecurity::event($u, $u->email, 'web', 'unlocked', null, 'murano:login-reset');
            if ($this->option('no-2fa-types')) {
                DB::table('settings')->where('company_id', LoginSecurity::companyOf($u))->whereNull('deleted_at')->update(['security_2fa_types' => json_encode([])]);
            }
            $this->info("#{$u->id} {$u->email} ({$u->type}): unlocked, two-step setup removed".($this->option('no-2fa-types') ? ', company two-step types cleared' : ''));
        }

        return self::SUCCESS;
    }
}
