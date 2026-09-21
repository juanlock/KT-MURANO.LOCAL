<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Timesheets: hours booked against projects and tasks.
 * One permission covers read and write: whoever records this is the same person
 * who reads it back.
 */
class ProjectTimeLogPolicy
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
        $permission = Permission::where('name', 'project_timesheets')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
