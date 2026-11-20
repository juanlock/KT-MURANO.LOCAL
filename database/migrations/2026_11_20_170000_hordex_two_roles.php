<?php

use App\Support\UserAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * HORDEX EIRL (user's rules, 2026-11-20): only two roles -- IT Admin and Manager ("manager es el usuario"); every other role
 * is retired (its logins disabled, the role removed) and the demo people of the company go too. Both roles open on the B2B
 * board (like Hordex's home) and the side menu starts collapsed for the company (settings.sidebar_collapsed_default, a
 * new switch any company can have). Nothing when the company does not exist. Safe to run twice.
 */
return new class extends Migration
{
    private const KEEP = ['IT Admin', 'Manager'];

    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'sidebar_collapsed_default')) {
            Schema::table('settings', fn (Blueprint $t) => $t->boolean('sidebar_collapsed_default')->default(false));
        }
        $cid = DB::table('companies')->where('name', 'HORDEX EIRL')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }

        foreach (DB::table('roles')->where('company_id', $cid)->whereNull('deleted_at')->whereNotIn('name', self::KEEP)->get(['id']) as $role) {
            $users = DB::table('users')->where('company_id', $cid)->where(fn ($q) => $q->where('role_id', $role->id)
                ->orWhereIn('id', DB::table('role_user')->where('role_id', $role->id)->select('user_id')))->pluck('id');
            foreach ($users as $u) {
                if (DB::table('users')->where('id', $u)->value('statut')) {
                    UserAccess::disable((int) $u);
                }
            }
            DB::table('roles')->where('id', $role->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        // The demo people the new company got (CompanyDefaultPeople): not part of Hordex.
        $demoUsers = DB::table('users')->where('company_id', $cid)
            ->whereIn('email', ['hordex-customer@kcoltech.com', 'hordex-employee@kcoltech.com', 'hordex-supplier@kcoltech.com',
                'hordex-salesagent@kcoltech.com', 'hordex-store@kcoltech.com'])->pluck('id');
        foreach (['clients', 'employees', 'providers'] as $t) {
            if (Schema::hasColumn($t, 'user_id')) {
                DB::table($t)->where('company_id', $cid)->whereIn('user_id', $demoUsers)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            }
        }
        if (Schema::hasTable('sales_agents') && Schema::hasColumn('sales_agents', 'user_id')) {
            DB::table('sales_agents')->whereIn('user_id', $demoUsers)->update(['deleted_at' => now()]);
        }

        DB::table('roles')->where('company_id', $cid)->whereIn('name', self::KEEP)->whereNull('deleted_at')
            ->update(['home_path' => '/b2b/dashboard', 'updated_at' => now()]);
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['sidebar_collapsed_default' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Retired roles stay retired.
    }
};
