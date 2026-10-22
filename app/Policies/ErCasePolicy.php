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

    /** hms_er, or the admission desk (it registers arrivals and sends them to the waiting room / an ER bed, 2026-10-21). */
    private function check(User $user)
    {
        return \App\Support\HospitalShifts::can($user, 'hms_er', 'hms_admission_desk');
    }
}
