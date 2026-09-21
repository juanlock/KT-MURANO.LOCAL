<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Student records carry the module's only split permissions — a registrar
 * admits students, a teacher reads them, and only an administrator removes one.
 * The dashboard and reports hang here too, since the student is the entity
 * every school number ultimately counts.
 */
class StudentPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'school_students_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'school_students_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'school_students_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'school_students_delete');
    }

    /** The school dashboard. */
    public function dashboard(User $user)
    {
        return $this->check($user, 'school_dashboard');
    }

    /** Cross-module reporting, separate from write access to any one register. */
    public function report(User $user)
    {
        return $this->check($user, 'school_reports');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
