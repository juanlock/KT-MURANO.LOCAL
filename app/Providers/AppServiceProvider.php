<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('db.connector.mysql', \App\Support\RetryingMySqlConnector::class);

        // tdb() / locale_dir(), used by the guest Blade pages (login, password
        // reset). It is listed in composer.json's autoload.files too, but an
        // install that receives this file through an app update has not run
        // `composer dump-autoload` — requiring it here means the helper is
        // there either way. The file guards every function with
        // function_exists(), so a double load is a no-op.
        require_once app_path('Support/translations.php');

        // Wholesale Pricing by Quantity: one instance per request so the
        // toggle lookup and the tier ladders are queried once, no matter how
        // many controllers, services and views ask for them.
        $this->app->singleton(\App\Services\WholesalePricingService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        Schema::defaultStringLength(191);

        /* ADD THIS LINES */
        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        // SuperAdmin = the owner: every module by default. After each migrate,
        // permissions added by new modules are given to the SuperAdmin roles.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsEnded::class, function ($e) {
            if (($e->method ?? 'up') === 'up') {
                try {
                    \App\Support\SuperAdminAccess::sync();
                } catch (\Throwable $ex) {
                    // never break a migrate run
                }
            }
        });

        // A developer PC (database dbmurano) never emails real people: everything to one address.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, [\App\Support\DevMailGuard::class, 'handle']);

        View::composer('*', function ($view) {
            $firstSegment = Request::segment(1); // Get the first segment of the URL

            // The settings table may not exist yet during initial install/update,
            // so only skip those flows (and the API). Auth pages (login, password
            // reset, email verify) and the online store still get the app settings
            // so their page title/logo reflect the configured app name.
            $settingsExcluded = [
                'api',
                'setup',
                'update',
            ];

            if (! in_array($firstSegment, $settingsExcluded)) {
                // Login/password-reset pages render before any user is
                // authenticated -- there's no company to resolve yet (no
                // session, no acting-company switcher), so their branding is
                // intentionally ONE shared, install-wide look rather than
                // "whichever company happens to be Setting::first()" (which
                // used to silently follow row insertion order). Pinned to the
                // reserved template company (999) -- see the matching
                // Setting::forCompany(Setting::TEMPLATE_COMPANY_ID) calls in
                // SettingsController::get/update_appearance_settings(),
                // which is where a SuperAdmin actually edits this "Login
                // page" card, regardless of which company is currently
                // selected in the topbar switcher.
                $app_settings = in_array($firstSegment, ['login', 'password'])
                    ? Setting::forCompany(Setting::TEMPLATE_COMPANY_ID)
                    : Setting::first();
                $view->with('app_settings', $app_settings);
            }

            // Category data is only needed by the main application views; keep it
            // off the lighter auth / portal pages and the storefront (whose base
            // path is configurable — is_store_request() follows the setting).
            $categoriesExcluded = [
                'api',
                'setup',
                'update',
                'password',
                'support', // helpdesk public pages (standalone layout)
                // Portfolio public sites: their views use their own $categories
                // (portfolio filter buttons) -- this one overrode them with every
                // company's product categories.
                'portfolio',
            ];

            // (and a company website at its own address -- WebsiteCompany middleware)
            if (! in_array($firstSegment, $categoriesExcluded) && ! is_store_request(request()) && ! request()->attributes->get('website_company')) {
                $categories = Schema::hasTable('subcategories')
                    ? \App\Models\Category::with('subcategories')->orderBy('name')->get()
                    : \App\Models\Category::orderBy('name')->get();

                $view->with('categories', $categories);
            }
        });

        // Set the default guard to 'store' for all 'store/*' routes
        $this->app['router']->matched(function (\Illuminate\Routing\Events\RouteMatched $event) {
            if (($event->route->action['middleware'] ?? null) === 'auth.store') {
                Auth::shouldUse('store');
            }
        });
    }
}
