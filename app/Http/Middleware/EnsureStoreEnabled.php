<?php

namespace App\Http\Middleware;

use App\Models\StoreSetting;
use Closure;
use Illuminate\Http\Request;

class EnsureStoreEnabled
{
    /**
     * Abort with 404 if the online store is disabled in settings.
     */
    public function handle(Request $request, Closure $next)
    {
        $settings = StoreSetting::first();

        if (! $settings || ! $settings->enabled) {
            // Root-domain mode: keep "/" useful for staff when the store is off
            // instead of a dead 404 on the homepage.
            if (store_path() === '' && $request->path() === '/') {
                return redirect('/next');
            }

            abort(404);
        }

        return $next($request);
    }
}













