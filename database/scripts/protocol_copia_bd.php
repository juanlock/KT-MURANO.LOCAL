<?php

// Protocol Copia BD -- dumps the dbmurano MySQL database via mysqldump and
// saves it to KT-TOOLS/database/ as a timestamped .sql file, so a snapshot
// travels with the repo across every machine this checkout is on.
// Run via: php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_copia_bd.php';"
//
// Reads connection details from Laravel's own config (never hardcoded), so
// this runs unchanged on any machine's .env. mysqldump's location varies per
// machine and per DB engine/version (this repo's dev DBs run on a standalone
// MariaDB Windows service, not XAMPP's bundled MySQL -- confirmed via
// `Get-CimInstance Win32_Service`, e.g. "C:\Program Files\MariaDB 11.8\bin\").
// Since that version-numbered path will differ across machines and upgrades,
// this discovers it dynamically instead of hardcoding it. Tries, in order:
//   1. MYSQLDUMP_PATH in .env, if set (highest priority -- explicit override)
//   2. `mysqldump` on PATH
//   3. The bin/ folder of a running mysqld/mariadbd Windows service
//   4. A few common Windows XAMPP install locations (last resort)

use Illuminate\Support\Facades\Process;

$conn = config('database.connections.'.config('database.default'));

function findMysqldumpNextToService(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
    }
    $ps = "Get-CimInstance Win32_Service | Where-Object { \$_.Name -match 'mysql|maria' -and \$_.State -eq 'Running' } | Select-Object -First 1 -ExpandProperty PathName";
    $result = \Illuminate\Support\Facades\Process::run(['powershell', '-NoProfile', '-Command', $ps]);
    if (! $result->successful()) {
        return null;
    }
    $pathName = trim($result->output());
    // PathName looks like: "C:\Program Files\MariaDB 11.8\bin\mysqld.exe" "--defaults-file=..." "MariaDB"
    if (! preg_match('/^"([^"]+)"/', $pathName, $m)) {
        return null;
    }
    $dumpPath = dirname($m[1]).DIRECTORY_SEPARATOR.'mysqldump.exe';

    return file_exists($dumpPath) ? $dumpPath : null;
}

$candidates = array_filter([
    env('MYSQLDUMP_PATH'),
    'mysqldump',
    findMysqldumpNextToService(),
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'D:\\xampp\\mysql\\bin\\mysqldump.exe',
]);

$mysqldump = null;
foreach ($candidates as $bin) {
    if ($bin !== 'mysqldump' && ! file_exists($bin)) {
        continue;
    }
    $probe = Process::run([$bin, '--version']);
    if ($probe->successful()) {
        $mysqldump = $bin;
        break;
    }
}

if (! $mysqldump) {
    echo "ERROR: mysqldump not found on PATH, next to a running mysql/mariadb service, or the common XAMPP install locations.\n";
    echo "Add MYSQLDUMP_PATH=<full path to mysqldump.exe> to .env on this machine and re-run.\n";
    return;
}

$outDir = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'database');
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$outFile = $outDir.DIRECTORY_SEPARATOR.'dbmurano_'.now()->format('Y-m-d_His').'.sql';

$command = [
    $mysqldump,
    '-h', (string) $conn['host'],
    '-P', (string) $conn['port'],
    '-u', (string) $conn['username'],
    '-p'.$conn['password'],
    '--routines',
    '--triggers',
    '--single-transaction',
    (string) $conn['database'],
];

$fh = fopen($outFile, 'w');
$result = Process::timeout(600)->run($command, function ($type, $chunk) use ($fh) {
    if ($type === \Symfony\Component\Process\Process::OUT) {
        fwrite($fh, $chunk);
    }
});
fclose($fh);

if (! $result->successful()) {
    @unlink($outFile);
    echo "ERROR: mysqldump failed.\n".$result->errorOutput()."\n";
    return;
}

$sizeMb = round(filesize($outFile) / 1024 / 1024, 2);
echo "Backup written to: {$outFile} ({$sizeMb} MB)\n";
