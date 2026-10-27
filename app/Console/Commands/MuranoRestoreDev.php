<?php

namespace App\Console\Commands;

use App\Services\ProdDump;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Restore the local dev database from a Murano backup (user's request,
 * 2026-10-25, protocol "Restaura BD Dev"): the main zip + its office_emails
 * 1/2/3 parts made by Copia BD / Wake-up / Pa Llevar / A Dormir
 * (KT-TOOLS/database/<PC>/ and the copy in its root).
 *
 *  php artisan murano:restore-dev --list        the latest backups, newest first
 *  php artisan murano:restore-dev <zip> --yes   restore that one
 *
 * Before replacing anything the current dev database is backed up (tag
 * `prerestore`); then every local table is dropped, the main dump and the
 * office_emails parts imported in order, the local migrations run and the
 * caches cleared. Production is never touched.
 */
class MuranoRestoreDev extends Command
{
    protected $signature = 'murano:restore-dev {zip? : The backup (main zip) to restore; default the latest} {--list : Only list the latest backups} {--yes : Restore without asking (the protocol already asked)}';

    protected $description = 'Restore the local dev database from a Murano backup (never production)';

    public function handle(): int
    {
        $backups = $this->backups();
        if ($this->option('list')) {
            foreach (array_slice($backups, 0, 8) as $i => $b) {
                $this->line(sprintf('%d) %s | %s | PC %s | %s | %s MB%s | %s', $i + 1, $b['when'], $b['tag'], $b['pc'], $b['age'],
                    round($b['size'] / 1048576, 1), $b['parts'] ? ' + office_emails '.implode('/', array_keys($b['parts'])) : '', $b['path']));
            }

            return self::SUCCESS;
        }
        $pick = $this->argument('zip') ? collect($backups)->firstWhere('path', realpath($this->argument('zip')) ?: $this->argument('zip')) : ($backups[0] ?? null);
        if (! $pick) {
            $this->error('No backup found.');

            return self::FAILURE;
        }
        $this->info("Backup: {$pick['when']} ({$pick['tag']}, PC {$pick['pc']}) -- {$pick['path']}");
        if (! $this->option('yes') && ! $this->confirm('Replace the local database '.config('database.connections.'.config('database.default').'.database').' with it?')) {
            return self::SUCCESS;
        }
        $local = config('database.connections.'.config('database.default'));
        $dump = ProdDump::findClient('mysqldump');
        $mysql = $dump ? ProdDump::findClient('mysql', dirname($dump)) : null;
        if (! $mysql) {
            $this->error('mysql client not found -- add MYSQL_PATH (and MYSQLDUMP_PATH) to .env.');

            return self::FAILURE;
        }

        // 1. Safety copy of what is there now.
        $this->info('Backing up the current dev database (prerestore)...');
        $backupTag = 'prerestore';
        require base_path('database/scripts/protocol_copia_bd.php');

        // 2. Unzip the parts.
        $tmp = storage_path('app/restore_'.date('Ymd_His'));
        @mkdir($tmp, 0775, true);
        $files = [];
        foreach (array_merge([0 => $pick['path']], $pick['parts']) as $n => $zipPath) {
            $z = new \ZipArchive();
            if ($z->open($zipPath) !== true) {
                $this->error("Cannot open $zipPath");

                return self::FAILURE;
            }
            $z->extractTo("$tmp/$n");
            $z->close();
            $sql = collect(glob("$tmp/$n/*.sql") ?: [])->first();
            if (! $sql) {
                $this->error("No .sql inside $zipPath");

                return self::FAILURE;
            }
            $files[$n] = $sql;
        }
        ksort($files); // main (0), then office_emails 1, 2, 3

        // 3. Replace the local database.
        $this->info("Replacing {$local['database']}...");
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SELECT TABLE_NAME n, TABLE_TYPE t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $t) {
            DB::statement(($t->t === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `').$t->n.'`');
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        foreach ($files as $n => $sql) {
            $this->line('  importing '.($n ? "office_emails $n" : 'main').' ('.round(filesize($sql) / 1048576, 1).' MB)');
            $in = fopen($sql, 'r');
            $res = Process::timeout(3600)->env(['MYSQL_PWD' => (string) $local['password']])->input($in)->run([
                $mysql, '--host='.$local['host'], '--port='.$local['port'], '--user='.$local['username'],
                '--default-character-set=utf8mb4', $local['database'],
            ]);
            fclose($in);
            if (! $res->successful()) {
                $this->error('Import FAILED: '.trim($res->errorOutput()).' -- the prerestore backup is in '.\App\Support\BackupPaths::pc().'/');

                return self::FAILURE;
            }
        }
        $this->deleteDir($tmp);
        $this->line('  imported ('.count(DB::select('SHOW TABLES')).' tables)');

        // 4. This PC's code may be newer than the backup: its migrations, caches.
        $this->info('Local migrations...');
        $this->call('migrate', ['--force' => true]);
        $this->call('optimize:clear');
        $this->info('Done: the dev database is the backup of '.$pick['when'].' ('.$pick['pc'].').');

        return self::SUCCESS;
    }

    /** Every main backup zip (not the office_emails parts), newest first, with its parts. */
    private function backups(): array
    {
        // The latest copy in the repo + every PC folder of the backup archive (Google Drive, 2026-10-27).
        $root = \App\Support\BackupPaths::latest();
        $archive = \App\Support\BackupPaths::archive();
        $zips = array_values(array_unique(array_merge(glob("$root/dbmurano_*.zip") ?: [], glob("$root/*/dbmurano_*.zip") ?: [], glob("$archive/*/dbmurano_*.zip") ?: [])));
        $out = [];
        foreach ($zips as $z) {
            $name = basename($z);
            if (str_contains($name, '_office_emails_') || str_contains(str_replace('\\', '/', $z), '/Older/')) {
                continue;
            }
            $parts = [];
            for ($n = 1; $n <= 3; $n++) {
                $p = preg_replace('/\.zip$/', "_office_emails_$n.zip", $z);
                if (is_file($p)) {
                    $parts[$n] = realpath($p);
                }
            }
            // dbmurano_<date>_<time>_<tag>.zip (PC folder) or dbmurano_<date>_<PC>_<time>_<tag>.zip (root copy)
            preg_match('/dbmurano_(\d{4}-\d{2}-\d{2})_(?:([A-Z0-9_-]+?)_)?(\d{2}-\d{2}-\d{2}-[AP]M|\d{2}-\d{2}-\d{2})_([a-z]+)\.zip$/i', $name, $m);
            $pc = ($m[2] ?? '') ?: basename(dirname($z));
            $mtime = filemtime($z);
            // The time in the file name (a git checkout changes the file's own date).
            if (isset($m[1], $m[3])) {
                $t = str_replace('-', ':', preg_replace('/-([AP]M)$/', ' $1', $m[3]));
                try {
                    $mtime = \Carbon\Carbon::createFromFormat(str_contains($t, 'M') ? 'Y-m-d h:i:s A' : 'Y-m-d H:i:s', $m[1].' '.$t)->timestamp;
                } catch (\Throwable $e) {
                    // keep the file's date
                }
            }
            $key = $name.'|'.filesize($z);
            $out[$key] = [
                'path' => realpath($z), 'size' => filesize($z), 'parts' => $parts, 'tag' => $m[4] ?? '?',
                'pc' => $pc === 'database' ? '?' : $pc, 'when' => ($m[1] ?? date('Y-m-d', $mtime)).' '.str_replace('-', ':', preg_replace('/-([AP]M)$/', ' $1', $m[3] ?? date('H-i-s', $mtime))),
                'mtime' => $mtime, 'age' => \Carbon\Carbon::createFromTimestamp($mtime)->diffForHumans(),
            ];
        }
        // The root copy duplicates a PC-folder backup: keep the PC-folder one.
        $seen = [];
        $list = collect($out)->sortByDesc('mtime')->filter(function ($b) use (&$seen) {
            $k = $b['when'].'|'.$b['size'];
            if (isset($seen[$k])) {
                return false;
            }

            return $seen[$k] = true;
        })->values()->all();

        return $list;
    }

    private function deleteDir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
