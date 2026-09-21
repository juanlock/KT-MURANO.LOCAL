<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Planning runs and their suggestions. Read-only in effect: accepting a suggestion needs the production permission as well.
 */
class MrpPlanningRunPolicy
{
    use HandlesAuthorization;

    private function has(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user)
    {
        return $this->has($user, 'mrp_planning');
    }

    public function create(User $user)
    {
        return $this->has($user, 'mrp_planning');
    }

    public function update(User $user)
    {
        return $this->has($user, 'mrp_planning');
    }

    public function delete(User $user)
    {
        return $this->has($user, 'mrp_planning');
    }
}
