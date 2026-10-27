<?php

/*
 * public/files -> Google Drive (user's rule, 2026-10-27): the companies' files left git,
 * so Pa Llevar keeps a copy of this PC's public/files in the backup archive's sibling
 * folder -- G:\My Drive\Bck_WEBSITE-LOCAL\KT-MURANO\Versions\files (App\Support\BackupPaths:
 * next to <archive>). New and newer files are copied; nothing is ever deleted there.
 *
 *   php artisan tinker --execute="require '<repo>\database\scripts\protocol_files_mirror.php';"
 *
 * Files travel without git: production -> PC with `murano:from-prod` (every morning),
 * PC -> production with protocol_prod_push.php (Pa Llevar). A deploy never deletes them.
 */

$archive = \App\Support\BackupPaths::archive();
if ($archive === \App\Support\BackupPaths::latest()) {
    echo "files mirror: Google Drive archive not found -- skipped.\n";
    return;
}
$dest = dirname($archive).DIRECTORY_SEPARATOR.'files';
if (! is_dir($dest)) {
    mkdir($dest, 0775, true);
}
$src = public_path('files');
// robocopy: /E sub-folders, /XO skip older, no /MIR (never deletes); exit codes below 8 = OK.
$cmd = 'robocopy '.escapeshellarg($src).' '.escapeshellarg($dest).' /E /XO /R:1 /W:1 /NP /NFL /NDL /NJH';
$out = [];
exec($cmd, $out, $code);
$summary = trim(implode("\n", array_slice(array_filter($out, fn ($l) => trim($l) !== ''), -6)));
echo $code < 8 ? "files mirror OK -> $dest\n$summary\n" : "files mirror FAILED (robocopy $code)\n$summary\n";
