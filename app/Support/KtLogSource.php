<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where the KT-Log screen reads kt_logs (2026-10-26). kt_logs lives in
 * PRODUCTION (Kelsy, 2026-10-20): on a developer PC with production access
 * (.env.production-db, git-ignored) the screen reads production's table -- read
 * only -- so today's rows show; production itself (no such file) and a PC
 * without access, or production not answering, read the local database.
 */
class KtLogSource
{
    private static ?array $resolved = null;

    /** [connection name, 'production' | 'local'] */
    public static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        $envFile = base_path('.env.production-db');
        if (is_file($envFile)) {
            try {
                $env = \Dotenv\Dotenv::parse(file_get_contents($envFile));
                config(['database.connections.kt_logs_prod' => array_merge(config('database.connections.mysql'), [
                    'host' => $env['PROD_DB_HOST'], 'port' => $env['PROD_DB_PORT'] ?? 3306,
                    'database' => $env['PROD_DB_DATABASE'], 'username' => $env['PROD_DB_USERNAME'],
                    'password' => $env['PROD_DB_PASSWORD'], 'options' => [\PDO::ATTR_TIMEOUT => 5],
                ])]);
                DB::connection('kt_logs_prod')->getPdo();

                return self::$resolved = ['kt_logs_prod', 'production'];
            } catch (\Throwable $e) {
                // Production unreachable (network, IP not allowed): the local copy.
            }
        }

        return self::$resolved = [config('database.default'), 'local'];
    }

    public static function connection(): string
    {
        return self::resolve()[0];
    }
}
