<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employee details -> new "Assets assigned" tab (2026-10-07): its View / Edit
 * pair, like the other tabs (2026_10_06_110000): roles with "view_employee"
 * get the view, roles with "edit_employee" the edit (untick per role in
 * Users -> Roles). Enforced by App\Support\EmployeeTabs.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['view' => 'view_employee', 'edit' => 'edit_employee'] as $action => $from) {
            $name = "employee_tab_assets_{$action}";
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $text = 'Employee details: Assets assigned tab -- '.($action === 'view' ? 'see' : 'edit');
                $id = DB::table('permissions')->insertGetId(['name' => $name, 'label' => $text, 'description' => $text, 'created_at' => now(), 'updated_at' => now()]);
            }
            $pid = DB::table('permissions')->where('name', $from)->value('id');
            $roles = $pid ? DB::table('permission_role')->where('permission_id', $pid)->pluck('role_id')->unique() : collect();
            foreach ($roles as $rid) {
                if (! DB::table('permission_role')->where('permission_id', $id)->where('role_id', $rid)->exists()) {
                    DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $rid]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', ['employee_tab_assets_view', 'employee_tab_assets_edit'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
