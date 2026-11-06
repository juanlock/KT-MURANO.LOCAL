<?php

/*
 * Wake-up / Pa Llevar, "update production" -- deploys THIS PC's committed code to production over
 * SSH, in place: ONE copy of the site (public_html), no node_modules, no
 * Hostinger "hbuilds" copies (the Hostinger Git deploy kept the project 4-5
 * times and ran the account out of files, 2026-10-18).
 *
 *   $apply = false  -> dry run: commits / files to deploy, composer, pending
 *                      migrations, server-side local changes, conflicts
 *   $apply = true   -> 1) git bundle of the new commits -> SFTP -> server
 *                      fast-forwards public_html to this PC's HEAD
 *                      2) public/js (built here: run `npm run build` first)
 *                      uploaded as a zip and swapped in (previous kept as
 *                      public/js.prev)
 *                      3) composer install when composer.lock changed
 *                      4) migrations with a production DB backup first
 *                      (protocol_prod_migrate.php)
 *                      5) optimize:clear, PHP workers restarted, site checked
 *   $rollback = true -> back to the commit before the last deploy + public/js.prev
 *
 *   php artisan tinker --execute="$apply = false; require '<repo>/database/scripts/protocol_prod_deploy.php';"
 *
 * Production's .env is never replaced: it is copied aside before the code moves
 * and put back right after (checked by md5) -- .env is still tracked in git
 * with dev's values. The site shows Laravel's maintenance page (artisan down)
 * while files change. The server needs no GitHub access (the commits travel
 * in the bundle).
 * Access: .env.production-ssh (+ .env.production-db for the migrations).
 */

use phpseclib3\Net\SFTP;

$apply = $apply ?? false;
$rollback = $rollback ?? false;
$envFile = base_path('.env.production-ssh');
if (! is_file($envFile)) { echo "Missing .env.production-ssh (per machine).\n"; return; }
$cfg = Dotenv\Dotenv::parse(file_get_contents($envFile));
$root = rtrim($cfg['PROD_PATH'], '/');
$work = '/home/'.$cfg['PROD_SSH_USER'].'/murano_deploy';      // outside the site
$sftp = new SFTP($cfg['PROD_SSH_HOST'], (int) ($cfg['PROD_SSH_PORT'] ?? 22), 60);
$sftp->setTimeout(1200);
if (! $sftp->login($cfg['PROD_SSH_USER'], $cfg['PROD_SSH_PASSWORD'] ?? '')) { echo "SSH login failed.\n"; return; }
// $q = quoting for the SERVER's bash (escapeshellarg follows this PC's OS --
// on Windows it uses double quotes and drops characters, which broke bash).
$q = fn (string $s) => "'".str_replace("'", "'\\''", $s)."'";
$sh = fn (string $cmd) => trim((string) $sftp->exec('cd '.$q($root).' && '.$cmd.' 2>&1'));

// local git (on PATH, or GitHub Desktop's bundled git); local quoting = escapeshellarg
$git = trim((string) shell_exec('where git 2>NUL')) ? 'git' : (glob(getenv('LOCALAPPDATA').'/GitHubDesktop/app-*/resources/app/git/cmd/git.exe')[0] ?? 'git');
$lg = fn (string $args) => trim((string) shell_exec('"'.$git.'" -C '.escapeshellarg(base_path()).' '.$args.' 2>&1'));

$sftp->exec('mkdir -p '.$q($work));

if ($rollback) {
    $state = json_decode((string) $sftp->get($work.'/last_deploy.json'), true);
    if (! $state) { echo "No previous deploy recorded.\n"; return; }
    echo $sh('git reset --hard '.$q($state['from'])), PHP_EOL;
    echo $sh('test -d public/js.prev && rm -rf public/js.broken && mv public/js public/js.broken && mv public/js.prev public/js && echo "public/js restored"'), PHP_EOL;
    echo $sh('php artisan optimize:clear | tail -1; pkill -u '.$q($cfg['PROD_SSH_USER']).' lsphp; sleep 2; curl -s -o /dev/null -w "site: %{http_code}" https://murano.kcoltech.com/login'), PHP_EOL;
    echo "Rolled back code to {$state['from']} (the database is NOT rolled back -- its backup: {$state['db_backup']}).\n";
    return;
}

