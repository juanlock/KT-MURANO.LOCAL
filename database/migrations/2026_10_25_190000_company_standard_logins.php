<?php

use App\Support\CompanyLogins;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standard logins (user's rule, 2026-10-25):
 *  - permission company_logins_view (the header's "Logins" list) for every
 *    company's Owner / Admin-ALL role;
 *  - Vitalis (the hospital test company): default passwords per type, the
 *    admin / helpdesk / info access logins with their roles, and every login
 *    of a typed user set to its standard password, so each role can be tested.
 * Safe to run twice; by name, not by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('name', 'company_logins_view')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'company_logins_view', 'label' => 'Company logins', 'description' => 'Header list of the company logins', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->whereIn('name', ['Owner', 'Admin-ALL'])->whereNull('deleted_at')->pluck('id') as $roleId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            TranslationKeys::ensure('Company_logins_view', ['en' => 'Company logins list', 'es' => 'Lista de logins de la empresa']);
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }

        foreach (DB::table('companies')->where('name', 'Vitalis')->whereNull('deleted_at')->pluck('id') as $cid) {
            CompanyLogins::applyToTestCompany((int) $cid);
        }
    }

    public function down(): void
    {
        // Logins and passwords stay; only the permission goes.
        $permId = DB::table('permissions')->where('name', 'company_logins_view')->value('id');
        if ($permId) {
            DB::table('permission_role')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
