<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with the USERNAME (user's rule, 2026-11-22): companies never know each other, so an email can repeat; the
 * username (unique in all of Murano) signs in. users.email_login = this login may also sign in with its email, turned on
 * by its IT Admin / the SuperAdmin only when that email doesn't repeat behind the same door (App\Support\LoginLookup).
 * On by default for the logins the user named: info@rubagardenlandscape.com, info@neweraunisexsalon.com,
 * hordex@qnet.com.pe and info@kcoltech.com (the SuperAdmin always signs in with its email).
 *
 * The "+xxxxxx" aliases made when an email was taken in another company get their real email back (repeating is allowed
 * now). By email / pattern, not by id. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_login')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('email_login')->default(false)->after('email'));
        }

        // The aliases LinkedUserService::uniqueEmail made: local+XXXXXX@domain (6 letters / digits) -> local@domain,
        // unless that would be a SuperAdmin's email.
        $super = DB::table('users')->where('type', 'SuperAdmin')->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        foreach (DB::table('users')->where('email', 'like', '%+%@%')->get(['id', 'email']) as $u) {
            if (! preg_match('/^(.+)\+[A-Za-z0-9]{6}(@.+)$/', $u->email, $m)) {
                continue;
            }
            $real = $m[1].$m[2];
            if (! in_array(mb_strtolower($real), $super, true)) {
                DB::table('users')->where('id', $u->id)->update(['email' => $real]);
            }
        }

        foreach (['info@rubagardenlandscape.com', 'info@neweraunisexsalon.com', 'hordex@qnet.com.pe', 'info@kcoltech.com'] as $email) {
            // Their staff (admin) logins, only where the email doesn't repeat among staff logins.
            $q = fn () => DB::table('users')->where('email', $email)->whereNull('deleted_at')->where('type', '!=', 'customer');
            if ($q()->count() === 1) {
                $q()->update(['email_login' => 1]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'email_login')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('email_login'));
        }
    }
};
