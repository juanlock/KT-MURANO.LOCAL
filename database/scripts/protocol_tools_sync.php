<?php

/*
 * KT-TOOLS <-> the clouds (user's rules, 2026-11-18, replacing the two-way "newest wins" merge):
 *
 *   $toolsMode = 'push'  -- the LAST step of Pa Llevar and A Dormir, the ONLY time the clouds change: each cloud copy becomes
 *                           EXACTLY this PC's D:\...\KT-MURANO.LOCAL\KT-TOOLS (new / changed files go up, files and folders
 *                           deleted here are deleted there), then a receipt _sync.json {pc, at, files} is written in it.
 *   $toolsMode = 'pull'  -- Wake-up: READS ONLY, never writes or deletes in a cloud. It compares both clouds, takes the one with
 *                           the newest complete push (Google Drive for Desktop / OneDrive may still be downloading another PC's
 *                           push: it waits up to ~10 minutes, then falls back to the other cloud, else changes nothing) and
 *                           makes this PC's KT-TOOLS equal to it. A file created / changed here after this PC's last sync is
 *                           kept and reported, never lost (it goes up with the next push).
 *
 * The clouds (each skipped with a note when its folder isn't on this PC):
 *   - Google Drive: <archive>\..\KT-TOOLS = G:\My Drive\Bck_WEBSITE-LOCAL\KT-MURANO\Versions\KT-TOOLS (.env BACKUP_ARCHIVE_DIR)
 *   - OneDrive:     %OneDrive%\A_WEBSITES-LOCAL\KT-MURANO.LOCAL\KT-TOOLS = D:\NetLock\OneDrive\... (.env KT_TOOLS_ONEDRIVE)
 *
 *   php artisan tinker --execute="$toolsMode = 'pull'; require '<repo>\database\scripts\protocol_tools_sync.php';"
 *
 * Everything in KT-TOOLS travels (backups, Temp, .env_modelo, reports) except desktop.ini; KT-TOOLS is not in git
 * (2026-11-17). Company material lives in KT-TOOLS\files\<company>\.
 */

$mode = ($toolsMode ?? null) === 'push' ? 'push' : (($toolsMode ?? null) === 'pull' ? 'pull' : null);
if (! $mode) {
    echo "KT-TOOLS sync: say \$toolsMode = 'push' (Pa Llevar / A Dormir) or 'pull' (Wake-up) -- nothing done.\n";

    return;
}
$local = base_path('KT-TOOLS');
if (! is_dir($local)) {
    mkdir($local, 0775, true);
}

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
    echo "KT-TOOLS sync: neither Google Drive nor OneDrive is on this PC -- skipped, nothing copied.\n";

    return;
}

$RECEIPT = '_sync.json';
$stateFile = storage_path('app/kt_tools_sync.json');
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$skip = fn (string $rel) => $rel === $RECEIPT || str_ends_with(strtolower($rel), 'desktop.ini');
$abs = fn (string $root, string $rel) => $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
$same = fn ($a, $b) => $a && $b && $a[0] === $b[0] && abs($a[1] - $b[1]) <= 2; // clouds keep dates with less precision

/** [files: rel => [size, mtime], dirs: [rel...]] */
$scan = function (string $root) use ($skip) {
    $files = [];
    $dirs = [];
    if (! is_dir($root)) {
        return [$files, $dirs];
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($skip($rel)) {
            continue;
        }
        $f->isDir() ? $dirs[] = $rel : ($f->isFile() ? $files[$rel] = [$f->getSize(), $f->getMTime()] : null);
    }

    return [$files, $dirs];
};
$copy = function (string $from, string $to) {
    if (! is_dir(dirname($to))) {
        @mkdir(dirname($to), 0775, true);
    }
    if (! @copy($from, $to)) {
        return false;
    }
    @touch($to, filemtime($from));

    return true;
};
/** Empty folders under $root not in $keep, deepest first. */
$prune = function (string $root, array $keep) {
    $n = 0;
    $keep = array_flip($keep);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($f->isDir() && ! isset($keep[$rel]) && @rmdir($f->getPathname())) {
            $n++;
        }
    }

    return $n;
};
$receipt = function (string $cloud) use ($RECEIPT) {
    $f = $cloud.DIRECTORY_SEPARATOR.$RECEIPT;

    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: null) : null;
};

