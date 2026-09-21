<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Read is gated on shopify_stores; anything that writes to a shop or changes a
 * connection needs shopify_sync as well. Viewing what synced and being able to
 * push a catalogue live are very different levels of trust.
 */
class ShopifyStorePolicy
{
    use HandlesAuthorization;

    private function has(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user)
    {
        return $this->has($user, 'shopify_stores');
    }

    public function create(User $user)
    {
        return $this->has($user, 'shopify_stores');
    }

    public function update(User $user)
    {
        return $this->has($user, 'shopify_sync') || $this->has($user, 'shopify_stores');
    }

    public function delete(User $user)
    {
        return $this->has($user, 'shopify_stores');
    }
}
