<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** Emergency Room board (2026-10-21): one permission, hms_er, reads and works it. */
class ErCasePolicy
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
        $permission = Permission::where('name', 'hms_er')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
