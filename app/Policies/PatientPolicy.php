<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Patient records carry the module's only split permissions — a receptionist
 * registers patients, a clinician reads them, and only an administrator removes
 * one. The dashboard and reports hang here too, since patients are the entity
 * every hospital number ultimately counts.
 */
class PatientPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'hms_patients_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'hms_patients_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'hms_patients_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'hms_patients_delete');
    }

    /** The hospital dashboard. */
    public function dashboard(User $user)
    {
        return $this->check($user, 'hms_dashboard');
    }

    /** Cross-module reporting, separate from write access to any one log. */
    public function report(User $user)
    {
        return $this->check($user, 'hms_reports');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
