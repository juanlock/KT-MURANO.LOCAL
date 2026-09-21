<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Post-update health verification. "Update Successful" is only shown after
 * these pass. Critical failures trigger the automatic rollback.
 */
class HealthChecker
{
    public function verify(?string $expectedVersion): array
    {
        $checks = [];

        // Application files
        $missing = [];
        foreach (['artisan', 'composer.json', 'version.txt', 'public/index.php',
            'vendor/autoload.php', 'routes/api.php', 'routes/web.php',
            'app/Http/Controllers/SystemUpdateController.php'] as $file) {
            if (! File::exists(base_path(str_replace('/', DIRECTORY_SEPARATOR, $file)))) {
                $missing[] = $file;
            }
        }
        $checks[] = $this->check('required_files', 'Required application files exist', empty($missing), 'critical',
            empty($missing) ? null : 'Missing: '.implode(', ', $missing));

        // Version
        $version = null;
        try {
            $version = trim((string) File::get(base_path('version.txt')));
        } catch (\Throwable $e) {
        }
        $versionOk = $expectedVersion === null || $version === trim($expectedVersion);
        $checks[] = $this->check('version', 'Installed version is '.($version ?: 'unknown'), $versionOk, 'critical',
            $versionOk ? null : "Expected version {$expectedVersion} but found ".($version ?: 'none'));

        // Database
        $dbOk = false;
        $dbDetail = null;
        try {
            DB::select('SELECT 1');
            $dbOk = DB::getSchemaBuilder()->hasTable('settings') && DB::getSchemaBuilder()->hasTable('users');
            if (! $dbOk) {
                $dbDetail = 'Core tables are missing.';
            }
        } catch (\Throwable $e) {
            $dbDetail = $e->getMessage();
        }
        $checks[] = $this->check('database', 'Database connection and core tables OK', $dbOk, 'critical', $dbDetail);

        // Pending migrations
        $pending = null;
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            // Mirror Laravel's migration naming exactly: it strips EVERY
            // ".php" from the basename (relevant for files accidentally
            // named "*.php.php", which this codebase contains).
            $files = collect(File::glob(database_path('migrations').DIRECTORY_SEPARATOR.'*.php'))
                ->map(fn ($f) => str_replace('.php', '', basename($f)))->all();
            $pending = array_values(array_diff($files, $ran));
        } catch (\Throwable $e) {
        }
        $migOk = $pending !== null && count($pending) === 0;
        $checks[] = $this->check('migrations', 'All database migrations have run', $migOk, 'critical',
            $migOk ? null : (is_array($pending) ? count($pending).' migration(s) did not run.' : 'Migration state unreadable.'));

        // Configuration
        $envOk = File::exists(base_path('.env'));
        $keyOk = false;
        try {
            $env = (string) File::get(base_path('.env'));
            $keyOk = (bool) preg_match('/^APP_KEY=.+\S/m', $env);
        } catch (\Throwable $e) {
        }
        $checks[] = $this->check('configuration', '.env present with application key', $envOk && $keyOk, 'critical', null);

        // Storage
        $storageOk = is_writable(storage_path()) && is_writable(storage_path('framework'))
            && is_writable(base_path('bootstrap/cache'));
        $checks[] = $this->check('storage', 'Storage and cache directories writable', $storageOk, 'critical', null);

        // New autoloader actually resolves classes shipped by the package
        $autoloadOk = File::exists(base_path('vendor/composer/autoload_real.php'));
        $checks[] = $this->check('autoload', 'Composer autoloader present', $autoloadOk, 'critical', null);

        // Loopback HTTP (best effort — some hosts cannot reach themselves)
        $loopback = null;
        try {
            $url = rtrim((string) config('app.url'), '/').'/api/system-update/ping';
            if ($url !== '/api/system-update/ping') {
                $ctx = stream_context_create([
                    'http' => ['timeout' => 8, 'ignore_errors' => true],
                    'https' => ['timeout' => 8, 'ignore_errors' => true],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                $loopback = is_string($body) && strpos($body, 'pong') !== false;
            }
        } catch (\Throwable $e) {
            $loopback = false;
        }
        if ($loopback !== null) {
            $checks[] = $this->check('http', 'Application answers HTTP requests', $loopback, 'warning',
                $loopback ? null : 'Loopback request failed — verify the site manually after the update (this can be a false alarm on hosts that cannot reach their own URL).');
        }

        $criticalFailed = array_values(array_filter($checks, fn ($c) => ! $c['ok'] && $c['level'] === 'critical'));

        return ['ok' => empty($criticalFailed), 'checks' => $checks];
    }

    private function check(string $key, string $label, bool $ok, string $level, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'level' => $level, 'detail' => $detail];
    }
}
