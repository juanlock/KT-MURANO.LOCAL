<?php

/*
 * KT-TOOLS: what differs between this PC and the clouds (user's request, 2026-11-26) -- READ ONLY, nothing is copied or deleted.
 *
 * For each cloud (Google Drive, OneDrive; same folders as protocol_tools_sync.php) it lists:
 *   - only here         -> what the next push uploads
 *   - only in the cloud -> what the next push would DELETE there (bring it down first: $toolsMode = 'merge' in protocol_tools_sync.php)
 *   - different         -> newer here (goes up) / newer in the cloud (a push would overwrite it)
 * grouped by folder (2 levels, e.g. files/turismo), plus the cloud's last push (PC and time).
 *
 *   php artisan tinker --execute="require 'D:\WEBSITES-LOCAL\KT-MURANO.LOCAL\database\scripts\protocol_tools_compare.php';"
 *   $toolsCompareList = true;          -- also every file name (up to 200 per kind)
 *   $toolsComparePath = 'files/turismo'; -- only that folder
 */

$local = base_path('KT-TOOLS');
$clouds = [];
$archive = \App\Support\BackupPaths::archive();
if ($archive !== \App\Support\BackupPaths::latest()) {
    $clouds['Google Drive'] = dirname($archive).DIRECTORY_SEPARATOR.'KT-TOOLS';
}
$oneDrive = env('KT_TOOLS_ONEDRIVE') ?: env('ONEDRIVE_TOOLS_DIR') ?: ((getenv('OneDrive') ?: getenv('OneDriveConsumer')) ? rtrim(getenv('OneDrive') ?: getenv('OneDriveConsumer'), '\\/').'\A_WEBSITES-LOCAL\KT-MURANO.LOCAL\KT-TOOLS' : null);
if ($oneDrive && is_dir(dirname($oneDrive))) {
    $clouds['OneDrive'] = $oneDrive;
}
if (! $clouds) {
    echo "KT-TOOLS compare: neither Google Drive nor OneDrive is on this PC.\n";

    return;
}
$prefix = trim(str_replace('\\', '/', (string) ($toolsComparePath ?? '')), '/');
$list = ! empty($toolsCompareList);
$skip = function (string $rel) {
    $b = strtolower(basename($rel));

    return $rel === '_sync.json' || $b === 'desktop.ini' || $b === 'thumbs.db' || str_starts_with($b, '~$') || str_starts_with($b, '.~lock') || str_ends_with($b, '.tmp');
};
$scan = function (string $root) use ($skip, $prefix) {
    $files = [];
    $start = $prefix !== '' ? $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $prefix) : $root;
    if (! is_dir($start)) {
        return $files;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($f->isFile() && ! $skip($rel)) {
            $files[mb_strtolower($rel)] = [$f->getSize(), $f->getMTime(), $rel];
        }
    }

    return $files;
};
$group = fn ($rel) => implode('/', array_slice(explode('/', $rel), 0, min(2, max(1, substr_count($rel, '/')))));
$mb = fn ($b) => number_format($b / 1048576, 1).' MB';

$lf = $scan($local);
echo 'KT-TOOLS of this PC ('.gethostname().')'.($prefix ? " [$prefix]" : '').': '.count($lf)." files\n";
foreach ($clouds as $name => $cloud) {
    $r = is_file("$cloud/_sync.json") ? json_decode((string) file_get_contents("$cloud/_sync.json"), true) : null;
    echo "\n== $name -- last push: ".($r ? $r['pc'].', '.date('Y-m-d H:i', $r['at']) : 'none')."\n";
    $cf = $scan($cloud);
    $kinds = ['only here (goes up)' => [], 'only in the cloud (a push deletes it)' => [], 'newer here' => [], 'newer in the cloud' => []];
    foreach ($lf as $k => $v) {
        $c = $cf[$k] ?? null;
        if (! $c) {
            $kinds['only here (goes up)'][] = $v;
        } elseif ($c[0] !== $v[0] || abs($c[1] - $v[1]) > 2) {
            $kinds[$v[1] >= $c[1] ? 'newer here' : 'newer in the cloud'][] = $v[1] >= $c[1] ? $v : $c;
        }
    }
    foreach (array_diff_key($cf, $lf) as $c) {
        $kinds['only in the cloud (a push deletes it)'][] = $c;
    }
    foreach ($kinds as $kind => $rows) {
        if (! $rows) {
            echo "  $kind: 0\n";
            continue;
        }
        echo "  $kind: ".count($rows).' ('.$mb(array_sum(array_column($rows, 0))).")\n";
        $by = [];
        foreach ($rows as $row) {
            $g = $group($row[2]);
            $by[$g] = ($by[$g] ?? 0) + 1;
        }
        arsort($by);
        foreach (array_slice($by, 0, 12, true) as $g => $n) {
            echo "      $n  $g\n";
        }
        if ($list) {
            foreach (array_slice($rows, 0, 200) as $row) {
                echo '        - '.$row[2].'  ('.date('Y-m-d H:i', $row[1]).")\n";
            }
        }
    }
}
