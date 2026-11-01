<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may open / close a POS register (2026-11-01): the SuperAdmin; any role
 * with the "Open / close register" permission (register_open_close); and the
 * roles / users chosen in Settings -> POS -> "Who can open / close the register"
 * (settings.pos_register_openers = {"roles": [ids], "users": [ids]}, per company).
 */
class PosRegisterOpeners
{
    /** @return array{roles: array<int>, users: array<int>} */
    public static function get(int $companyId): array
    {
        $raw = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('pos_register_openers');
        $v = is_string($raw) ? json_decode($raw, true) : (array) $raw;

        return [
            'roles' => array_values(array_map('intval', (array) ($v['roles'] ?? []))),
            'users' => array_values(array_map('intval', (array) ($v['users'] ?? []))),
        ];
    }

    /** Keeps only roles / users of that company. */
    public static function clean(int $companyId, array $roles, array $users): array
    {
        return [
            'roles' => DB::table('roles')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('id', array_map('intval', $roles))->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
            'users' => DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('id', array_map('intval', $users))->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
        ];
    }

    public static function allows(User $user): bool
    {
        if ($user->type === 'SuperAdmin') {
            return true;
        }
        $role = $user->roles()->first();
        if ($role && $role->permissions->contains('name', 'register_open_close')) {
            return true;
        }
        $o = self::get((int) $user->company_id);

        return in_array((int) $user->id, $o['users'], true) || ($role && in_array((int) $role->id, $o['roles'], true));
    }

    /** "Admin, Manager, Ana Pérez" -- roles with the permission + the chosen ones + the chosen users. */
    public static function names(int $companyId): string
    {
        $o = self::get($companyId);
        $roles = DB::table('roles')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('id', $o['roles'] ?: [0])
                ->orWhereIn('id', DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->where('p.name', 'register_open_close')->select('pr.role_id')))
            ->orderBy('name')->pluck('name')->all();
        $users = DB::table('users')->whereIn('id', $o['users'] ?: [0])->whereNull('deleted_at')->orderBy('username')->pluck('username')->all();

        return implode(', ', array_merge($roles, $users));
    }
}
