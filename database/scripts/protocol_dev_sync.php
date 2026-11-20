<?php

/*
 * Dev PCs share their latest work (user's rule, 2026-11-20: "lo que yo haga en Optimum, Hogwarts, Thundera (dev) siempre
 * debe sincronizarse con la última versión"; production is never touched by this script).
 *
 * Each morning Wake-up replaces a PC's dev database with production's copy, which used to wipe what any PC had done in dev
 * (a live company's data never goes to production, and A Dormir sends nothing). Now:
 *
 *   $devSyncMode = 'save'   -- the end of A Dormir / Pa Llevar, after its local backup: <archive>/dev_sync/<PC>.json says
 *                              "this PC's latest dev version is that backup", with the PC's morning sync record (what is
 *                              new in dev = after it) and which other versions it already holds. Pa Llevar passes
 *                              $pushedToProd = true once protocol_prod_push.php went well (then only the companies in
 *                              production travel dev -> dev: the rest is already in production).
 *   $devSyncMode = 'apply'  -- Wake-up, right after murano:from-prod: the latest version of every PC (this one too) that no
 *                              other version already contains is imported into a scratch database and its dev changes are
 *                              applied on top of this PC's fresh copy with protocol_prod_push.php's rules (same ids when
 *                              free, new ids + rewritten references when taken, both sides changed = conflict, reported).
 *                              Production's data wins a conflict. The files those rows use come from the Drive mirror.
 *
 *   php artisan tinker --execute="$devSyncMode = 'apply'; require '<repo>/database/scripts/protocol_dev_sync.php';"
 *   optional: $apply = false (dry run of 'apply').
 *
 * Not carried: the Email Office tables (they are in the backup's office_emails parts), audit / login history, sessions.
 */

use App\Services\ProdDump;
use App\Support\BackupPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

$mode = $devSyncMode ?? null;
$dir = BackupPaths::archive().DIRECTORY_SEPARATOR.'dev_sync';
@mkdir($dir, 0775, true);
$pc = BackupPaths::pcFolder();
$appliedFile = storage_path('app/dev_sync_applied.json');
$applied = json_decode((string) @file_get_contents($appliedFile), true) ?: [];

if ($mode === 'save') {
    if (DB::getDatabaseName() !== 'dbmurano') { echo "dev sync: not a dev database -- nothing saved.\n"; return; }
    $zip = collect(glob(BackupPaths::pc().DIRECTORY_SEPARATOR.'dbmurano_*.zip') ?: [])
        ->reject(fn ($f) => str_contains($f, '_office_emails_'))->sortByDesc(fn ($f) => filemtime($f))->first();
    if (! $zip || filemtime($zip) < time() - 6 * 3600) { echo "dev sync: no recent local backup of this PC -- run the protocol's backup first; nothing saved.\n"; return; }
    $sync = json_decode((string) @file_get_contents(BackupPaths::lastSync(null, true)), true) ?: [];
    if (empty($sync['pulled_at'])) { echo "dev sync: this PC has no morning copy recorded -- nothing saved.\n"; return; }
    $rec = [
        'pc' => $pc, 'at' => now()->format('Y-m-d H:i:s'), 'backup' => basename(dirname($zip)).'/'.basename($zip),
        'sync' => ['pulled_at' => $sync['pulled_at'], 'dev_max_ids' => $sync['dev_max_ids'] ?? []],
        'pushed_to_prod' => (bool) ($pushedToProd ?? false), 'includes' => $applied,
    ];
    file_put_contents("$dir/$pc.json", json_encode($rec, JSON_PRETTY_PRINT));
    echo "dev sync: this PC's dev version saved (backup {$rec['backup']}, since {$rec['sync']['pulled_at']}".($rec['pushed_to_prod'] ? ', already in production except the live companies' : '').")\n";
    return;
}

if ($mode !== 'apply') { echo "dev sync: say \$devSyncMode = 'save' (A Dormir / Pa Llevar) or 'apply' (Wake-up) -- nothing done.\n"; return; }
if (DB::getDatabaseName() !== 'dbmurano') { echo "dev sync: not a dev database -- nothing applied.\n"; return; }
$dryRun = isset($apply) && ! $apply;

// The latest version of each PC; one already contained in another (that PC applied it at its Wake-up) is skipped.
$cands = collect(glob("$dir/*.json") ?: [])->map(fn ($f) => json_decode((string) file_get_contents($f), true))->filter(fn ($r) => ! empty($r['pc']) && ! empty($r['at']))->keyBy('pc');
$cands = $cands->reject(function ($ver) use ($cands) {
    foreach ($cands as $o) if ($o['pc'] !== $ver['pc'] && (string) ($o['includes'][$ver['pc']] ?? '') >= $ver['at']) return true;
    return false;
})->sortBy('at');
if ($cands->isEmpty()) { echo "dev sync: no dev version saved by any PC yet -- nothing to apply.\n"; return; }

