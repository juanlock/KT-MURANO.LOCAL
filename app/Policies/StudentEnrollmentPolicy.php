<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Class enrolment and promotion.
 * One permission covers read and write: whoever maintains this record is the
 * same person who reads it.
 */
class StudentEnrollmentPolicy
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
        $permission = Permission::where('name', 'school_enrollment')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
