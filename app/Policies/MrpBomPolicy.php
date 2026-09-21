<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Bills of materials and work centres. Engineering data — changing a recipe changes what every future order costs.
 */
class MrpBomPolicy
{
    use HandlesAuthorization;

    private function has(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user)
    {
        return $this->has($user, 'mrp_boms');
    }

    public function create(User $user)
    {
        return $this->has($user, 'mrp_boms');
    }

    public function update(User $user)
    {
        return $this->has($user, 'mrp_boms');
    }

    public function delete(User $user)
    {
        return $this->has($user, 'mrp_boms');
    }
}
