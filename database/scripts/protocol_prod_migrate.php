<?php

/*
 * Protocol Pa Llevar -- step "dev -> production": applies dev's database changes
 * (the migrations production hasn't run) to the production database.
 * Every dev database change -- tables, columns AND the data a feature needs
 * (permissions, settings, translations, menus) -- travels as a migration; no
 * rows are copied from dev to production.
 *
 *   $apply = false  -> lists the pending migrations and the SQL they would run (--pretend)
 *   $apply = true   -> backs production up first (mysqldump -> KT-TOOLS/database/production/,
 *                      git-ignored), then php artisan migrate --database=prod --force
 *
 *   php artisan tinker --execute="$apply = false; require '<repo>/database/scripts/protocol_prod_migrate.php';"
 *
 * Production's code is deployed separately: a migration that drops or renames
 * something production's current code still uses must wait for that deploy --
 * check the pending list before applying.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$apply = $apply ?? false;
$prodEnv = require __DIR__.'/protocol_prod_conn.php';
if (! $prodEnv) return;

$p = DB::connection('prod');
$ran = $p->table('migrations')->pluck('migration')->all();
$pending = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => str_replace('.php', '', basename($f)) /* same naming as Laravel's Migrator */)->diff($ran)->sort()->values();
echo 'production: ', $p->getDatabaseName(), ' -- pending migrations: ', $pending->count(), PHP_EOL;
foreach ($pending as $m) echo '  ', $m, PHP_EOL;
if ($pending->isEmpty()) return;

if (! $apply) {
    Artisan::call('migrate', ['--database' => 'prod', '--pretend' => true, '--force' => true]);
    echo PHP_EOL, Artisan::output(), PHP_EOL, 'Dry run -- nothing written. Run again with $apply = true.', PHP_EOL;
    return;
}

// 1) backup production (compressed, phpMyAdmin-ready; shared helper -- if it
//    fails, nothing is applied)
$backupLabel = 'before_migrate';
if (! (require __DIR__.'/protocol_prod_backup.php')) {
    echo 'Nothing applied.', PHP_EOL;
    return;
}

// 2) migrate production. From a deploy ($migrateOnServer, set by protocol_prod_deploy.php)
// the migrations run ON THE SERVER over SSH (2026-11-03): a migration that writes files
// (a website page, downloaded pictures) writes them on the server's disk. Run from this
// PC against the production DB, those files stayed on the deploying PC (Ruba's form,
// Turismo's pictures). Standalone, it still runs here with --database=prod.
if (isset($migrateOnServer) && is_callable($migrateOnServer)) {
    $out = (string) $migrateOnServer();
    $code = preg_match('/__EXIT:(\d+)/', $out, $mm) ? (int) $mm[1] : 1;
    echo trim(preg_replace('/__EXIT:\d+/', '', $out)), PHP_EOL;
    echo $code === 0 ? 'Production migrated (on the server).' : 'migrate on the server returned '.$code, PHP_EOL;
} else {
    $code = Artisan::call('migrate', ['--database' => 'prod', '--force' => true]);
    echo Artisan::output();
    echo $code === 0 ? 'Production migrated.' : 'migrate returned '.$code, PHP_EOL;
}
