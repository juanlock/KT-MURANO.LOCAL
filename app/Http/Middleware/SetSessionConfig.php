<?php

// app/Http/Middleware/SetSessionConfig.php

namespace App\Http\Middleware;

use Closure;
use Config;
use Illuminate\Support\Facades\DB;

class SetSessionConfig
{
    /**
     * When the admin sets the session timeout to "never expire" (null), use
     * one year so neither the web session nor the Passport cookie dies
     * mid-shift.
     */
    private const NEVER_EXPIRE_MINUTES = 525600;

    public function handle($request, Closure $next)
    {
        if (is_store_request($request)) {
            $storeBase = store_path();
            Config::set('session.path', $storeBase === '' ? '/' : '/'.$storeBase);
            Config::set('session.cookie', 'store_session');
        } else {
            Config::set('session.path', '/');
            Config::set('session.cookie', 'web_session');

            // Configurable session timeout (System Settings → Security) — staff
            // sessions only; storefront customers keep the default lifetime.
            // This must run before StartSession AND before Passport's
            // CreateFreshApiToken: session.lifetime drives both the web session
            // expiry and the laravel_token cookie's JWT expiry that the SPA
            // authenticates with. Guarded so a missing table/column (installer,
            // pending migration) silently keeps the .env SESSION_LIFETIME default.
            try {
                $minutes = DB::table('settings')->whereNull('deleted_at')->value('session_timeout_minutes');
                Config::set(
                    'session.lifetime',
                    ($minutes !== null && (int) $minutes > 0) ? (int) $minutes : self::NEVER_EXPIRE_MINUTES
                );
            } catch (\Throwable $e) {
                // keep config/session.php default
            }
        }

        return $next($request);
    }
}
