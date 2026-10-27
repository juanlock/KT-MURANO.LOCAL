<?php

namespace App\Console\Commands;

use App\Services\ProdDump;
use Illuminate\Console\Command;

/*
 * A backup of the PRODUCTION database on this PC (user's rule, 2026-10-20:
 * Protocol A Dormir). Read-only on production. Written to
 * KT-TOOLS/database/HOSTINGER/hostinger-<date>_<time>-<PC>-<tag>.zip
 * (git-ignored: production holds real customer data; each PC keeps its own),
 * compressed and phpMyAdmin-ready like every backup (protocol_sql_clean.php).
 *
 *   php artisan murano:prod-backup              (tag a-dormir)
 *   php artisan murano:prod-backup --tag=manual
 */
class MuranoProdBackup extends Command
{
    protected $signature = 'murano:prod-backup {--tag=a-dormir : Last part of the file name}';

    protected $description = 'Back up the production database to KT-TOOLS/database/HOSTINGER (compressed, phpMyAdmin-ready)';

    public function handle(): int
    {
        $prodEnv = require base_path('database/scripts/protocol_prod_conn.php');
        if (! $prodEnv) {
            return self::FAILURE;
        }
        $tag = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $this->option('tag'))) ?: 'a-dormir';
        $pc = preg_replace('/[^A-Za-z0-9_-]/', '-', gethostname() ?: 'unknown-pc');
        $dir = \App\Support\BackupPaths::hostinger(); // <archive>/HOSTINGER (Google Drive, 2026-10-27)
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = 'hostinger-'.now()->format('Y-m-d_h-i-s-A')."-{$pc}-{$tag}";
        $raw = "$dir/$name.raw.sql";
        $sql = "$dir/$name.sql";

        $this->info("Production ({$prodEnv['PROD_DB_DATABASE']}) -> $name.zip ...");
        if (! ProdDump::dump($prodEnv, $raw, fn ($l) => $this->line('  '.$l))) {
            @unlink($raw);
            $this->error('Production backup FAILED (nothing written).');

            return self::FAILURE;
        }
        $sqlTools = require base_path('database/scripts/protocol_sql_clean.php');
        ($sqlTools['file'])($raw, $sql, "-- Murano PRODUCTION database backup ({$prodEnv['PROD_DB_DATABASE']}), ".now()->format('Y-m-d h:i:s A')
            ." -- PC {$pc}, {$tag}, phpMyAdmin-ready.\n-- phpMyAdmin -> select the target database -> Import -> this file.\n\n");
        @unlink($raw);
        $sqlMb = round(filesize($sql) / 1048576, 1);
        $zip = ($sqlTools['zip'])($sql);
        $this->info('Backup written to: '.$zip.' ('.round(filesize($zip) / 1048576, 2)." MB zip of a {$sqlMb} MB .sql)");

        return self::SUCCESS;
    }
}
