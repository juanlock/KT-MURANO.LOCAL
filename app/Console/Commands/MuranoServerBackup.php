<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/*
 * The server's own daily database backup (2026-10-27; scheduled 03:15 in Console\Kernel):
 * mysqldump --single-transaction | gzip into ~/murano_backups/ (outside the website
 * folder, never reachable by URL), the newest KEEP kept. The password goes through
 * MYSQL_PWD, never on the command line. Replaces Stocky's database:backup in the
 * schedule (no frequency = every minute, one uncompressed copy under storage/app/public).
 *
 *   php artisan murano:server-backup
 */
class MuranoServerBackup extends Command
{
    protected $signature = 'murano:server-backup {--keep=14 : How many backups to keep}';

    protected $description = 'Daily gzipped database backup of this server, outside the website folder';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
        $dir = env('MURANO_BACKUP_DIR') ?: ($home && is_dir($home) ? rtrim($home, '/').'/murano_backups' : storage_path('app/murano_backups'));
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir.'/'.$db['database'].'_'.now()->format('Y-m-d_His').'.sql.gz';
        $q = fn (string $s) => "'".str_replace("'", "'\\''", $s)."'";
        $cmd = 'set -o pipefail; mysqldump --host='.$q((string) $db['host']).' --port='.(int) ($db['port'] ?? 3306).' --user='.$q((string) $db['username'])
            .' --single-transaction --quick --no-tablespaces --routines --triggers --default-character-set=utf8mb4 '.$q((string) $db['database'])
            .' | gzip -6 > '.$q($file);

        $res = Process::timeout(1800)->env(['MYSQL_PWD' => (string) $db['password']])->run(['bash', '-c', $cmd]);
        if (! $res->successful() || ! is_file($file) || filesize($file) < 1000) {
            @unlink($file);
            $this->error('Backup FAILED: '.trim($res->errorOutput() ?: $res->output()));

            return self::FAILURE;
        }
        @chmod($file, 0600);

        $all = glob($dir.'/'.$db['database'].'_*.sql.gz') ?: [];
        rsort($all);
        foreach (array_slice($all, max(1, (int) $this->option('keep'))) as $old) {
            @unlink($old);
        }
        $this->info('Backup: '.$file.' ('.round(filesize($file) / 1048576, 1).' MB), '.min(count($all), (int) $this->option('keep')).' kept');

        return self::SUCCESS;
    }
}
