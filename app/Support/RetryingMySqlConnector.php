<?php

namespace App\Support;

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\Facades\Log;

/**
 * Hostinger allows 20 new MySQL connections per second and answers the next ones
 * with "[2002] Operation not permitted" (its support, 2026-11-04). A page that fires
 * many API calls at once goes over it for a moment: each PHP request opens its own
 * connection. So that one waits a random fraction of a second and tries again, up to
 * 4 more times (about 3 s at the most), instead of failing the request.
 */
class RetryingMySqlConnector extends MySqlConnector
{
    private const RETRIES = 4;

    public function createConnection($dsn, array $config, array $options)
    {
        for ($retry = 1; ; $retry++) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (\PDOException $e) {
                if ($retry > self::RETRIES || ! str_contains($e->getMessage(), 'Operation not permitted')) {
                    throw $e;
                }
                Log::warning('MySQL connection refused for a moment, trying again', ['retry' => $retry]);
                usleep(random_int(300000, 900000));
            }
        }
    }
}
