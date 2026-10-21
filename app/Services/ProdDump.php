<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * A full mysqldump of PRODUCTION into a local file (2026-10-20), shared by
 * `murano:from-prod` (Wake-up: copy production to this PC) and
 * `murano:prod-backup` (A Dormir: keep a production backup). Read-only on
 * production.
 *
 * Fast way: mysqldump | gzip ON the server (the database is local there), one
 * ~25 MB download over SFTP. The password goes in a temporary 600 cnf file,
 * never on a command line; the cnf and the .gz are removed. Fallback (~7 min):
 * remote MySQL from this PC (password via MYSQL_PWD).
 */
class ProdDump
{
    /**
     * @param  array  $prodEnv  .env.production-db (protocol_prod_conn.php)
     * @param  string  $raw  the .sql file to write (raw mysqldump output)
     * @param  callable|null  $say  progress lines
     */
    public static function dump(array $prodEnv, string $raw, ?callable $say = null): bool
    {
        $say ??= fn ($l) => null;
        if (self::viaSsh($prodEnv, $raw, $say)) {
            return is_file($raw) && filesize($raw) > 100000;
        }
        $say('SSH dump not available -- dumping over remote MySQL (slower)...');
        $dump = self::findClient('mysqldump');
        if (! $dump) {
            $say('mysqldump not found -- add MYSQLDUMP_PATH to .env.');

            return false;
        }
        $res = Process::timeout(1800)->env(['MYSQL_PWD' => $prodEnv['PROD_DB_PASSWORD']])->run([
            $dump, '--host='.$prodEnv['PROD_DB_HOST'], '--port='.($prodEnv['PROD_DB_PORT'] ?? 3306),
            '--user='.$prodEnv['PROD_DB_USERNAME'], '--single-transaction', '--skip-lock-tables',
            '--skip-add-locks', '--no-tablespaces', '--routines', '--triggers', '--default-character-set=utf8mb4',
            '--result-file='.$raw, $prodEnv['PROD_DB_DATABASE'],
        ]);
        if (! $res->successful()) {
            @unlink($raw);
            $say(trim($res->errorOutput()));

            return false;
        }

        return is_file($raw) && filesize($raw) > 100000;
    }

    private static function viaSsh(array $prodEnv, string $raw, callable $say): bool
    {
        $envFile = base_path('.env.production-ssh');
        if (! is_file($envFile)) {
            return false;
        }
        $s = \Dotenv\Dotenv::parse(file_get_contents($envFile));
        $sftp = new \phpseclib3\Net\SFTP($s['PROD_SSH_HOST'], (int) ($s['PROD_SSH_PORT'] ?? 22), 60);
        $key = str_replace('~', getenv('USERPROFILE') ?: getenv('HOME'), (string) ($s['PROD_SSH_KEY'] ?? ''));
        $ok = false;
        try {
            if ($key && is_file($key)) {
                $ok = $sftp->login($s['PROD_SSH_USER'], \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($key)));
            }
            if (! $ok && ! empty($s['PROD_SSH_PASSWORD'])) {
                $sftp = new \phpseclib3\Net\SFTP($s['PROD_SSH_HOST'], (int) ($s['PROD_SSH_PORT'] ?? 22), 60);
                $ok = $sftp->login($s['PROD_SSH_USER'], $s['PROD_SSH_PASSWORD']);
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (! $ok) {
            return false;
        }
        $sftp->setTimeout(1800);
        $dir = 'murano_deploy';
        $sftp->mkdir($dir);
        $stamp = bin2hex(random_bytes(4));
        $cnf = "$dir/.dump_$stamp.cnf";
        $gz = "$dir/dump_$stamp.sql.gz";
        $q = fn ($v) => '"'.addcslashes((string) $v, '"\\').'"';
        $sftp->put($cnf, "[client]\nuser=".$q($prodEnv['PROD_DB_USERNAME'])."\npassword=".$q($prodEnv['PROD_DB_PASSWORD'])."\nhost=localhost\n");
        $sftp->chmod(0600, $cnf);
        $out = $sftp->exec('set -o pipefail; mysqldump --defaults-extra-file='.$cnf.' --single-transaction --skip-lock-tables --skip-add-locks'
            .' --no-tablespaces --routines --triggers --default-character-set=utf8mb4 '.escapeshellarg($prodEnv['PROD_DB_DATABASE'])
            .' | gzip -1 > '.$gz.' && echo DUMP_OK; rm -f '.$cnf);
        if (! str_contains((string) $out, 'DUMP_OK')) {
            $say(trim((string) $out));
            $sftp->delete($gz);

            return false;
        }
        $say('dumped on the server ('.round($sftp->filesize($gz) / 1048576, 1).' MB gz), downloading...');
        $local = $raw.'.gz';
        $got = $sftp->get($gz, $local);
        $sftp->delete($gz);
        if ($got === false) {
            @unlink($local);

            return false;
        }
        $in = gzopen($local, 'rb');
        $outFh = fopen($raw, 'wb');
        while (! gzeof($in)) {
            fwrite($outFh, gzread($in, 1048576));
        }
        gzclose($in);
        fclose($outFh);
        @unlink($local);

        return true;
    }

    /** MYSQLDUMP_PATH / MYSQL_PATH in .env, the folder given, PATH, the running MariaDB / MySQL service, XAMPP. */
    public static function findClient(string $name, ?string $nextTo = null): ?string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $candidates = [env(strtoupper($name).'_PATH')];
        if ($nextTo && $nextTo !== '.') {
            $candidates[] = $nextTo.DIRECTORY_SEPARATOR.$name.$exe;
        }
        $candidates[] = $name;
        if (PHP_OS_FAMILY === 'Windows') {
            $svc = Process::run(['powershell', '-NoProfile', '-Command',
                "Get-CimInstance Win32_Service | Where-Object { \$_.Name -match 'mysql|maria' -and \$_.State -eq 'Running' } | Select-Object -First 1 -ExpandProperty PathName"]);
            if (preg_match('/^"([^"]+)"/', trim($svc->output()), $m)) {
                $candidates[] = dirname($m[1]).DIRECTORY_SEPARATOR.$name.$exe;
            }
            $candidates[] = "C:\\xampp\\mysql\\bin\\{$name}.exe";
            $candidates[] = "D:\\xampp\\mysql\\bin\\{$name}.exe";
        }
        foreach (array_filter($candidates) as $bin) {
            if ($bin !== $name && ! is_file($bin)) {
                continue;
            }
            if (Process::run([$bin, '--version'])->successful()) {
                return $bin;
            }
        }

        return null;
    }
}
