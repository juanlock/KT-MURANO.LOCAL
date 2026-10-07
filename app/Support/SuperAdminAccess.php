<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SuperAdmin is the system's owner: every module by default (user's rule,
 * 2026-10-06). Gives the roles of SuperAdmin users (role_user, plus
 * users.role_id) every permission they don't have yet -- except the
 * menu_hide:* ones, which HIDE a menu entry. Runs after every `php artisan
 * migrate` (AppServiceProvider, MigrationsEnded), so a permission added by a
 * new module reaches the SuperAdmin without its own grant.
 */
class SuperAdminAccess
{
    /** Number of permissions granted. */
    public static function sync(): int
    {
        if (! Schema::hasTable('permission_role') || ! Schema::hasTable('role_user') || ! Schema::hasTable('permissions')) {
            return 0;
        }
        $superIds = DB::table('users')->whereRaw('LOWER(type) = ?', ['superadmin'])->pluck('id');
        if ($superIds->isEmpty()) {
            return 0;
        }
        $roles = DB::table('role_user')->whereIn('user_id', $superIds)->pluck('role_id')
            ->merge(DB::table('users')->whereIn('id', $superIds)->whereNotNull('role_id')->pluck('role_id'))
            ->filter()->unique();
        $perms = DB::table('permissions')->whereNull('deleted_at')->where('name', 'not like', 'menu\_hide:%')->pluck('id');

        $added = 0;
        foreach ($roles as $rid) {
            $have = DB::table('permission_role')->where('role_id', $rid)->pluck('permission_id')->flip();
            $rows = $perms->reject(fn ($pid) => isset($have[$pid]))->map(fn ($pid) => ['role_id' => $rid, 'permission_id' => $pid])->values()->all();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('permission_role')->insert($chunk);
            }
            $added += count($rows);
        }

        return $added;
    }
}