$dump = ProdDump::findClient('mysqldump');
$mysql = $dump ? ProdDump::findClient('mysql', dirname($dump)) : null;
if (! $mysql) { echo "dev sync: mysql client not found -- nothing applied.\n"; return; }
$local = config('database.connections.'.config('database.default'));
$peerDb = 'dbmurano_devsync';
config(['database.connections.devsync' => array_merge($local, ['database' => $peerDb])]);
$devSyncLive = DB::table('companies')->where('is_live', 1)->pluck('id')->map(fn ($v) => (int) $v)->all();
$newApplied = $applied;

foreach ($cands as $ver) {
    $zipPath = BackupPaths::archive().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ver['backup']);
    echo "dev sync <- {$ver['pc']} ({$ver['at']}, since {$ver['sync']['pulled_at']}".($ver['pushed_to_prod'] ? ', live companies only' : '').")\n";
    if (! is_file($zipPath)) { echo "  its backup is not on this PC yet (Drive still downloading?): {$ver['backup']} -- skipped\n"; continue; }
    // Import that PC's dev copy into the scratch database.
    $tmp = storage_path('app/devsync_'.date('Ymd_His'));
    @mkdir($tmp, 0775, true);
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) { echo "  cannot open the backup -- skipped\n"; continue; }
    $z->extractTo($tmp); $z->close();
    $sql = collect(glob("$tmp/*.sql") ?: [])->first();
    DB::statement("DROP DATABASE IF EXISTS `$peerDb`");
    DB::statement("CREATE DATABASE `$peerDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $in = fopen($sql, 'r');
    $res = Process::timeout(3600)->env(['MYSQL_PWD' => (string) $local['password']])->input($in)->run([
        $mysql, '--host='.$local['host'], '--port='.$local['port'], '--user='.$local['username'], '--default-character-set=utf8mb4', $peerDb,
    ]);
    fclose($in);
    array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp);
    if (! $res->successful()) { echo '  import FAILED: '.trim($res->errorOutput())." -- skipped\n"; continue; }
    DB::purge('devsync');

    // Apply its dev changes on this PC with the push rules (dev -> dev).
    $apply = ! $dryRun; $sourceConn = 'devsync'; $targetConn = config('database.default'); $sourceSync = $ver['sync'];
    $onlyCompanies = $ver['pushed_to_prod'] ? $devSyncLive : null;
    $since = null;
    ob_start();
    require __DIR__.'/protocol_prod_push.php';
    echo preg_replace('/^/m', '  ', rtrim(ob_get_clean()))."\n";
    unset($since, $onlyCompanies);

    // A company's menu (Manage Menu, 2026-11-20): saving it deletes and re-inserts every row with new ids, so it can't
    // go row by row -- a company whose menu changed on that PC after its morning copy gets that PC's whole menu.
    $src = DB::connection('devsync');
    $menuSince = \Carbon\Carbon::parse($ver['sync']['pulled_at'], config('app.timezone'))->utc()->format('Y-m-d H:i:s');
    $src->statement("SET time_zone = '+00:00'");
    $menuCos = $src->table('menu_positions')->where('updated_at', '>', $menuSince)->distinct()->pluck('company_id')->map(fn ($v) => (int) $v)
        ->filter(fn ($id) => ! $ver['pushed_to_prod'] || in_array($id, $devSyncLive, true))->values();
    foreach ($menuCos as $co) {
        $rows = $src->table('menu_positions')->where('company_id', $co)->get()->map(function ($r) { $r = (array) $r; unset($r['id']); return $r; })->all();
        $before = DB::table('menu_positions')->where('company_id', $co)->count();
        if (! $dryRun) {
            DB::transaction(function () use ($co, $rows) {
                DB::table('menu_positions')->where('company_id', $co)->delete();
                foreach (array_chunk($rows, 200) as $chunk) DB::table('menu_positions')->insert($chunk);
            });
        }
        echo "  menu of company $co: replaced by that PC's ($before -> ".count($rows)." items)\n";
    }
    if (! $dryRun) $newApplied[$ver['pc']] = $ver['at'];

    // The files of what came: from the Drive mirror of public/files (Pa Llevar copies each PC's there), never overwriting.
    $mirror = BackupPaths::archive().DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'files';
    if (is_dir($mirror) && ! $dryRun) {
        $sinceTs = strtotime($ver['sync']['pulled_at']);
        $copied = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mirror, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getMTime() < $sinceTs) continue;
            $rel = substr($f->getPathname(), strlen($mirror) + 1);
            $dest = public_path('files'.DIRECTORY_SEPARATOR.$rel);
            if (! is_file($dest)) { @mkdir(dirname($dest), 0775, true); copy($f->getPathname(), $dest) && $copied++; }
        }
        echo "  files from the Drive mirror: $copied\n";
    }
}
DB::statement("DROP DATABASE IF EXISTS `$peerDb`");
// What this PC now holds; its next 'save' lists it, so the other PCs don't apply it twice.
foreach ($cands as $ver) if (! $dryRun) $newApplied[$ver['pc']] = $ver['at'];
if (! $dryRun) file_put_contents($appliedFile, json_encode($newApplied));
echo $dryRun ? "dev sync: dry run -- nothing applied.\n" : "dev sync: done.\n";