$serverHead = $sh('git rev-parse HEAD');
$localHead = $lg('rev-parse HEAD');
if (! preg_match('/^[0-9a-f]{40}$/', $serverHead)) { echo "Server public_html is not a git checkout: $serverHead\n"; return; }
// History rewritten on 2026-10-27 (public/files removed from every commit): production may sit on
// an old commit; KT-TOOLS/history_rewrite_map.txt ("old new" per line) gives its new twin, and the
// apply re-points the server's branch to it (git reset --mixed: the disk is not touched).
$rebasedFrom = null;
$onOurHistory = $lg('cat-file -t '.$serverHead) === 'commit' && $lg('merge-base '.$serverHead.' HEAD') === $serverHead;
if (! $onOurHistory && is_file(base_path('KT-TOOLS/history_rewrite_map.txt'))) {
    foreach (file(base_path('KT-TOOLS/history_rewrite_map.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        [$old, $new] = array_pad(preg_split('/\s+/', trim($l)), 2, null);
        if ($old === $serverHead && $new && $lg('cat-file -t '.$new) === 'commit') {
            $rebasedFrom = $serverHead;
            $serverHead = $new;
            echo 'production is on the old history (', substr($rebasedFrom, 0, 9), ') = ', substr($new, 0, 9), " in the rewritten one -- its branch is re-pointed on apply\n";
            break;
        }
    }
}
if ($lg('cat-file -t '.$serverHead) !== 'commit') { echo "Production is at $serverHead, which this PC doesn't have -- pull first.\n"; return; }
if ($serverHead === $localHead) { echo "Production already has this PC's HEAD ($localHead) -- no code to deploy.\n"; }
if ($lg('merge-base '.$serverHead.' HEAD') !== $serverHead) {
    echo "Production's commit $serverHead is not behind this PC's HEAD -- refusing (would lose its commits).\n"; return;
}
$commits = $serverHead === $localHead ? '' : $lg('log --oneline '.$serverHead.'..HEAD');
$changed = $serverHead === $localHead ? [] : array_filter(explode("\n", $lg('diff --name-only '.$serverHead.' HEAD')));
echo 'production: ', substr($serverHead, 0, 9), ' -> this PC: ', substr($localHead, 0, 9), ' (', count(array_filter(explode("\n", $commits))), ' commits, ', count($changed), " files)\n";
if ($commits) echo '  ', str_replace("\n", "\n  ", $commits), PHP_EOL;
$envInCommits = in_array('.env', $changed, true);
if ($envInCommits) echo "note: these commits change .env (dev's) -- production's .env is backed up and put back unchanged.\n";
$composer = in_array('composer.lock', $changed, true);
echo 'composer install: ', $composer ? 'yes (composer.lock changed)' : 'no', PHP_EOL;
$dirty = trim($lg('status --porcelain -- resources/src resources/css resources/js'));
if ($dirty) echo "WARNING: uncommitted changes in resources/ -- public/js built from them would not match the commit.\n";
$jsAge = is_dir(public_path('js')) ? filemtime(public_path('js')) : 0;
echo 'public/js built here: ', $jsAge ? date('Y-m-d H:i', $jsAge) : 'MISSING -- run npm run build', PHP_EOL;

// server-side local changes that the incoming commits would overwrite
$conflicts = []; $sameAsIncoming = [];
// Files the new commits stop tracking under public/files (2026-10-27: public/files left git):
// production keeps ITS copy as it is -- uploaded, edited or deleted there -- so they are
// never a conflict; they are taken out of the server's index before the fast-forward.
$gone = $serverHead === $localHead ? [] : array_values(array_filter(explode("\n", $lg('diff --name-only --diff-filter=D '.$serverHead.' HEAD -- public/files'))));
$goneSet = array_flip($gone);
foreach (array_filter(explode("\n", $sh('git status --porcelain --untracked-files=all'))) as $line) {
    $path = trim(substr($line, 3));
    if ($path === '.env' || ! in_array($path, $changed, true) || isset($goneSet[$path])) continue;
    $incoming = $lg('rev-parse HEAD:'.escapeshellarg($path));
    $serverBlob = $sh('git hash-object '.$q($path));
    // a copy uploaded from Windows may carry CRLF: same file once CR is dropped
    $serverLf = $sh('tr -d '.$q("\r").' < '.$q($path).' | git hash-object --stdin');
    if ($incoming === $serverBlob || $incoming === $serverLf) $sameAsIncoming[] = $path; else $conflicts[] = $path;
}
echo 'server files already equal to the new version: ', count($sameAsIncoming), ' | real conflicts: ', count($conflicts), PHP_EOL;
if ($conflicts) { echo '  ', implode("\n  ", array_slice($conflicts, 0, 20)), "\nREFUSED: production has its own changes in those files.\n"; return; }

$deployApply = $apply;
$apply = false;                                  // the migrate script reads $apply: list only here
require __DIR__.'/protocol_prod_migrate.php';
$apply = $deployApply;

if (! $apply) { echo "\nDry run -- nothing changed. Run again with \$apply = true.\n"; return; }
if (! $jsAge) { echo "public/js missing -- run npm run build first.\n"; return; }

// Maintenance page while files change; production's .env saved first. Whatever
// happens below (an error, a refused step), the finally block puts the .env
// back and takes the site out of maintenance.
$envMd5 = $sh('md5sum .env | cut -c1-32');
$sh('cp -p .env '.$q($work.'/env.production'));
if ($sh('md5sum '.$q($work.'/env.production').' | cut -c1-32') !== $envMd5) { echo "Could not back up production .env -- nothing done.\n"; return; }
$restoreEnv = function () use ($sh, $q, $work, $envMd5) {
    $sh('cp -p '.$q($work.'/env.production').' .env');
    $ok = $sh('md5sum .env | cut -c1-32') === $envMd5;
    echo $ok ? "production .env: unchanged\n" : "WARNING: production .env differs from its backup ($work/env.production)\n";
    return $ok;
};
echo $sh('php artisan down --retry=20 | tail -1'), PHP_EOL;
$dbBackup = null;
try {
    // 1) code
    if ($serverHead !== $localHead) {
        if ($envInCommits) $sh('git checkout -- .env');   // let git move it; production's copy goes back right after
        $bundle = storage_path('app/murano_deploy.bundle');
        $out = $lg('bundle create '.escapeshellarg($bundle).' '.$serverHead.'..HEAD');
        if (! is_file($bundle)) { echo "git bundle failed: $out\n"; return; }
        // Production's checkout is SHALLOW (only its recent commits). A merge
        // of an older branch makes the bundle require a base commit the server
        // never received ("Repository lacks these prerequisite commits",
        // 2026-10-19). Send just those base commits' missing objects as a
        // pack and mark them shallow -- never the whole history (1+ GB).
        $prereqs = [];
        $fh = fopen($bundle, 'rb');
        while (($line = fgets($fh)) !== false && trim($line) !== '') {
            if (preg_match('/^-([0-9a-f]{40})/', $line, $m)) $prereqs[] = $m[1];
        }
        fclose($fh);
        $missing = array_values(array_filter($prereqs, fn ($c) => $sh('git cat-file -t '.$c) !== 'commit'));
        if ($missing && ($rebasedFrom || $sh('git rev-parse --is-shallow-repository') === 'true')) {
            echo 'server is shallow and lacks base commit(s) ', implode(', ', array_map(fn ($c) => substr($c, 0, 9), $missing)), " -- sending only their missing objects\n";
            $objs = [];
            foreach ($missing as $c) {
                foreach (explode("\n", $lg('rev-list --objects --no-walk '.$c)) as $l) {
                    if (preg_match('/^[0-9a-f]{40}/', $l, $m)) $objs[$m[0]] = true;
                }
            }
            $listFile = storage_path('app/murano_deploy_objs.txt');
            file_put_contents($listFile, implode("\n", array_keys($objs))."\n");
            $sftp->put($work.'/objs.txt', $listFile, SFTP::SOURCE_LOCAL_FILE);
            $need = array_filter(explode("\n", $sh('git cat-file --batch-check < '.$q($work.'/objs.txt').' | grep " missing$" | cut -d" " -f1')));
            file_put_contents($listFile, implode("\n", $need)."\n");
            $packFile = storage_path('app/murano_deploy_base.pack');
            shell_exec('"'.$git.'" -C '.escapeshellarg(base_path()).' pack-objects --stdout < '.escapeshellarg($listFile).' > '.escapeshellarg($packFile));
            unlink($listFile);
            echo '  objects: ', count($objs), ' in those commits, ', count($need), ' missing on the server, pack ', round(filesize($packFile) / 1048576, 2), " MB\n";
            $sftp->put($work.'/base.pack', $packFile, SFTP::SOURCE_LOCAL_FILE);
            unlink($packFile);
            echo '  ', $sh('git index-pack --stdin < '.$q($work.'/base.pack').' >/dev/null && echo "pack stored"'), PHP_EOL;
            foreach ($missing as $c) $sh('grep -qx '.$c.' .git/shallow || echo '.$c.' >> .git/shallow');
            $sftp->exec('rm -f '.$q($work.'/base.pack').' '.$q($work.'/objs.txt'));
        }
        if ($rebasedFrom) {
            // The twin commit's objects are on the server now (base pack above): the branch moves
            // to it and the index follows (mixed reset) -- files on disk stay as they are.
            echo $sh('git cat-file -t '.$q($serverHead).' && git update-ref HEAD '.$q($serverHead).' && git reset -q && echo "branch re-pointed to '.substr($serverHead, 0, 9).' (disk untouched)"'), PHP_EOL;
            if ($sh('git rev-parse HEAD') !== $serverHead) { echo "Could not re-point production's branch -- nothing deployed.\n"; return; }
        }
        $sftp->put($work.'/deploy.bundle', $bundle, SFTP::SOURCE_LOCAL_FILE);
        unlink($bundle);
        echo $sh('git bundle verify '.$q($work.'/deploy.bundle').' | tail -1'), PHP_EOL;
        echo $sh('git fetch -q '.$q($work.'/deploy.bundle').' HEAD && git rev-parse FETCH_HEAD'), PHP_EOL;
        foreach ($sameAsIncoming as $p) {   // identical to the new version: let git write them itself
            $sh('(git ls-files --error-unmatch '.$q($p).' >/dev/null 2>&1 && git checkout -- '.$q($p).') || rm -f '.$q($p));
        }
        // A deploy never deletes the companies' files: what the new commits untrack under
        // public/files leaves the server's INDEX only (git rm --cached), the disk is untouched,
        // so the fast-forward has nothing to delete there.
        if ($gone) {
            $list = $work.'/untrack.txt';
            $sftp->put($list, implode("\n", $gone)."\n");
            echo $sh('xargs -a '.$q($list).' -d '.$q("\n").' git rm -q --cached --ignore-unmatch -- && echo "public/files: '.count($gone).' file(s) untracked on the server, kept on disk"'), PHP_EOL;
            $sftp->exec('rm -f '.$q($list));
        }
        echo $sh('git merge --ff-only FETCH_HEAD | tail -3'), PHP_EOL;
        $restoreEnv();
        if ($sh('git rev-parse HEAD') !== $localHead) { echo "Code NOT deployed (fast-forward failed) -- nothing else done.\n"; return; }
        $sftp->exec('rm -f '.$q($work.'/deploy.bundle'));
    }
    // 2) public/js, built on this PC
    $zipFile = storage_path('app/murano_js.zip');
    $zip = new ZipArchive;
    $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $base = public_path('js');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
        $zip->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1)));
    }
    $zip->close();
    $sftp->put($work.'/js.zip', $zipFile, SFTP::SOURCE_LOCAL_FILE);
    unlink($zipFile);
    $js = $sh('rm -rf public/js.new && unzip -q -o '.$q($work.'/js.zip').' -d public/js.new && echo "$(find public/js.new -type f | wc -l) files"'
        .' && rm -rf public/js.prev && (test -d public/js && mv public/js public/js.prev || true) && mv public/js.new public/js && echo "-> public/js swapped"');
    echo $js, PHP_EOL;
    if (! str_contains($js, 'swapped')) echo "WARNING: public/js NOT replaced (the previous one stays).\n";
    $sftp->exec('rm -f '.$q($work.'/js.zip'));
    // 3) composer
    if ($composer) echo $sh('composer install --no-interaction --optimize-autoloader | tail -3'), PHP_EOL;
    // 4) migrations (production DB backup first), run on the server so the files a
    //    migration writes land on the server's disk (2026-11-03).
    $apply = true;
    $migrateOnServer = fn () => $sh('php artisan migrate --force --no-interaction 2>&1 | tail -60; echo "__EXIT:${PIPESTATUS[0]}"');
    ob_start();
    require __DIR__.'/protocol_prod_migrate.php';
    $mig = (string) ob_get_clean();
    echo $mig;
    if (preg_match('/production backup: (\S+)/', $mig, $m)) $dbBackup = $m[1];
    echo $sh('php artisan optimize:clear | tail -1'), PHP_EOL;
} finally {
    $restoreEnv();
    echo $sh('php artisan up | tail -1'), PHP_EOL;
}

// 5) PHP workers restarted (by process name only), site checked
$sh('pkill -u '.$q($cfg['PROD_SSH_USER']).' lsphp; sleep 2');
$codes = [];
for ($i = 0; $i < 4; $i++) { $codes[] = $sh('curl -s -o /dev/null -w "%{http_code}" https://murano.kcoltech.com/login'); sleep(2); }
echo 'site check: ', implode(' ', $codes), PHP_EOL;
$sftp->put($work.'/last_deploy.json', json_encode(['from' => $serverHead, 'to' => $localHead, 'at' => now()->toDateTimeString(), 'db_backup' => $dbBackup]));
echo in_array('200', $codes, true) && count(array_unique($codes)) === 1
    ? "Deployed $localHead.\n"
    : "Site not answering 200 -- check it; to undo the code: \$rollback = true.\n";
