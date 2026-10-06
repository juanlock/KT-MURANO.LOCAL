<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employee details: View / Edit permission per tab (user's request,
 * 2026-10-06), set per role in Users -> Roles:
 *   employee_tab_{basic|company|social|experiences|accounts|user|production|payments}_{view|edit}
 * Nobody loses what they had: roles with "view_employee" get every *_view,
 * roles with "edit_employee" every *_edit (untick them per role afterwards).
 * Enforced server-side by App\Support\EmployeeTabs.
 */
return new class extends Migration
{
    private const TABS = [
        'basic' => 'Basic information', 'company' => 'Company', 'social' => 'Social media', 'experiences' => 'Experiences',
        'accounts' => 'Bank accounts', 'user' => 'User', 'production' => 'Production', 'payments' => 'Payments',
    ];

    public function up(): void
    {
        $viewRoles = $this->rolesWith('view_employee');
        $editRoles = $this->rolesWith('edit_employee');
        foreach (self::TABS as $tab => $label) {
            foreach (['view' => $viewRoles, 'edit' => $editRoles] as $action => $roles) {
                $name = "employee_tab_{$tab}_{$action}";
                $id = DB::table('permissions')->where('name', $name)->value('id');
                if (! $id) {
                    $text = "Employee details: {$label} tab -- ".($action === 'view' ? 'see' : 'edit');
                    $id = DB::table('permissions')->insertGetId(['name' => $name, 'label' => $text, 'description' => $text, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($roles as $rid) {
                    if (! DB::table('permission_role')->where('permission_id', $id)->where('role_id', $rid)->exists()) {
                        DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $rid]);
                    }
                }
            }
        }
    }

    private function rolesWith(string $permission): array
    {
        $pid = DB::table('permissions')->where('name', $permission)->value('id');

        return $pid ? DB::table('permission_role')->where('permission_id', $pid)->pluck('role_id')->unique()->all() : [];
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'like', 'employee\_tab\_%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
