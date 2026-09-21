<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class VehicleFuelLogPolicy
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
        $permission = Permission::where('name', 'fleet_fuel')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
