<?php

/*
 * Protocol Pa Llevar -- step 1c, files: brings the files created in production
 * (public/files/company_{id}/{module}/...: uploads, email attachments, website
 * imports...) down to this PC, so Pa Llevar commits them and the next Hostinger
 * deploy (a git pull) carries them back -- production, dev and GitHub stay alike.
 * Production is only READ; nothing is ever deleted on either side.
 *
 *   php artisan tinker --execute="$apply = false; require '<repo>/database/scripts/protocol_prod_files.php';"   (dry run)
 *   php artisan tinker --execute="$apply = true;  require '<repo>/database/scripts/protocol_prod_files.php';"
 *
 * Access: .env.production-ssh (per machine, git-ignored): PROD_SSH_HOST / PORT /
 * USER, PROD_SSH_KEY (preferred, when the key is added in hPanel) or
 * PROD_SSH_PASSWORD, PROD_PATH (Laravel root on the server).
 *
 * Rules: a file only in production -> downloaded. In both with a different size
 * -> production's copy wins; dev's copy is first saved to
 * KT-TOOLS/database/production/files_replaced_<stamp>/ (git-ignored). Files only
 * in dev are kept and listed (they reach production with the next deploy).
 */

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

$apply = $apply ?? false;
$envFile = base_path('.env.production-ssh');
if (! is_file($envFile)) {
    echo "Missing .env.production-ssh -- production SSH access is set per machine (see CLAUDE.md).\n";
    return;
}
$ssh = Dotenv\Dotenv::parse(file_get_contents($envFile));
$root = rtrim($ssh['PROD_PATH'] ?? '', '/');
$sftp = new SFTP($ssh['PROD_SSH_HOST'], (int) ($ssh['PROD_SSH_PORT'] ?? 22), 60);
$logged = false;
$keyFile = str_replace('~', getenv('USERPROFILE') ?: getenv('HOME'), (string) ($ssh['PROD_SSH_KEY'] ?? ''));
if ($keyFile && is_file($keyFile)) {
    try { $logged = $sftp->login($ssh['PROD_SSH_USER'], PublicKeyLoader::load(file_get_contents($keyFile))); } catch (\Throwable $e) { $logged = false; }
}
if (! $logged && ! empty($ssh['PROD_SSH_PASSWORD'])) {
    $sftp = new SFTP($ssh['PROD_SSH_HOST'], (int) ($ssh['PROD_SSH_PORT'] ?? 22), 60);
    $logged = $sftp->login($ssh['PROD_SSH_USER'], $ssh['PROD_SSH_PASSWORD']);
}
if (! $logged) {
    echo "Could not sign in to the production server (key / password in .env.production-ssh).\n";
    return;
}

// production's list: path <TAB> size
$list = $sftp->exec('cd '.escapeshellarg($root.'/public').' && find files -type f -printf "%p\t%s\n"');
$prod = [];
foreach (preg_split('/\r?\n/', trim((string) $list)) as $line) {
    if (str_contains($line, "\t")) {
        [$p, $s] = explode("\t", $line, 2);
        $prod[$p] = (int) $s;
    }
}
if (! $prod) {
    echo "Production listed no files under public/files -- stopped (nothing done).\n";
    return;
}
$pub = public_path();
$new = []; $changed = []; $onlyDev = 0;
// Git on Windows checks text files out with CRLF, the server keeps LF: same
// file, different size. Equal once CRLF -> LF = not a change.
$sameText = function (string $local, int $prodSize): bool {
    if (filesize($local) > 20 * 1048576) return false;
    $c = (string) file_get_contents($local);

    return str_contains($c, "\r\n") && strlen(str_replace("\r\n", "\n", $c)) === $prodSize;
};
foreach ($prod as $p => $s) {
    $local = $pub.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p);
    if (! is_file($local)) $new[$p] = $s;
    elseif (filesize($local) !== $s && ! $sameText($local, $s)) $changed[$p] = $s;
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pub.DIRECTORY_SEPARATOR.'files', FilesystemIterator::SKIP_DOTS)) as $f) {
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($pub) + 1));
    if (! isset($prod[$rel])) $onlyDev++;
}
// A tiny production copy may be a Git LFS pointer (the deploy doesn't fetch LFS):
// never download it over the real file -- report it instead.
$lfsBroken = [];
foreach ($changed as $p => $s) {
    if ($s < 300 && str_starts_with((string) $sftp->get($root.'/public/'.$p), 'version https://git-lfs')) {
        $lfsBroken[] = $p;
        unset($changed[$p]);
    }
}
if ($lfsBroken) {
    echo 'BROKEN in production (Git LFS pointer instead of the file -- the next deploy fixes it now that public/ is out of LFS): ', implode(', ', $lfsBroken), PHP_EOL;
}
$mb = fn ($a) => round(array_sum($a) / 1048576, 1).' MB';
echo 'production files: ', count($prod), ' | new in production: ', count($new), ' (', $mb($new), ') | changed in production: ', count($changed), ' (', $mb($changed), ') | only in dev (kept): ', $onlyDev, PHP_EOL;
$byFolder = [];
foreach (array_merge(array_keys($new), array_keys($changed)) as $p) {
    $k = implode('/', array_slice(explode('/', $p), 1, 2));
    $byFolder[$k] = ($byFolder[$k] ?? 0) + 1;
}
arsort($byFolder);
foreach (array_slice($byFolder, 0, 15, true) as $k => $n) echo "  $k: $n", PHP_EOL;
foreach (array_slice($changed, 0, 20, true) as $p => $s) {
    echo "  changed: $p (production $s bytes, dev ", filesize($pub.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p)), ')', PHP_EOL;
}

if (! $apply || (! $new && ! $changed)) {
    if (! $apply && ($new || $changed)) echo 'Dry run -- nothing downloaded. Run again with $apply = true.', PHP_EOL;
    return;
}

$backupDir = base_path('KT-TOOLS/database/production/files_replaced_'.now()->format('Y-m-d_h-i-s-A'));
$done = 0; $failed = [];
foreach (array_merge($new, $changed) as $p => $s) {
    $local = $pub.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p);
    if (isset($changed[$p])) {
        $b = $backupDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p);
        @mkdir(dirname($b), 0775, true);
        copy($local, $b);
    }
    @mkdir(dirname($local), 0775, true);
    $tmp = $local.'.part';
    if ($sftp->get($root.'/public/'.$p, $tmp) !== false && filesize($tmp) === $s) {
        rename($tmp, $local);
        $done++;
    } else {
        @unlink($tmp);
        $failed[] = $p;
    }
}
echo "downloaded: $done", ($changed ? " (dev copies replaced saved in $backupDir)" : ''), PHP_EOL;
if ($failed) echo 'FAILED (', count($failed), '): ', implode(', ', array_slice($failed, 0, 20)), PHP_EOL;
