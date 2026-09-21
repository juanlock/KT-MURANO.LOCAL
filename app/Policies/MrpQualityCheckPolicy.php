<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quality inspections. Separate from production because the person who builds a batch should not always be the one who passes it.
 */
class MrpQualityCheckPolicy
{
    use HandlesAuthorization;

    private function has(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user)
    {
        return $this->has($user, 'mrp_quality');
    }

    public function create(User $user)
    {
        return $this->has($user, 'mrp_quality');
    }

    public function update(User $user)
    {
        return $this->has($user, 'mrp_quality');
    }

    public function delete(User $user)
    {
        return $this->has($user, 'mrp_quality');
    }
}
