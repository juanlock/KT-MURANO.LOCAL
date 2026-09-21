<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Clinical departments.
 * One permission covers read and write: whoever maintains this record is the
 * same person who reads it.
 */
class HospitalDepartmentPolicy
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
        $permission = Permission::where('name', 'hms_departments')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
