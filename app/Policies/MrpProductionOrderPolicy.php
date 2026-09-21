<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Production orders and shop-floor work. This is the permission that lets someone move stock, so it is deliberately separate from viewing a BOM.
 */
class MrpProductionOrderPolicy
{
    use HandlesAuthorization;

    private function has(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user)
    {
        return $this->has($user, 'mrp_production');
    }

    public function create(User $user)
    {
        return $this->has($user, 'mrp_production');
    }

    public function update(User $user)
    {
        return $this->has($user, 'mrp_production');
    }

    public function delete(User $user)
    {
        return $this->has($user, 'mrp_production');
    }
}
