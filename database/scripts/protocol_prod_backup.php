<?php

/*
 * Production database backup, taken before ANY write to production (code
 * deploy migrations, data push) -- shared by protocol_prod_migrate.php and
 * protocol_prod_push.php. Needs $prodEnv (from protocol_prod_conn.php) and
 * optionally $backupLabel (e.g. 'before_push').
 *
 * Writes KT-TOOLS/database/production/<db>_<date>_<time>[_<label>].zip
 * (git-ignored: production backups stay on the PC that made them), compressed
 * and phpMyAdmin-ready like every backup (protocol_sql_clean.php: no sandbox
 * line, no DEFINER, portable collations; --skip-add-locks: no LOCK TABLES).
 * The password goes through MYSQL_PWD, never on the command line.
 *
 * Returns the zip path, or null (with a message) when the backup failed --
 * the caller must then write NOTHING to production.
 */

$dir = base_path('KT-TOOLS/database/production');
if (! is_dir($dir)) mkdir($dir, 0775, true);
$label = isset($backupLabel) && preg_match('/^[a-z0-9_]+$/', (string) $backupLabel) ? '_'.$backupLabel : '';
$raw = "$dir/{$prodEnv['PROD_DB_DATABASE']}_".now()->format('Y-m-d_h-i-s-A')."{$label}.raw.sql";
$dump = env('MYSQLDUMP_PATH', 'mysqldump');
if ($dump === 'mysqldump' && is_file('C:/Program Files/MariaDB 11.8/bin/mysqldump.exe')) $dump = 'C:/Program Files/MariaDB 11.8/bin/mysqldump.exe';
$cmd = sprintf('"%s" --host=%s --port=%s --user=%s --single-transaction --skip-lock-tables --skip-add-locks --no-tablespaces --routines --triggers --default-character-set=utf8mb4 %s --result-file="%s"',
    $dump, $prodEnv['PROD_DB_HOST'], $prodEnv['PROD_DB_PORT'] ?? 3306, $prodEnv['PROD_DB_USERNAME'], $prodEnv['PROD_DB_DATABASE'], $raw);
putenv('MYSQL_PWD='.$prodEnv['PROD_DB_PASSWORD']);
$out = [];
exec($cmd.' 2>&1', $out, $code);
putenv('MYSQL_PWD');
if ($code !== 0 || ! is_file($raw) || filesize($raw) < 1000) {
    @unlink($raw);
    echo 'Production backup FAILED -- nothing will be written to production.', PHP_EOL, implode(PHP_EOL, $out), PHP_EOL;

    return null;
}

$sqlTools = require __DIR__.'/protocol_sql_clean.php';
$sqlFile = substr($raw, 0, -8).'.sql';
($sqlTools['file'])($raw, $sqlFile, "-- Murano PRODUCTION database backup ({$prodEnv['PROD_DB_DATABASE']}), ".now()->format('Y-m-d h:i:s A')
    .($label ? ' -- '.ltrim($label, '_') : '').", phpMyAdmin-ready.\n-- phpMyAdmin -> select the target database -> Import -> this file.\n\n");
@unlink($raw);
$zipPath = ($sqlTools['zip'])($sqlFile);
echo 'Production backup: ', $zipPath, ' (', round(filesize($zipPath) / 1048576, 1), ' MB)', PHP_EOL;

return $zipPath;
