<?php

/*
 * KT-TOOLS -> Google Drive and back (user's rule, 2026-11-04): what a PC keeps in KT-TOOLS outside git (photos and material
 * for a company, like KT-TOOLS/turismo) must not depend on one PC. Both ways, by file: a file missing on
 * one side is copied, and when both have it the newest wins. Nothing is ever deleted: a file removed here
 * comes back from Drive; to really remove one, remove it in Drive too. Files in git are left to git.
 * Drive: <archive>\..\KT-TOOLS (same path on every PC). Wake-up, Pa Llevar and A Dormir run it.
 *
 *   php artisan tinker --execute="require '<repo>\database\scripts\protocol_tools_sync.php';"
 *
 * EVERYTHING outside git travels (user's rule, 2026-11-15, replacing 2026-11-04's exclusions: "la sincronización entre todas
 * las PCs debe ser con todo"): backups (database, SQL, VERSION), Temp, .env_modelo, reports with customer data included.
 * Only desktop.ini is left out. Git keeps what it tracks; GitHub never gets backups / .env (2026-10-27).
 * Since 2026-11-17 (user's rule) KT-TOOLS is not in git at all: this sync is the only way it reaches the other PCs.
 */

$archive = \App\Support\BackupPaths::archive();
if ($archive === \App\Support\BackupPaths::latest()) {
    echo "KT-TOOLS sync: Google Drive not found -- skipped, nothing copied.\n";

    return;
}
$drive = dirname($archive).DIRECTORY_SEPARATOR.'KT-TOOLS';
$local = base_path('KT-TOOLS');
if (! is_dir($drive)) {
    mkdir($drive, 0775, true);
}
$left = fn (string $rel) => str_ends_with(strtolower($rel), 'desktop.ini');

// Since 2026-11-17 the whole of KT-TOOLS is out of git (.gitignore /KT-TOOLS/): every file travels by Drive. A file
// git still tracks (a PC before that commit) is left to git; an empty list from git is normal now.
$git = trim((string) shell_exec('where git 2>NUL')) ? 'git' : (glob(getenv('LOCALAPPDATA').'/GitHubDesktop/app-*/resources/app/git/cmd/git.exe')[0] ?? 'git');
$tracked = array_filter(explode("\0", (string) shell_exec('"'.$git.'" -C '.escapeshellarg(base_path()).' -c core.quotepath=off ls-files -z -- KT-TOOLS 2>NUL')));
$tracked = array_flip(array_map(fn ($p) => substr($p, strlen('KT-TOOLS/')), $tracked));

$files = []; // relative path => true, from both sides
if (is_dir($local)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($local, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $files[str_replace('\\', '/', substr($f->getPathname(), strlen($local) + 1))] = true;
        }
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($drive, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile()) {
        $files[str_replace('\\', '/', substr($f->getPathname(), strlen($drive) + 1))] = true;
    }
}

$copy = function (string $from, string $to) {
    if (! is_dir(dirname($to))) {
        mkdir(dirname($to), 0775, true);
    }
    if (! copy($from, $to)) {
        return false;
    }
    touch($to, filemtime($from)); // the same date on both sides, or they would copy back and forth

    return true;
};
$up = $down = $same = $failed = 0;
foreach (array_keys($files) as $rel) {
    if ($left($rel) || isset($tracked[$rel])) {
        continue;
    }
    $a = $local.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $b = $drive.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ta = is_file($a) ? filemtime($a) : 0;
    $tb = is_file($b) ? filemtime($b) : 0;
    // Two seconds of tolerance: Drive and the PC don't keep dates with the same precision.
    if (abs($ta - $tb) <= 2 && $ta && $tb) {
        $same++;
    } elseif ($ta > $tb) {
        $copy($a, $b) ? $up++ : $failed++;
    } else {
        $copy($b, $a) ? $down++ : $failed++;
    }
}
echo "KT-TOOLS sync -> $drive\n  up to Drive: $up; down from Drive: $down; already the same: $same".($failed ? "; FAILED: $failed" : '')."\n";
