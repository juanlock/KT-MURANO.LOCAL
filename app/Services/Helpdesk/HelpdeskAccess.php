<?php

namespace App\Services\Helpdesk;

use App\Models\User;

/** Permission check used across the app (a user's first role's permissions; SuperAdmin always). */
class HelpdeskAccess
{
    public static function can(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->type === 'SuperAdmin') {
            return true;
        }

        return (bool) optional($user->roles()->first())->permissions?->contains('name', $permission);
    }
}