// Company folders moved into KT-TOOLS\files (user's rule, 2026-11-18): an old root folder on THIS PC is merged into
// files\<name> and removed here (never in a cloud; the next push mirrors it).
foreach (['turismo' => 'files/turismo', 'ARCE' => 'files/ARCE', 'kcoltech' => 'files/kcoltech'] as $old => $new) {
    $from = $local.DIRECTORY_SEPARATOR.$old;
    if (! is_dir($from)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $to = $abs($local, $new).substr($f->getPathname(), strlen($from));
            if (! is_file($to) || filemtime($to) < $f->getMTime()) {
                $copy($f->getPathname(), $to);
            }
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($from);
    echo "KT-TOOLS: old root folder $old moved into $new on this PC\n";
}

// ------------------------------------------------------------------ push: each cloud = this PC
if ($mode === 'push') {
    [$lf, $ld] = $scan($local);
    $at = time();
    foreach ($clouds as $name => $cloud) {
        if (! is_dir($cloud) && ! @mkdir($cloud, 0775, true)) {
            echo "KT-TOOLS push -> $name: FAILED, its folder can't be created ($cloud)\n";
            continue;
        }
        [$cf] = $scan($cloud);
        $up = $del = $failed = 0;
        foreach ($lf as $rel => $v) {
            if (! $same($v, $cf[$rel] ?? null)) {
                $copy($abs($local, $rel), $abs($cloud, $rel)) ? $up++ : $failed++;
            }
        }
        foreach (array_diff_key($cf, $lf) as $rel => $v) {
            @unlink($abs($cloud, $rel)) && $del++;
        }
        foreach ($ld as $d) {
            @mkdir($abs($cloud, $d), 0775, true);
        }
        $pruned = $prune($cloud, $ld);
        if ($failed) {
            echo "KT-TOOLS push -> $name: up $up, deleted $del, FAILED $failed -- no receipt written (the next Wake-up won't trust it)\n";
            continue;
        }
        file_put_contents($cloud.DIRECTORY_SEPARATOR.$RECEIPT, json_encode(['pc' => gethostname(), 'at' => $at, 'files' => $lf]));
        echo "KT-TOOLS push -> $name = this PC (".count($lf)." files): up $up, deleted there $del file(s), $pruned empty folder(s)\n";
    }
    @mkdir(dirname($stateFile), 0775, true);
    file_put_contents($stateFile, json_encode(['pc' => gethostname(), 'at' => $at, 'synced_at' => time()]));

    return;
}

// ------------------------------------------------------------------ pull: read only; this PC = the newest complete push
$candidates = [];
foreach ($clouds as $name => $cloud) {
    $r = $receipt($cloud);
    if ($r && ! empty($r['files'])) {
        $candidates[] = ['name' => $name, 'dir' => $cloud, 'r' => $r];
    } else {
        echo "  $name: no push receipt yet".(is_dir($cloud) ? '' : ' (folder not found)')."\n";
    }
}
usort($candidates, fn ($a, $b) => $b['r']['at'] <=> $a['r']['at']);
if (! $candidates) {
    echo "KT-TOOLS pull: no cloud has a push receipt yet -- nothing changed on this PC (the next Pa Llevar / A Dormir writes one).\n";

    return;
}
$top = $candidates[0];
if (($state['at'] ?? null) === $top['r']['at'] && ($state['pc'] ?? null) === $top['r']['pc']) {
    echo "KT-TOOLS pull: nothing new -- this PC already has the last push ({$top['r']['pc']}, ".date('Y-m-d H:i', $top['r']['at']).").\n";

    return;
}
$src = null;
foreach ($candidates as $c) {
    $missing = [];
    for ($try = 0; $try < ($c === $top ? 20 : 2); $try++) {
        [$cf] = $scan($c['dir']);
        $missing = array_keys(array_filter($c['r']['files'], fn ($v, $rel) => ! $same($cf[$rel] ?? null, $v), ARRAY_FILTER_USE_BOTH));
        if (! $missing) {
            break;
        }
        echo "  {$c['name']}: still downloading {$c['r']['pc']}'s push (".count($missing)." file(s) missing) -- waiting 30 s...\n";
        sleep(30);
    }
    if (! $missing) {
        $src = $c;
        break;
    }
    echo "  {$c['name']}: incomplete (e.g. {$missing[0]}) -- not used\n";
}
if (! $src) {
    echo "KT-TOOLS pull: STOPPED -- no cloud holds a complete push yet. Nothing was changed on this PC; run it again later.\n";

    return;
}
[$lf] = $scan($local);
$since = (int) ($state['synced_at'] ?? 0);
$down = $deleted = 0;
$kept = [];
foreach ($src['r']['files'] as $rel => $v) {
    $mine = $lf[$rel] ?? null;
    if ($same($mine, $v)) {
        continue;
    }
    if ($mine && (! $since || $mine[1] > $since) && $mine[1] > $v[1]) {
        $kept[] = $rel; // changed here after the last sync and newer than the push
        continue;
    }
    $copy($abs($src['dir'], $rel), $abs($local, $rel)) && $down++;
}
foreach (array_diff_key($lf, $src['r']['files']) as $rel => $v) {
    if (! $since || $v[1] > $since) {
        $kept[] = $rel; // created here after the last sync (or this PC's first pull): never lost
        continue;
    }
    @unlink($abs($local, $rel)) && $deleted++;
}
$dirs = array_unique(array_map(fn ($r) => str_contains($r, '/') ? substr($r, 0, strrpos($r, '/')) : '', array_keys($src['r']['files'])));
$keepDirs = [];
foreach ($dirs as $d) {
    for (; $d !== ''; $d = str_contains($d, '/') ? substr($d, 0, strrpos($d, '/')) : '') {
        $keepDirs[$d] = true;
    }
}
$pruned = $prune($local, array_keys($keepDirs));
@mkdir(dirname($stateFile), 0775, true);
file_put_contents($stateFile, json_encode(['pc' => $src['r']['pc'], 'at' => $src['r']['at'], 'synced_at' => time()]));
echo "KT-TOOLS pull <- {$src['name']} ({$src['r']['pc']}'s push of ".date('Y-m-d H:i', $src['r']['at'])."): down $down; deleted here $deleted file(s), $pruned empty folder(s)".
    ($kept ? '; kept '.count($kept).' local change(s) not in that push (they go up with the next push), e.g. '.$kept[0] : '')."\n";
