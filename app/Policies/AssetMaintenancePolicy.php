<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AssetMaintenancePolicy
{
    use HandlesAuthorization;

    private function allowed(User $user): bool
    {
        $permission = Permission::where('name', 'asset_maintenance')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user)
    {
        return $this->allowed($user);
    }

    public function create(User $user)
    {
        return $this->allowed($user);
    }

    public function update(User $user)
    {
        return $this->allowed($user);
    }

    public function delete(User $user)
    {
        return $this->allowed($user);
    }
}
