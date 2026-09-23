<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A new Sales Agent role, one copy per company (matching the per-company
 * role pattern from 2026_09_23_110000_add_company_id_to_roles_table.php),
 * auto-assigned to the linked user LinkedUserService::provision() creates
 * whenever a new sales agent is added from Commissions -> Sales Agents.
 * Scoped to only the Sales and Commissions forms -- view/add/edit/delete on
 * each, nothing else.
 */
return new class extends Migration
{
    private const PERMISSION_NAMES = [
        'Sales_view', 'Sales_add', 'Sales_edit', 'Sales_delete',
        'commissions_view', 'commissions_add', 'commissions_edit', 'commissions_delete',
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::PERMISSION_NAMES)
            ->pluck('id');

        $companyIds = DB::table('companies')->pluck('id');
        $now = now();

        foreach ($companyIds as $companyId) {
            if (DB::table('roles')->where('company_id', $companyId)->where('name', 'Sales Agent')->exists()) {
                continue;
            }

            $roleId = DB::table('roles')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Sales Agent',
                'label' => 'Sales Agent',
                'description' => 'Auto-assigned to accounts created from Commissions -> Sales Agents. Access to Sales and Commissions only.',
                'status' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = $permissionIds->map(fn ($permissionId) => [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ])->all();
            if ($rows) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->where('name', 'Sales Agent')->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }
};
