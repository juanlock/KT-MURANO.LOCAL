<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * The three fleet logs each have ONE permission covering read and write —
 * whoever books a service is the same person who records it.
 */
class VehicleMaintenancePolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user);
    }

    public function create(User $user)
    {
        return $this->check($user);
    }

    public function update(User $user)
    {
        return $this->check($user);
    }

    public function delete(User $user)
    {
        return $this->check($user);
    }

    private function check(User $user)
    {
        $permission = Permission::where('name', 'fleet_maintenance')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
