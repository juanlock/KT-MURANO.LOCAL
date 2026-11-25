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
        // Security (user's rule, 2026-11-25): a store page opens only for a KNOWN company with the Store module on.
        // /online_store from a new PC or a private window showed the install's own store (AdminSystem 999, "StoreX"),
        // and companies with the module off (Ruba: 255 products) still had a public store. Unknown / 999 / module off
        // = 404, as if the page did not exist.
        $cid = (int) StoreSetting::currentCompanyId();
        if ($cid <= 0 || $cid === 999 || ! \App\Support\ModuleFlags::enabled($cid, 'Store')) {
            abort(404);
        }
        $settings = StoreSetting::forCompany($cid);

        if (! $settings || ! $settings->enabled) {
            // Root-domain mode: keep "/" useful for staff when the store is off
            // instead of a dead 404 on the homepage.
            if (store_path() === '' && $request->path() === '/') {
                return redirect('/next');
            }

            abort(404);
        }
        // Only this store's company catalogue on its pages (ScopedToStoreCompany, 2026-11-25).
        if ((int) $settings->company_id) {
            app()->instance('store.company_id', (int) $settings->company_id);
        }

        return $next($request);
    }
}













