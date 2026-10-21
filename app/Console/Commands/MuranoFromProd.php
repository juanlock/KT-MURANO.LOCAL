<?php

namespace App\Console\Commands;

use App\Services\ProdDump;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Production -> this PC, the whole database (user's rule, 2026-10-20): production
 * is the only real database; a developer PC's dbmurano is a copy of it, replaced
 * every morning by Wake-up. Code goes the other way (git + protocol_prod_deploy.php,
 * schema and needed data as migrations); test data made in dev is never sent up.
 *
 *   php artisan murano:from-prod            (database + public/files + migrate)
 *   php artisan murano:from-prod --backup   (local backup tagged wakeup first)
 *   php artisan murano:from-prod --no-files
 *
 * Steps: 1) this PC's kt_logs rows production lacks go up (insert-only, by
 * location + summary) -- the log lives in production; 2) a dump of production
 * (App\Services\ProdDump: on the server + gzip, else remote MySQL) to
 * KT-TOOLS/database/production/ (git-ignored) -- if it fails dev is untouched;
 * 3) every local table / view dropped and the dump imported; 4) production's new
 * files (protocol_prod_files.php); 5) `migrate` for this PC's migrations
 * production doesn't have yet; 6) optimize:clear.
 */
class MuranoFromProd extends Command
{
    protected $signature = 'murano:from-prod {--backup : Back up the local dbmurano first (tag wakeup)} {--no-files : Skip public/files}';

    protected $description = 'Replace the local database with a copy of production (+ its new files), then run local migrations';

    public function handle(): int
    {
        $started = microtime(true);
        $prodEnv = require base_path('database/scripts/protocol_prod_conn.php');
        if (! $prodEnv) {
            return self::FAILURE;
        }
        $local = config('database.connections.'.config('database.default'));

        $dump = ProdDump::findClient('mysqldump');
        $mysql = $dump ? ProdDump::findClient('mysql', dirname($dump)) : null;
        if (! $dump || ! $mysql) {
            $this->error('mysqldump / mysql not found -- add MYSQLDUMP_PATH (and MYSQL_PATH) to .env.');

            return self::FAILURE;
        }

        if ($this->option('backup')) {
            $this->info('Local backup...');
            $backupTag = 'wakeup';
            require base_path('database/scripts/protocol_copia_bd.php');
        }

        // 1. kt_logs: this PC's rows production lacks go up first, so the copy
        // brings them back and no history is lost (also catches rows written
        // locally while production was unreachable).
        $this->info('kt_logs -> production...');
        // Matched by location + summary, not created_at: rows synced in the past
        // carry UTC times in production and Eastern times in dev (4 h apart).
        $key = fn ($r) => $r->location.'|'.$r->summary;
        $inProd = DB::connection('prod')->table('kt_logs')->get(['location', 'summary'])->keyBy($key);
        $missing = DB::table('kt_logs')->orderBy('id')->get()->reject(fn ($r) => $inProd->has($key($r)));
        if ($missing->isNotEmpty()) {
            DB::connection('prod')->transaction(function () use ($missing) {
                foreach ($missing as $r) {
                    $row = (array) $r;
                    unset($row['id']);
                    DB::connection('prod')->table('kt_logs')->insert($row);
                }
            });
        }
        $this->line("  sent {$missing->count()} row(s)");

        // 2. Dump production.
        $dir = base_path('KT-TOOLS/database/production');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $raw = "$dir/from_prod.raw.sql";
        $sql = "$dir/from_prod_latest.sql";
        $this->info("Downloading production ({$prodEnv['PROD_DB_DATABASE']})...");
        if (! ProdDump::dump($prodEnv, $raw, fn ($l) => $this->line('  '.$l))) {
            @unlink($raw);
            $this->error('Production dump FAILED -- the local database was not touched.');

            return self::FAILURE;
        }
        $sqlTools = require base_path('database/scripts/protocol_sql_clean.php');
        ($sqlTools['file'])($raw, $sql, "-- Murano: copy of production ({$prodEnv['PROD_DB_DATABASE']}), ".now()->format('Y-m-d h:i:s A')."\n\n");
        @unlink($raw);
        $this->line('  '.round(filesize($sql) / 1048576, 1).' MB');

        // 3. Replace the local database: drop everything first, so tables only
        // a local migration created don't block that migration from re-running.
        $this->info("Replacing local database {$local['database']}...");
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SELECT TABLE_NAME n, TABLE_TYPE t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $t) {
            DB::statement(($t->t === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `').$t->n.'`');
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $in = fopen($sql, 'r');
        $res = Process::timeout(1800)->env(['MYSQL_PWD' => (string) $local['password']])->input($in)->run([
            $mysql, '--host='.$local['host'], '--port='.$local['port'], '--user='.$local['username'],
            '--default-character-set=utf8mb4', $local['database'],
        ]);
        fclose($in);
        if (! $res->successful()) {
            $this->error('Import FAILED -- run `php artisan murano:from-prod` again (the dump is kept in '.$sql.').');
            $this->line($res->errorOutput());

            return self::FAILURE;
        }
        $zip = ($sqlTools['zip'])($sql);
        $this->line('  imported ('.count(DB::select('SHOW TABLES')).' tables); copy kept: '.$zip);

        // 4. Files.
        if (! $this->option('no-files')) {
            $this->info('Production files -> public/files...');
            $apply = true;
            require base_path('database/scripts/protocol_prod_files.php');
        }

        // 5-6. This PC's migrations production doesn't have yet, caches.
        $this->info('Local migrations...');
        $this->call('migrate', ['--force' => true]);
        $this->call('optimize:clear');

        $this->info('Done in '.round(microtime(true) - $started).' s -- this PC now has production\'s data.');

        return self::SUCCESS;
    }
}
