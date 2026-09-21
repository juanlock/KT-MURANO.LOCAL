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
                $view->with('app_settings', Setting::first());
            }

            // Category data is only needed by the main application views; keep it
            // off the lighter auth / portal pages and the storefront (whose base
            // path is configurable — is_store_request() follows the setting).
            $categoriesExcluded = [
                'api',
                'setup',
                'update',
                'password',
            ];

            if (! in_array($firstSegment, $categoriesExcluded) && ! is_store_request(request())) {
                $categories = Schema::hasTable('subcategories')
                    ? \App\Models\Category::with('subcategories')->orderBy('name')->get()
                    : \App\Models\Category::orderBy('name')->get();

                $view->with('categories', $categories);
            }
        });

        // Set the default guard to 'store' for all 'store/*' routes
        $this->app['router']->matched(function (\Illuminate\Routing\Events\RouteMatched $event) {
            if ($event->route->action['middleware'] === 'auth.store') {
                Auth::shouldUse('store');
            }
        });
    }
}
