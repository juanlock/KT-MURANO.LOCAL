<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/*
 * The server's own daily database backup (2026-10-27; scheduled 03:20 in Console\Kernel):
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

        // Hostinger's PHP has no proc_open (Laravel's Process): exec() like Stocky's
        // backup, the password in the environment; else a pure-PHP dump, gzipped.
        $error = 'exec() is not available';
        if (function_exists('exec')) {
            putenv('MYSQL_PWD='.$db['password']);
            $out = [];
            @exec('bash -c '.$q($cmd).' 2>&1', $out, $code);
            putenv('MYSQL_PWD');
            $error = $code === 0 ? null : trim(implode("\n", $out));
        }
        if ($error !== null || ! is_file($file) || filesize($file) < 1000) {
            @unlink($file);
            $this->warn('mysqldump: '.($error ?: 'empty file').' -- pure-PHP dump instead.');
            try {
                $sql = substr($file, 0, -3);
                $service = new \App\Services\Updater\DatabaseBackupService();
                $cursor = [];
                do {
                    $service->dumpChunkPhp($sql, $cursor, static fn () => false);
                } while (empty($cursor['done']));
                $in = fopen($sql, 'rb');
                $gz = gzopen($file, 'wb6');
                while (! feof($in)) {
                    gzwrite($gz, fread($in, 1 << 20));
                }
                fclose($in);
                gzclose($gz);
                @unlink($sql);
            } catch (\Throwable $e) {
                @unlink($file);
                @unlink($sql ?? '');
                $this->error('Backup FAILED: '.$e->getMessage());
                $this->notifyFailure($e->getMessage());

                return self::FAILURE;
            }
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

    /** A failed backup emails the active SuperAdmin(s), like murano:error-digest. A mail problem never hides the failure. */
    private function notifyFailure(string $message): void
    {
        try {
            $site = (string) config('app.url');
            $to = \Illuminate\Support\Facades\DB::table('users')->whereNull('deleted_at')->where('type', 'SuperAdmin')->where('statut', 1)->pluck('email')
                ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! \App\Support\CompanyMail::reserved($e))->unique()->values();
            [$mailer] = \App\Support\CompanyMail::mailer(\App\Models\Setting::TEMPLATE_COMPANY_ID);
            $html = '<div style="font-family:Arial,sans-serif;color:#0f2747"><h2 style="color:#b91c1c">Murano: la copia del servidor falló</h2>'
                .'<p>'.e($site).' · '.now()->format('Y-m-d H:i').'</p><p style="font-family:monospace;font-size:12px">'.e(mb_substr($message, 0, 1000)).'</p>'
                .'<p style="color:#94a3b8;font-size:12px">murano:server-backup · storage/logs/scheduler</p></div>';
            foreach ($to as $email) {
                $mailer->html($html, fn ($m) => $m->to($email)->subject("Murano: la copia del servidor falló ($site)"));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
