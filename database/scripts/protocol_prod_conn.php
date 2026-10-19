<?php

/*
 * Shared by the production protocol scripts: registers the `prod` database
 * connection from .env.production-db (per machine, git-ignored -- never commit
 * it, never print the password). Returns the parsed values, or null (with a
 * message) when this machine has no production access.
 */

$envFile = base_path('.env.production-db');
if (! is_file($envFile)) {
    echo "Missing .env.production-db -- production access is set per machine (see CLAUDE.md).\n";
    return null;
}
$prodEnv = Dotenv\Dotenv::parse(file_get_contents($envFile));
config(['database.connections.prod' => array_merge(config('database.connections.mysql'), [
    'host' => $prodEnv['PROD_DB_HOST'], 'port' => $prodEnv['PROD_DB_PORT'] ?? 3306,
    'database' => $prodEnv['PROD_DB_DATABASE'], 'username' => $prodEnv['PROD_DB_USERNAME'],
    'password' => $prodEnv['PROD_DB_PASSWORD'], 'options' => [PDO::ATTR_TIMEOUT => 20],
])]);

return $prodEnv;
