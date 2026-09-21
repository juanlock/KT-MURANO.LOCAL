<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class CheckForMaintenanceMode extends Middleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array
     */
    protected $except = [
        // System Update endpoints must stay reachable while the application
        // is down for updating: the admin UI drives the resumable update
        // steps and polls progress through them.
        'api/system-update/*',
        // Standalone recovery console (works without the SPA) + the login
        // routes needed to reach it if the admin session expired mid-update.
        'system-update/recovery',
        'login',
        'logout',
    ];
}
