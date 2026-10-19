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

// 2) migrate production
$code = Artisan::call('migrate', ['--database' => 'prod', '--force' => true]);
echo Artisan::output();
echo $code === 0 ? 'Production migrated.' : 'migrate returned '.$code, PHP_EOL;
