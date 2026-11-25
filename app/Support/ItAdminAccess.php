<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's IT Admin is that company's SuperAdmin (user's rule, 2026-11-25: "se supone que es como un superadmin para esa
 * compañía"; turismo-admin saw 2 of the 17 dashboard tabs). For every company's IT Admin role:
 *  - every dashboard tab its permissions allow (roles.dashboard_tabs = null);
 *  - every permission any other role of the SAME company holds (never menu_hide:*, never another company's), so a screen
 *    built for one role (Mayte's boards, the pilots' My trip...) is always open to the IT Admin too.
 * Runs after every `php artisan migrate` (AppServiceProvider, MigrationsEnded), like SuperAdminAccess.
 * The company's own menu_hide:* choices on the IT Admin are left as they are.
 */
class ItAdminAccess
{
    /** ['roles' => n, 'granted' => n, 'tabs_opened' => n] */
    public static function sync(?int $companyId = null): array
    {
        $out = ['roles' => 0, 'granted' => 0, 'tabs_opened' => 0];
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role') || ! Schema::hasColumn('roles', 'company_id')) {
            return $out;
        }
        $hasTabs = Schema::hasColumn('roles', 'dashboard_tabs');
        $admins = DB::table('roles')->where('name', CompanyRoles::ADMIN)->whereNull('deleted_at')->whereNotNull('company_id')
            ->where('company_id', '!=', 999)->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get(['id', 'company_id']);

        foreach ($admins as $admin) {
            $out['roles']++;
            if ($hasTabs && DB::table('roles')->where('id', $admin->id)->whereNotNull('dashboard_tabs')->update(['dashboard_tabs' => null])) {
                $out['tabs_opened']++;
            }
            $others = DB::table('roles')->where('company_id', $admin->company_id)->where('id', '!=', $admin->id)->whereNull('deleted_at')->pluck('id');
            if ($others->isEmpty()) {
                continue;
            }
            $want = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->whereIn('pr.role_id', $others)->whereNull('p.deleted_at')->where('p.name', 'not like', 'menu\_hide:%')
                ->distinct()->pluck('p.id');
            $have = DB::table('permission_role')->where('role_id', $admin->id)->pluck('permission_id')->flip();
            $rows = $want->reject(fn ($pid) => isset($have[$pid]))->map(fn ($pid) => ['role_id' => $admin->id, 'permission_id' => $pid])->values()->all();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('permission_role')->insert($chunk);
            }
            $out['granted'] += count($rows);
        }

        return $out;
    }
}
