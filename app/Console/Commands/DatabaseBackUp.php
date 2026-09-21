<?php

namespace App\Console\Commands;

use App\Services\Updater\DatabaseBackupService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DatabaseBackUp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $backupDir = storage_path().'/app/public/backup';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        foreach (glob($backupDir.'/*') as $filename) {
            $path = $backupDir.'/'.basename($filename);
            @unlink($path);
        }

        $filename = 'backup-'.Carbon::now()->format('Y-m-d').'.sql';
        $outputPath = $backupDir.'/'.$filename;

        // Shared hosting often disables exec()/escapeshellarg() through
        // disable_functions, which removes them entirely (function_exists()
        // then returns false). Only take the mysqldump route when both are
        // really callable; otherwise dump with pure PHP.
        $binaryError = null;
        if (function_exists('exec') && function_exists('escapeshellarg')) {
            $binaryError = $this->dumpWithMysqldump($outputPath);
            if ($binaryError === null) {
                return 0;
            }
            @unlink($outputPath);
        }

        try {
            $this->dumpWithPhp($outputPath);

            return 0;
        } catch (\Throwable $e) {
            @unlink($outputPath);
            $err = 'PHP database dump failed: '.$e->getMessage();
            if ($binaryError !== null) {
                $err = $binaryError."\n".$err;
            }
            $this->line('ERROR_DETAILS: '.$err);

            return 1;
        }
    }

    /**
     * Dump through the mysqldump binary.
     * Returns null on success, or an error description on failure.
     */
    private function dumpWithMysqldump(string $outputPath): ?string
    {
        // env() returns null when config is cached, so read credentials from
        // config() and fall back to plain "mysqldump" when DUMP_PATH is unset.
        $connection = config('database.default');
        $db = config('database.connections.'.$connection);

        $dumpPath = trim((string) env('DUMP_PATH'));
        if ($dumpPath === '') {
            $dumpPath = 'mysqldump';
        } elseif (strpos($dumpPath, ' ') !== false && $dumpPath[0] !== '"') {
            $dumpPath = '"'.$dumpPath.'"';
        }

        $db_user = $db['username'] ?? '';
        $db_pass = $db['password'] ?? '';
        $db_host = $db['host'] ?? '127.0.0.1';
        $db_name = $db['database'] ?? '';

        $command = $dumpPath
            .' --user='.escapeshellarg($db_user)
            .($db_pass !== '' ? ' --password='.escapeshellarg($db_pass) : '')
            .' --host='.escapeshellarg($db_host)
            .' '.escapeshellarg($db_name)
            .' > '.escapeshellarg($outputPath);

        $output = [];
        $returnVar = null;
        try {
            @\exec($command.' 2>&1', $output, $returnVar);
        } catch (\Throwable $e) {
            return 'mysqldump could not be executed: '.$e->getMessage();
        }

        if ($returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            return null;
        }

        $err = 'mysqldump failed (exit code: '.var_export($returnVar, true).')';
        if (!empty($output)) {
            $err .= "\n".implode("\n", $output);
        } elseif (file_exists($outputPath) && filesize($outputPath) > 0) {
            $err .= "\n".trim((string) @file_get_contents($outputPath));
        }

        return $err;
    }

    /** Pure-PHP dump, used when mysqldump is unavailable or fails. */
    private function dumpWithPhp(string $outputPath): void
    {
        $service = new DatabaseBackupService();
        $cursor = [];
        $neverStop = static function () {
            return false;
        };

        do {
            $service->dumpChunkPhp($outputPath, $cursor, $neverStop);
        } while (empty($cursor['done']));

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('the dump file was not written.');
        }
    }
}
