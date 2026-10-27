<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Login security (user's rules, 2026-10-27) -- App\Support\LoginSecurity:
 *  - users: lockout (5 wrong passwords -> 1 hour, any IP), "must change the
 *    standard password", two-step verification (authenticator app secret +
 *    recovery codes, or email codes), when the password last changed
 *  - login_events: every sign-in attempt / lock / code / password change
 *  - login_devices: the browsers each login used (sign-in alerts, "remember this
 *    computer 30 days" for the two-step code)
 *  - password_histories: the last passwords (a new one can't repeat them)
 *  - settings: which user types need the two-step code, sign-in alerts on / off
 *  - permission login_security + Settings -> Login security in every menu
 * Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = '/app/settings/login_security';

    private const AFTER = '/app/settings/network_security';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'failed_logins')) $table->unsignedSmallInteger('failed_logins')->default(0);
            if (! Schema::hasColumn('users', 'last_failed_at')) $table->dateTime('last_failed_at')->nullable();
            if (! Schema::hasColumn('users', 'locked_until')) $table->dateTime('locked_until')->nullable();
            if (! Schema::hasColumn('users', 'must_change_password')) $table->boolean('must_change_password')->default(false);
            if (! Schema::hasColumn('users', 'password_changed_at')) $table->dateTime('password_changed_at')->nullable();
            if (! Schema::hasColumn('users', 'two_factor_secret')) $table->text('two_factor_secret')->nullable();
            if (! Schema::hasColumn('users', 'two_factor_recovery')) $table->text('two_factor_recovery')->nullable();
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) $table->dateTime('two_factor_confirmed_at')->nullable();
            if (! Schema::hasColumn('users', 'two_factor_email')) $table->boolean('two_factor_email')->default(false);
        });

        if (! Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedInteger('company_id')->nullable()->index();
                $table->string('email', 191)->nullable()->index();
                $table->string('channel', 12);   // web | portal | mobile | api
                $table->string('event', 24);     // success | failed | locked | blocked | unlocked | code_sent | code_ok | code_failed | password_changed | new_device | 2fa_on | 2fa_off
                $table->string('ip', 64)->nullable();
                $table->string('device', 120)->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }
        if (! Schema::hasTable('login_devices')) {
            Schema::create('login_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->char('token_hash', 64);
                $table->string('name', 120)->nullable();
                $table->string('last_ip', 64)->nullable();
                $table->dateTime('remember_until')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'token_hash']);
            });
        }
        if (! Schema::hasTable('password_histories')) {
            Schema::create('password_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('password');
                $table->timestamp('created_at')->nullable();
            });
        }

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'security_2fa_types')) $table->text('security_2fa_types')->nullable();
            if (! Schema::hasColumn('settings', 'security_login_alerts')) $table->boolean('security_login_alerts')->default(true);
        });

        if (! DB::table('permissions')->where('name', 'login_security')->exists()) {
            $text = 'Login security: two-step verification, sign-in alerts, locked accounts';
            $id = DB::table('permissions')->insertGetId(['name' => 'login_security', 'label' => $text, 'description' => $text, 'created_at' => now(), 'updated_at' => now()]);
            // Roles that manage the company's users get it (untick per role in Roles).
            $from = DB::table('permissions')->where('name', 'users_edit')->value('id');
            foreach ($from ? DB::table('permission_role')->where('permission_id', $from)->pluck('role_id')->unique() : [] as $rid) {
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $rid]);
            }
        }

        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', self::AFTER)->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
                if (DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', self::KEY)->exists()) {
                    continue;
                }
                $same = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $ref->company_id)->where($same)->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $ref->company_id, 'item_key' => self::KEY, 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (['Login_Security' => ['Login security', 'Seguridad de acceso']] as $key => [$en, $es]) {
                TranslationKeys::ensure($key, ['es' => $es]);
                foreach (['en' => $en, 'es' => $es] as $col => $text) {
                    DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                        ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                        ->update([$col => $text, 'updated_at' => now()]);
                }
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('login_devices');
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table) {
            foreach (['failed_logins', 'last_failed_at', 'locked_until', 'must_change_password', 'password_changed_at', 'two_factor_secret', 'two_factor_recovery', 'two_factor_confirmed_at', 'two_factor_email'] as $c) {
                if (Schema::hasColumn('users', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('settings', function (Blueprint $table) {
            foreach (['security_2fa_types', 'security_login_alerts'] as $c) {
                if (Schema::hasColumn('settings', $c)) $table->dropColumn($c);
            }
        });
    }
};
