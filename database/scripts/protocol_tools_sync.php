<?php

/*
 * KT-TOOLS <-> the clouds (user's rules, 2026-11-04 / 2026-11-15 / 2026-11-18). KT-TOOLS is not in git: it reaches the other
 * PCs only through two cloud copies, Google Drive and OneDrive.
 *
 *   $toolsMode = 'up'    Pa Llevar / A Dormir, their LAST step: this PC's KT-TOOLS is copied to BOTH clouds (a file
 *                        missing there, or older there, is copied). NOTHING is ever deleted in a cloud.
 *   $toolsMode = 'down'  Wake-up: both clouds are read and compared; for each file the newest copy among them comes down
 *                        to this PC when this PC lacks it or has it older. Nothing is written to the clouds, nothing is
 *                        deleted on the PC.
 *   (not set)            'down' then 'up' (a manual run).
 *
 *   php artisan tinker --execute="$toolsMode = 'up'; require '<repo>\database\scripts\protocol_tools_sync.php';"
 *
 * Why (user, 2026-11-18): a Wake-up on another PC emptied Google Drive's KT-TOOLS. Since then no step deletes anything in a
 * cloud, and a Wake-up never writes to a cloud.
 *
 * Clouds: Google Drive = <archive>\..\KT-TOOLS (same path on every PC); OneDrive = .env ONEDRIVE_TOOLS_DIR, else the PC's
 * known path ($onedriveByPc). A cloud that isn't on this PC is skipped and said so.
 * Company material lives in KT-TOOLS\files\<company> (user, 2026-11-18): a file a cloud still holds under an old root folder
 * (turismo, ARCE, kcoltech) is read as files\<name>\...; an old root folder on THIS PC is merged into files\ and removed
 * (on the PC only). Only desktop.ini is left out; a file git still tracks is left to git.
 */

$mode = $toolsMode ?? 'both';
unset($toolsMode);
$local = base_path('KT-TOOLS');

$onedriveByPc = ['KT-THUNDERA' => 'D:\\NetLock\\OneDrive\\A_WEBSITES-LOCAL\\KT-MURANO.LOCAL\\KT-TOOLS'];
$clouds = [];
$archive = \App\Support\BackupPaths::archive();
if ($archive !== \App\Support\BackupPaths::latest()) {
    $clouds['Google Drive'] = dirname($archive).DIRECTORY_SEPARATOR.'KT-TOOLS';
}
$one = env('ONEDRIVE_TOOLS_DIR') ?: ($onedriveByPc[strtoupper(gethostname())] ?? null);
if ($one && is_dir(dirname($one))) {
    $clouds['OneDrive'] = $one;
}
if (! $clouds) {
    echo "KT-TOOLS sync: no Google Drive or OneDrive on this PC -- skipped, nothing copied.\n";

    return;
}
foreach (['Google Drive', 'OneDrive'] as $name) {
    if (! isset($clouds[$name])) {
        echo "KT-TOOLS sync: $name not found on this PC -- skipped (OneDrive: set ONEDRIVE_TOOLS_DIR in .env).\n";
    }
}

$left = fn (string $rel) => str_ends_with(strtolower($rel), 'desktop.ini');
$moved = ['turismo' => 'files/turismo', 'ARCE' => 'files/ARCE', 'kcoltech' => 'files/kcoltech'];
// A path under an old root folder is the same file under files\<name>.
$remap = function (string $rel) use ($moved) {
    foreach ($moved as $old => $new) {
        if (strcasecmp(substr($rel, 0, strlen($old) + 1), $old.'/') === 0) {
            return $new.substr($rel, strlen($old));
        }
    }

    return $rel;
};
$path = fn (string $root, string $rel) => $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
$copy = function (string $from, string $to) {
    if (! is_dir(dirname($to)) && ! @mkdir(dirname($to), 0775, true) && ! is_dir(dirname($to))) {
        return false;
    }
    if (! @copy($from, $to)) {
        return false;
    }
    touch($to, filemtime($from)); // the same date on both sides, or they would copy back and forth

    return true;
};
// rel path => absolute path, for one side ($rel remapped for the clouds).
$scan = function (string $root, bool $isCloud) use ($remap) {
    $out = [];
    if (! is_dir($root)) {
        return $out;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $key = $isCloud ? $remap($rel) : $rel;
            // When a cloud holds both the old and the new path, the newest of the two counts.
            if (! isset($out[$key]) || filemtime($out[$key]) < $f->getMTime()) {
                $out[$key] = $f->getPathname();
            }
        }
    }

    return $out;
};

