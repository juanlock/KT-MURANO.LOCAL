<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Storage;

class CheckInstalled
{
    /**
     * Force every request to the setup wizard until installation is completed
     * (the "installed" flag file exists on the public disk). Runs globally so
     * authenticated SPA routes (/next) and API calls are covered too.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Storage::disk('public')->exists('installed')) {
            return $next($request);
        }

        // The setup wizard itself must stay reachable
        if ($request->is('setup') || $request->is('setup/*')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Application is not installed.'], 503);
        }

        return redirect('/setup');
    }
}
