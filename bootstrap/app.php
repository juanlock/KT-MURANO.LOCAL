<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

// Keep .env values out of the process environment (putenv). On LiteSpeed
// (Hostinger) a PHP worker serves many requests: values put there by one
// request stayed in memory and beat the .env on later ones (production kept
// an old DB user after .env was fixed, 2026-10-17).
Illuminate\Support\Env::disablePutenv();

// The server's .env is the source of truth: on Hostinger, LiteSpeed also
// hands every PHP worker the site's hPanel environment variables (an old
// DB_DATABASE / DB_USERNAME / DB_PASSWORD from before 2026-10-13), and real
// environment variables beat .env -- the site went down each time a new
// worker started (2026-10-18). Web requests only: CLI / tests keep their
// usual overrides.
if (PHP_SAPI !== 'cli' && is_file($envFile = dirname(__DIR__).DIRECTORY_SEPARATOR.'.env')) {
    try {
        foreach (array_keys(Dotenv\Dotenv::parse((string) file_get_contents($envFile))) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    } catch (Throwable $e) {
        // an unreadable .env: Laravel reports it while loading it
    }
}

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
