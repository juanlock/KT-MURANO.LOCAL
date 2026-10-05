<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ruba Garden now runs on Service Jobs (2026_10_04_230100), but its admin
 * role "Manager RG" (company 2, used by AdminRG) never had the Service
 * permissions, so the menu stayed hidden. Grants the module and its reports.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['service_jobs', 'service_jobs_report', 'checklist_completion_report', 'customer_maintenance_history_report'];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('company_id', 2)->where('name', 'Manager RG')->whereNull('deleted_at')->value('id');
        if (! $roleId) {
            return;
        }
        $permIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->whereNull('deleted_at')->pluck('id');
        $has = DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $permIds)->pluck('permission_id');
        $rows = $permIds->diff($has)->map(fn ($p) => ['permission_id' => $p, 'role_id' => $roleId])->values()->all();
        if ($rows) {
            DB::table('permission_role')->insert($rows);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('company_id', 2)->where('name', 'Manager RG')->value('id');
        if ($roleId) {
            DB::table('permission_role')->where('role_id', $roleId)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id'))->delete();
        }
    }
};
