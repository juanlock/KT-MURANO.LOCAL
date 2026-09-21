<?php

namespace App\Policies;

use App\Models\LoyaltyReward;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LoyaltyRewardPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user)
    {
        $permission = Permission::where('name', 'loyalty_rewards')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function create(User $user)
    {
        $permission = Permission::where('name', 'loyalty_rewards')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function update(User $user)
    {
        $permission = Permission::where('name', 'loyalty_rewards')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function delete(User $user)
    {
        $permission = Permission::where('name', 'loyalty_rewards')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function restore(User $user, LoyaltyReward $loyaltyReward)
    {
        //
    }

    public function forceDelete(User $user, LoyaltyReward $loyaltyReward)
    {
        //
    }
}
