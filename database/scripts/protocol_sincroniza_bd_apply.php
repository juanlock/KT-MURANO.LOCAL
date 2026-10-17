<?php

// Protocol Sincroniza BD -- APPLY step (writes to PRODUCTION; only after the
// user approved the exact tables). See .claude/commands/sincroniza-bd.md.
//
//   $mode = 'backup';                 // full backup of production only
//   $mode = 'push'; $tables = [...];  // backup, then dev -> production, per table
//   require '<repo>\database\scripts\protocol_sincroniza_bd_apply.php';
//
// Backup: mysqldump of production -> KT-TOOLS/database/production/<db>_<date>_<time>.zip
// (git-ignored folder). Push: per table, dev rows dumped (data only, explicit
// column lists) and loaded into production in ONE transaction:
// DELETE FROM t + INSERTs + COMMIT -- a failure rolls that table back and stops.
// Credentials: .env.production-db, passed to the MySQL tools through a temporary
// --defaults-extra-file deleted right after (never on a command line, never printed).

use Illuminate\Support\Facades\Process;

$mode = $mode ?? 'backup';
$tables = $tables ?? [];

$cred = [];
foreach (file(base_path('.env.production-db'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*(PROD_DB_[A-Z]+)\s*=\s*(.*)\s*$/', $line, $m)) {
        $v = trim($m[2]);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
        $cred[$m[1]] = $v;
    }
}
$prodDb = $cred['PROD_DB_DATABASE'];
$dev = config('database.connections.'.config('database.default'));

// MySQL tools: MYSQLDUMP_PATH in .env, else next to the running MariaDB/MySQL service.
$bin = function (string $tool) {
    $env = env('MYSQLDUMP_PATH');
    if ($env && file_exists($env)) return dirname($env).DIRECTORY_SEPARATOR.$tool.'.exe';
    $out = shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Service | Where-Object { $_.Name -match \'mysql|maria\' -and $_.State -eq \'Running\' } | Select-Object -First 1).PathName"');
    if ($out && preg_match('/"?([^"]+?)\\\\mysqld(-nt)?\.exe/i', $out, $m) && file_exists($m[1].'\\'.$tool.'.exe')) return $m[1].'\\'.$tool.'.exe';

    return $tool;
};
$mysqldump = $bin('mysqldump');
$mysql = $bin('mysql');

$cnf = function (array $c) {
    $f = tempnam(sys_get_temp_dir(), 'kt_cnf_');
    $q = fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
    file_put_contents($f, "[client]\nhost=".$q($c['host'])."\nport=".(int) $c['port']."\nuser=".$q($c['user'])."\npassword=".$q($c['pass'])."\n");

    return $f;
};
$prodCnf = $cnf(['host' => $cred['PROD_DB_HOST'], 'port' => $cred['PROD_DB_PORT'] ?? 3306, 'user' => $cred['PROD_DB_USERNAME'], 'pass' => $cred['PROD_DB_PASSWORD']]);
$devCnf = $cnf(['host' => $dev['host'], 'port' => $dev['port'] ?? 3306, 'user' => $dev['username'], 'pass' => $dev['password']]);

try {
    // ---------------- 1. backup production ----------------
    $dir = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'production');
    if (! is_dir($dir)) mkdir($dir, 0775, true);
    $stamp = now()->format('Y-m-d_g-i-s-A');

    // ---------------- 0. $mode = 'sql': files to import BY HAND ----------------
    // Reads dev only (production is not touched): the same per-table
    // "DELETE + INSERT in one transaction" as 'push', written to
    // KT-TOOLS/database/production/sync_to_prod_<stamp>_<n>_<part>.zip for
    // phpMyAdmin -> Import (production database selected), in number order.
    // office_emails (big) goes in 3 files of about the same size.
    if ($mode === 'sql') {
        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kt_sql_'.$stamp;
        @mkdir($tmpDir);
        $dump = function (string $t, ?string $where, $fh) use ($mysqldump, $devCnf, $dev, $tmpDir) {
            $f = $tmpDir.DIRECTORY_SEPARATOR.$t.'_'.md5((string) $where).'.sql';
            $args = [$mysqldump, '--defaults-extra-file='.$devCnf, '--no-create-info', '--skip-triggers', '--complete-insert', '--skip-add-locks', '--skip-disable-keys', '--single-transaction', '--skip-comments', '--result-file='.$f];
            if ($where) $args[] = '--where='.$where;
            $args[] = $dev['database'];
            $args[] = $t;
            $r = Process::timeout(1800)->run($args);
            if (! $r->successful()) throw new RuntimeException("dumping $t: ".$r->errorOutput());
            // Line by line, dropping MariaDB 11's "/*M!999999\- enable the sandbox
            // mode */" line (older MySQL / phpMyAdmin refuse it, as in Copia BD).
            $in = fopen($f, 'r');
            while (($line = fgets($in)) !== false) {
                if (str_starts_with($line, '/*M!999999')) continue;
                fwrite($fh, $line);
            }
            fclose($in);
            @unlink($f);
        };
        $made = [];
        $file = function (string $name, callable $body) use ($dir, $stamp, &$made) {
            $sql = $dir.DIRECTORY_SEPARATOR."sync_to_prod_{$stamp}_{$name}.sql";
            $fh = fopen($sql, 'w');
            fwrite($fh, "-- Sincroniza BD: dev -> production, import in production with phpMyAdmin (production database selected).\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $body($fh);
            fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fh);
            $zipPath = substr($sql, 0, -4).'.zip';
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile($sql, basename($sql));
            $zip->close();
            $made[] = basename($zipPath).' ('.round(filesize($zipPath) / 1048576, 2).' MB zip of '.round(filesize($sql) / 1048576, 2).' MB .sql)';
            @unlink($sql);
        };
        $small = array_values(array_filter(array_map(fn ($t) => preg_replace('/[^A-Za-z0-9_]/', '', $t), $tables), fn ($t) => $t !== 'office_emails'));
        $n = 1;
        if ($small) {
            $file(($n++).'_tables', function ($fh) use ($small, $dump) {
                foreach ($small as $t) {
                    fwrite($fh, "\n-- ===== $t =====\nSTART TRANSACTION;\nDELETE FROM `$t`;\n");
                    $dump($t, null, $fh);
                    fwrite($fh, "\nCOMMIT;\n");
                }
            });
        }
        if (in_array('office_emails', $tables, true)) {
            // Three id ranges of about the same size.
            $sizeExpr = implode(' + ', array_map(fn ($c) => "COALESCE(OCTET_LENGTH(`$c`),0)", array_column(\Illuminate\Support\Facades\DB::select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'office_emails'"), 'c')));
            $rows = \Illuminate\Support\Facades\DB::select("SELECT id, $sizeExpr s FROM office_emails ORDER BY id");
            $total = array_sum(array_map(fn ($r) => (int) $r->s, $rows));
            $cuts = [];
            $acc = 0;
            foreach ($rows as $r) {
                $acc += (int) $r->s;
                if (count($cuts) < 2 && $acc >= $total * (count($cuts) + 1) / 3) $cuts[] = (int) $r->id;
            }
            [$c1, $c2] = $cuts + [PHP_INT_MAX, PHP_INT_MAX];
            $file(($n++).'_office_emails_1', function ($fh) use ($dump, $c1) {
                fwrite($fh, "START TRANSACTION;\nDELETE FROM `office_emails`;\n");
                $dump('office_emails', "id <= $c1", $fh);
                fwrite($fh, "\nCOMMIT;\n");
            });
            $file(($n++).'_office_emails_2', function ($fh) use ($dump, $c1, $c2) {
                fwrite($fh, "START TRANSACTION;\n");
                $dump('office_emails', "id > $c1 AND id <= $c2", $fh);
                fwrite($fh, "\nCOMMIT;\n");
            });
            $file(($n++).'_office_emails_3', function ($fh) use ($dump, $c2) {
                fwrite($fh, "START TRANSACTION;\n");
                $dump('office_emails', "id > $c2", $fh);
                fwrite($fh, "\nCOMMIT;\n");
            });
        }
        @rmdir($tmpDir);
        echo "SQL files for production (import in this order), in {$dir}:\n  ".implode("\n  ", $made)."\n";

        return;
    }
    $sql = $dir.DIRECTORY_SEPARATOR."{$prodDb}_{$stamp}.sql";
    $r = Process::timeout(1800)->run([$mysqldump, '--defaults-extra-file='.$prodCnf, '--single-transaction', '--routines', '--triggers', '--no-tablespaces', '--skip-lock-tables', '--result-file='.$sql, $prodDb]);
    if (! $r->successful() || ! is_file($sql) || filesize($sql) < 1000) {
        echo "ERROR: production backup failed -- nothing was changed.\n".$r->errorOutput()."\n";
        return;
    }
    $zipPath = substr($sql, 0, -4).'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($sql, basename($sql));
    $zip->close();
    $sqlMb = round(filesize($sql) / 1048576, 2);
    @unlink($sql);
    echo 'Production backup: '.$zipPath.' ('.round(filesize($zipPath) / 1048576, 2)." MB zip of a {$sqlMb} MB .sql)\n";

    if ($mode !== 'push') return;

    // ---------------- 2. dev -> production, one table at a time ----------------
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kt_push_'.$stamp;
    @mkdir($tmp);
    foreach ($tables as $t) {
        $t = preg_replace('/[^A-Za-z0-9_]/', '', $t);
        $data = "$tmp\\$t.data.sql";
        $r = Process::timeout(1800)->run([$mysqldump, '--defaults-extra-file='.$devCnf, '--no-create-info', '--skip-triggers', '--complete-insert', '--skip-add-locks', '--skip-disable-keys', '--single-transaction', '--result-file='.$data, $dev['database'], $t]);
        if (! $r->successful()) { echo "ERROR dumping dev $t -- stopped.\n".$r->errorOutput()."\n"; return; }
        $load = "$tmp\\$t.load.sql";
        $fh = fopen($load, 'w');
        // The dump itself sets TIME_ZONE='+00:00' (TIMESTAMPs written in UTC).
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\nDELETE FROM `$t`;\n");
        $in = fopen($data, 'r');
        stream_copy_to_stream($in, $fh);
        fclose($in);
        fwrite($fh, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        $r = Process::timeout(3600)->input(fopen($load, 'r'))->run([$mysql, '--defaults-extra-file='.$prodCnf, '--max-allowed-packet=256M', $prodDb]);
        $count = trim((string) Process::run([$mysql, '--defaults-extra-file='.$prodCnf, '-N', '-e', "SELECT COUNT(*) FROM `$t`", $prodDb])->output());
        if (! $r->successful()) { echo "ERROR loading $t into production (rolled back) -- stopped.\n".$r->errorOutput()."\n"; return; }
        echo "OK $t: production now has $count rows\n";
        @unlink($data);
        @unlink($load);
    }
    @rmdir($tmp);
} finally {
    @unlink($prodCnf);
    @unlink($devCnf);
}
