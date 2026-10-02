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

// Target folder under KT-TOOLS: "database" (default, the protocol's backup
// history) or another one set before the require, e.g. $outSubdir = 'SQL'
// for an export handed to an external backup program.
$outSubdir = isset($outSubdir) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $outSubdir) ? $outSubdir : 'database';
$outDir = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.$outSubdir);
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
// Filename: date - host - time, with a human-readable 12-hour time (not a
// bare digit run like "151648") so the file's own name tells you when and
// on which machine it was made without opening it. Host comes from the OS
// hostname (e.g. "KT-THUNDERA") -- no per-machine config needed, and it
// already matches this project's Kelsy location naming (Thundera/Home).
// Colons aren't valid in Windows filenames, so the time uses hyphens:
// "11-23-06-AM", not "11:23:06 AM". now() correctly reflects the real local
// clock since APP_TIMEZONE is set to America/New_York app-wide.
$host = gethostname() ?: 'unknown-host';
$date = now()->format('Y-m-d');
$time = now()->format('h-i-s-A');
$outFile = $outDir.DIRECTORY_SEPARATOR."dbmurano_{$date}_{$host}_{$time}.sql";

// phpMyAdmin-ready (user's rule, 2026-10-01): the file must import as-is
// through phpMyAdmin -> Import on shared hosting (e.g. Hostinger), into
// whatever database is selected there. So:
//  - one database, no --databases: no CREATE DATABASE / USE inside;
//  - --skip-add-locks: no LOCK TABLES (that privilege isn't always granted);
//  - utf8mb4 throughout; DROP TABLE IF EXISTS before each table (default),
//    so re-importing replaces the tables;
//  - each line is cleaned while streaming (see $clean below).
// Three passes, in this order in the file (user's rule, 2026-10-01):
//  1. structure: every CREATE TABLE (+ routines) -- all tables exist before any data;
//  2. data: every INSERT;
//  3. triggers last -- created after the data so they don't fire on the
//     imported rows (kt_logs' BEFORE INSERT trigger would rewrite versions).
$base = [
    $mysqldump,
    '-h', (string) $conn['host'],
    '-P', (string) $conn['port'],
    '-u', (string) $conn['username'],
    '-p'.$conn['password'],
    '--single-transaction',
    '--skip-add-locks',
    '--default-character-set=utf8mb4',
];
$db = (string) $conn['database'];
$passes = [
    '1. TABLES (structure only)' => array_merge($base, ['--no-data', '--routines', '--skip-triggers', $db]),
    '2. DATA' => array_merge($base, ['--no-create-info', '--skip-triggers', $db]),
    '3. TRIGGERS' => array_merge($base, ['--no-create-info', '--no-data', '--triggers', $db]),
];

// Line clean-up for portability (structure lines only -- INSERT data rows
// are written untouched, so no text inside the data is ever altered):
//  - drop MariaDB 11's "/*M!999999\- enable the sandbox mode */" first line
//    (MySQL / older MariaDB clients choke on it);
//  - collations only MariaDB 11 (uca1400) or MySQL 8 (0900) know become
//    utf8mb4_unicode_ci, which every MySQL / MariaDB version supports
//    ("Unknown collation" otherwise);
//  - DEFINER=`user`@`host` removed from triggers / routines / views (needs
//    SUPER on shared hosting; without it they run as the importing user).
$clean = function (string $line): ?string {
    if (str_starts_with($line, '/*M!999999\\- enable the sandbox mode')) {
        return null;
    }
    if (str_starts_with($line, 'INSERT INTO')) {
        return $line;
    }
    $line = preg_replace('/\b(utf8mb[34])_(?:uca1400|0900)_[a-z_]+\b/', '$1_unicode_ci', $line);
    $line = preg_replace('#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\s*\*/\s*#', '', $line);
    $line = preg_replace('/\s*DEFINER\s*=\s*`[^`]*`@`[^`]*`/', '', $line);

    return $line;
};

$fh = fopen($outFile, 'w');
fwrite($fh, "-- Murano database export (Protocol Copia BD), phpMyAdmin-ready:\n"
    ."-- phpMyAdmin -> select the target database -> Import -> this file.\n"
    ."-- Order: 1) all CREATE TABLE  2) all data (INSERT)  3) triggers.\n"
    ."-- No CREATE DATABASE / USE, no LOCK TABLES, no DEFINER, only portable\n"
    ."-- collations (utf8mb4_unicode_ci). Existing tables are replaced.\n\n");
$result = null;
foreach ($passes as $title => $command) {
    fwrite($fh, "\n-- ============================================================\n-- {$title}\n-- ============================================================\n\n");
    $buffer = '';
    $result = Process::timeout(600)->run($command, function ($type, $chunk) use ($fh, $clean, &$buffer) {
        if ($type !== \Symfony\Component\Process\Process::OUT) {
            return;
        }
        // Chunks don't end on line breaks: clean complete lines only.
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = $clean(substr($buffer, 0, $pos + 1));
            $buffer = substr($buffer, $pos + 1);
            if ($line !== null) {
                fwrite($fh, $line);
            }
        }
    });
    if ($buffer !== '' && ($line = $clean($buffer)) !== null) {
        fwrite($fh, $line);
    }
    if (! $result->successful()) {
        break;
    }
}
fclose($fh);

if (! $result->successful()) {
    @unlink($outFile);
    echo "ERROR: mysqldump failed.\n".$result->errorOutput()."\n";
    return;
}

$sizeMb = round(filesize($outFile) / 1024 / 1024, 2);
echo "Backup written to: {$outFile} ({$sizeMb} MB)\n";
