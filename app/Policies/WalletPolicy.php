<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Gates the E-Wallet admin module (wallets, gift cards / wallet items,
 * withdrawals, wallet settings) behind the dedicated `ewallet` permission.
 * Registered for Wallet, GiftCard and WalletWithdrawal in AuthServiceProvider.
 */
class WalletPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user)
    {
        $permission = Permission::where('name', 'ewallet')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function create(User $user)
    {
        $permission = Permission::where('name', 'ewallet')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function update(User $user)
    {
        $permission = Permission::where('name', 'ewallet')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function delete(User $user)
    {
        $permission = Permission::where('name', 'ewallet')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function restore(User $user, Wallet $wallet)
    {
        //
    }

    public function forceDelete(User $user, Wallet $wallet)
    {
        //
    }
}
