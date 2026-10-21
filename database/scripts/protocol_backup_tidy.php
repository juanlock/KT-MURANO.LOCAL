<?php

/*
 * Last step of every local backup (Copia BD / Wake-up / Pa Llevar; user's rule,
 * 2026-10-19), required by protocol_copia_bd.php with $written (the new zips)
 * and $host:
 *
 *  1. KT-TOOLS/database/ is tidied: a backup zip found in the root, or in another
 *     PC's folder, goes to its PC's folder -- the PC comes from the file name:
 *       old style  dbmurano_<date>_<PC>_<time>[_office_emails_N].zip
 *       root copy  dbmurano_<date>_<PC>_<time>_<tag>[_office_emails_N].zip
 *     A root copy whose original is already in the PC folder is just removed (it
 *     was a copy). Nothing else is touched (production/, Older/, other files).
 *  2. A copy of the backup just made is left in the root of KT-TOOLS/database/,
 *     named with the PC: dbmurano_<date>_<PC>_<time>_<tag>[...].zip -- so the
 *     latest backup is always at hand there (only that one: the previous root
 *     copy is removed in step 1 on the next run).
 */

$root = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'database');
$pcDir = fn (string $pc) => $root.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_-]/', '-', $pc);
// dbmurano_<date>_<PC>_<time>[_<tag>][_office_emails_N].zip  (the PC may contain "_")
$pattern = '/^dbmurano_(\d{4}-\d{2}-\d{2})_(.+)_(\d{2}-\d{2}-\d{2}-[AP]M)(?:_([a-z]+))?(_office_emails_\d)?\.zip$/';
$moved = 0; $removed = 0;
$place = function (string $file, string $pc, string $date, string $time, ?string $tag, ?string $part) use ($pcDir, &$moved, &$removed) {
    $dir = $pcDir($pc);
    // the original's standard name in its PC folder (tagged copies) / the old name kept (untagged)
    $standard = $tag ? "dbmurano_{$date}_{$time}_{$tag}".($part ?? '').'.zip' : basename($file);
    $target = $dir.DIRECTORY_SEPARATOR.$standard;
    if (is_file($target)) {
        if (filesize($target) === filesize($file)) { @unlink($file); $removed++; }   // a copy of a file already in place
        return;
    }
    if (! is_dir($dir)) mkdir($dir, 0777, true);
    if (@rename($file, $target)) $moved++;
};

// 1a. loose files in the root
foreach (glob($root.DIRECTORY_SEPARATOR.'dbmurano_*.zip') ?: [] as $f) {
    if (in_array($f, $written, true)) continue;
    if (preg_match($pattern, basename($f), $m)) $place($f, $m[2], $m[1], $m[3], $m[4] ?? null ?: null, $m[5] ?? null ?: null);
}
// 1b. old-style files sitting in the wrong PC folder (e.g. KTOPTIMUM_NB's in "KTOPTIMUM")
foreach (glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $d) {
    $folder = basename($d);
    if (in_array($folder, ['production', 'Older', 'HOSTINGER'], true)) continue;
    foreach (glob($d.DIRECTORY_SEPARATOR.'dbmurano_*.zip') ?: [] as $f) {
        if (preg_match($pattern, basename($f), $m) && preg_replace('/[^A-Za-z0-9_-]/', '-', $m[2]) !== $folder) {
            $place($f, $m[2], $m[1], $m[3], $m[4] ?? null ?: null, $m[5] ?? null ?: null);
        }
    }
    if (! (glob($d.DIRECTORY_SEPARATOR.'*') ?: [])) @rmdir($d);   // a folder left empty
}

// 2. the latest backup copied to the root, with the PC in its name
$copies = [];
foreach ($written as $f) {
    if (! preg_match('/^dbmurano_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2}-[AP]M)_(.+)$/', basename($f), $m)) continue;
    $copy = $root.DIRECTORY_SEPARATOR."dbmurano_{$m[1]}_{$host}_{$m[2]}_{$m[3]}";
    if (@copy($f, $copy)) $copies[] = basename($copy);
}
echo 'KT-TOOLS/database tidied: ', $moved, ' moved to their PC folder, ', $removed, ' old root copies removed',
    $copies ? ' | latest backup copied to KT-TOOLS/database/: '.$copies[0].(count($copies) > 1 ? ' (+'.(count($copies) - 1).')' : '') : '', PHP_EOL;
