<?php

use App\Support\CompanyRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The SuperAdmin users' role is called SuperAdmin (user's rule, 2026-11-02), like
 * the user type -- it was "Owner". Only AdminSystem's (999), the one the
 * SuperAdmin users hold; a deleted company's leftover Owner role is left alone.
 * By name, safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        $exists = DB::table('roles')->where('company_id', $tpl)->where('name', CompanyRoles::SUPERADMIN)->whereNull('deleted_at')->exists();
        if (! $exists) {
            DB::table('roles')->where('company_id', $tpl)->where('name', 'Owner')->whereNull('deleted_at')->update([
                'name' => CompanyRoles::SUPERADMIN, 'label' => CompanyRoles::SUPERADMIN,
                'description' => 'SuperAdmin: every permission, every company.', 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        DB::table('roles')->where('company_id', $tpl)->where('name', CompanyRoles::SUPERADMIN)->whereNull('deleted_at')
            ->update(['name' => 'Owner', 'label' => 'Owner', 'description' => 'Owner', 'updated_at' => now()]);
    }
};
