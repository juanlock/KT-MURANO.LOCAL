<?php

/*
 * Pending production backups -> Google Drive (user's rule, 2026-11-18: a backup step that takes long is left pending and
 * done by the next Pa Llevar / A Dormir). protocol_prod_backup.php makes the backup ON the server before any write
 * (murano_deploy/backups_pending/*.sql.gz) and lists it in storage/app/prod_backups_pending.json; this script downloads
 * each one, makes it phpMyAdmin-ready and zips it into <archive>/production/ like every backup, then removes the
 * server copy. A failed one stays in the list for the next run.
 *
 *   php artisan tinker --execute="require '<repo>\database\scripts\protocol_prod_backup_pending.php';"
 */

$list = storage_path('app/prod_backups_pending.json');
$pending = is_file($list) ? (json_decode((string) file_get_contents($list), true) ?: []) : [];
if (! $pending) {
    echo "Pending production backups: none.\n";

    return;
}
$sftp = \App\Services\ProdDump::sftp();
if (! $sftp) {
    echo 'Pending production backups: ', count($pending), " -- no SSH to production, left for the next run.\n";

    return;
}
$sftp->setTimeout(1800);
$dir = \App\Support\BackupPaths::production();
$sqlTools = require __DIR__.'/protocol_sql_clean.php';
$left = [];
foreach ($pending as $p) {
    $base = basename($p['remote'], '.sql.gz');
    $gz = "$dir/$base.sql.gz";
    $raw = "$dir/$base.raw.sql";
    $sql = "$dir/$base.sql";
    if ($sftp->get($p['remote'], $gz) === false) {
        @unlink($gz);
        echo "  $base: download failed, left pending\n";
        $left[] = $p;
        continue;
    }
    $in = gzopen($gz, 'rb');
    $out = fopen($raw, 'wb');
    while (! gzeof($in)) {
        fwrite($out, gzread($in, 1048576));
    }
    gzclose($in);
    fclose($out);
    @unlink($gz);
    ($sqlTools['file'])($raw, $sql, "-- Murano PRODUCTION database backup ({$p['db']}), ".\Carbon\Carbon::parse($p['at'])->format('Y-m-d h:i:s A')
        .($p['label'] ? ' -- '.$p['label'] : '').", phpMyAdmin-ready.\n-- phpMyAdmin -> select the target database -> Import -> this file.\n\n");
    @unlink($raw);
    $zip = ($sqlTools['zip'])($sql);
    if (! $zip || ! is_file($zip)) {
        echo "  $base: zip failed, left pending\n";
        $left[] = $p;
        continue;
    }
    $sftp->delete($p['remote']);
    echo "  $base -> ", $zip, ' (', round(filesize($zip) / 1048576, 1), " MB)\n";
}
file_put_contents($list, json_encode($left, JSON_PRETTY_PRINT));
echo 'Pending production backups: ', count($pending) - count($left), ' brought to Drive, ', count($left), " still pending.\n";
