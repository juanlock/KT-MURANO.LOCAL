<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner = every permission. Three added later never reached the Owner roles
 * (one per company; new companies copy the template company's Owner, so
 * fixing it covers future ones too). menu_hide:* entries are NOT
 * permissions -- they hide a menu item for the role -- so they stay off.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['edit_completed_paid_sale', 'employee_view_all', 'booking_other_staff'];

    public function up(): void
    {
        $permIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->whereNull('deleted_at')->pluck('id');
        foreach (DB::table('roles')->where('name', 'Owner')->whereNull('deleted_at')->pluck('id') as $roleId) {
            $has = DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $permIds)->pluck('permission_id');
            $rows = $permIds->diff($has)->map(fn ($p) => ['permission_id' => $p, 'role_id' => $roleId])->values()->all();
            if ($rows) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Not reversed: Owner roles are meant to carry every permission.
    }
};
