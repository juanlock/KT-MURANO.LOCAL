<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DocumentPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'documents_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'documents_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'documents_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'documents_delete');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