// Old root folders on THIS PC: merged into files\ (newer wins), then removed from the PC (never from a cloud).
foreach ($moved as $old => $new) {
    $from = $local.DIRECTORY_SEPARATOR.$old;
    if (! is_dir($from)) {
        continue;
    }
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $to = $path($local, $new).substr($f->getPathname(), strlen($from));
            if ((! is_file($to) || filemtime($to) < $f->getMTime()) && $copy($f->getPathname(), $to)) {
                $n++;
            }
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($from);
    echo "KT-TOOLS: old root folder $old merged into $new on this PC ($n file(s) copied) and removed here\n";
}

$git = trim((string) shell_exec('where git 2>NUL')) ? 'git' : (glob(getenv('LOCALAPPDATA').'/GitHubDesktop/app-*/resources/app/git/cmd/git.exe')[0] ?? 'git');
$tracked = array_filter(explode("\0", (string) shell_exec('"'.$git.'" -C '.escapeshellarg(base_path()).' -c core.quotepath=off ls-files -z -- KT-TOOLS 2>NUL')));
$tracked = array_flip(array_map(fn ($p) => substr($p, strlen('KT-TOOLS/')), $tracked));
$skip = fn (string $rel) => $left($rel) || isset($tracked[$rel]);

// ---- down: the newest copy among the clouds comes to this PC.
if ($mode === 'down' || $mode === 'both') {
    $mine = $scan($local, false);
    $best = []; // rel => [abs, cloud]
    foreach ($clouds as $name => $root) {
        foreach ($scan($root, true) as $rel => $abs) {
            if (! isset($best[$rel]) || filemtime($best[$rel][0]) < filemtime($abs) - 2) {
                $best[$rel] = [$abs, $name];
            }
        }
    }
    $down = $same = $failed = 0;
    $from = [];
    foreach ($best as $rel => [$abs, $name]) {
        if ($skip($rel)) {
            continue;
        }
        $t = isset($mine[$rel]) ? filemtime($mine[$rel]) : 0;
        if ($t && $t >= filemtime($abs) - 2) {
            $same++;
            continue;
        }
        if ($copy($abs, $path($local, $rel))) {
            $down++;
            $from[$name] = ($from[$name] ?? 0) + 1;
        } else {
            $failed++;
        }
    }
    $by = $from ? ' ('.implode(', ', array_map(fn ($k, $v) => "$v from $k", array_keys($from), $from)).')' : '';
    echo "KT-TOOLS down <- ".implode(' + ', array_keys($clouds)).": $down copied to this PC$by; already current: $same".($failed ? "; FAILED: $failed" : '')."\n";
}

// ---- up: this PC's current KT-TOOLS goes to every cloud. Nothing deleted there.
if ($mode === 'up' || $mode === 'both') {
    $mine = $scan($local, false);
    foreach ($clouds as $name => $root) {
        if (! is_dir($root) && ! @mkdir($root, 0775, true)) {
            echo "KT-TOOLS up -> $name: folder can't be created -- skipped\n";
            continue;
        }
        $theirs = $scan($root, false); // exact paths: up writes the new paths only
        $up = $same = $failed = 0;
        foreach ($mine as $rel => $abs) {
            if ($skip($rel)) {
                continue;
            }
            $t = isset($theirs[$rel]) ? filemtime($theirs[$rel]) : 0;
            if ($t && $t >= filemtime($abs) - 2) {
                $same++;
                continue;
            }
            $copy($abs, $path($root, $rel)) ? $up++ : $failed++;
        }
        echo "KT-TOOLS up -> $name ($root): $up copied; already current: $same".($failed ? "; FAILED: $failed" : '')."\n";
    }
}
