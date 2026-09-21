<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class VehiclePolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'fleet_vehicles_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'fleet_vehicles_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'fleet_vehicles_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'fleet_vehicles_delete');
    }

    /** Fleet reports live behind their own permission. */
    public function report(User $user)
    {
        return $this->check($user, 'fleet_reports');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
