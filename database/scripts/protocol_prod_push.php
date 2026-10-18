<?php

/*
 * Protocol Sube Prod -- data step (user's rule, 2026-10-18): what was CREATED or
 * CHANGED in dev since the last Baja Prod goes up to production (a user, a
 * company's logo setting...). Production stays the owner of everything else.
 *
 *   php artisan tinker --execute="$apply = false; require '<repo>/database/scripts/protocol_prod_push.php';"   (dry run)
 *   php artisan tinker --execute="$apply = true;  require '<repo>/database/scripts/protocol_prod_push.php';"
 *   optional: $since = 'YYYY-MM-DD HH:MM:SS' (dev's clock) instead of the last Baja Prod time
 *
 * Rules, per table (common columns only):
 *  - a dev row created after $since that production doesn't have -> inserted
 *    with the SAME id when that id is free in production; taken by another
 *    row = CONFLICT (reported, not sent)
 *  - a dev row changed after $since that production has -> production's copy
 *    is replaced only when production did NOT change it after $since too;
 *    both changed = CONFLICT (reported, not sent)
 *  - tables without id (user_warehouse...): dev rows production lacks whose
 *    *_id points to a row sent in this run
 *  - never: migrations, kt_logs, translations / permissions / menu_positions
 *    (they come with migrations), sessions, queues, caches, login history
 * Production is backed up first (mysqldump, KT-TOOLS/database/production/),
 * then everything is written in ONE transaction. $since = the last Baja Prod
 * (KT-TOOLS/database/production/last_sync.json, written by protocol_prod_pull).
 */

use Illuminate\Support\Facades\DB;

$apply = $apply ?? false;
$prodEnv = require __DIR__.'/protocol_prod_conn.php';
if (! $prodEnv) return;
$p = DB::connection('prod');
$d = DB::connection();

$syncFile = base_path('KT-TOOLS/database/production/last_sync.json');
$since = $since ?? (json_decode((string) @file_get_contents($syncFile), true)['pulled_at'] ?? null);
if (! $since) { echo "No last Baja Prod recorded on this PC -- run Baja Prod first, or pass \$since.\n"; return; }
echo 'dev changes since ', $since, " (dev's clock) -> production ", $p->getDatabaseName(), ($apply ? '  (APPLY)' : '  (dry run)'), PHP_EOL;
// Both sides read TIMESTAMP columns in UTC (else the same row looks different);
// $since was written on dev's clock (app timezone) -> UTC too.
$p->statement("SET time_zone = '+00:00'");
$d->statement("SET time_zone = '+00:00'");
$since = \Carbon\Carbon::parse($since, config('app.timezone'))->utc()->format('Y-m-d H:i:s');

$skip = ['migrations', 'kt_logs', 'translations', 'permissions', 'menu_positions', 'jobs', 'failed_jobs', 'job_batches',
    'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'user_login_sessions', 'oauth_access_tokens',
    'oauth_refresh_tokens', 'oauth_auth_codes', 'personal_access_tokens', 'telescope_entries', 'booking_public_requests'];
$tablesOf = fn ($c) => collect($c->select("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"))->pluck('t')->all();
$colsOf = fn ($c, $t) => collect($c->select('SELECT column_name c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$t]))->pluck('c')->all();

$plan = []; $conflicts = []; $sentIds = [];
$pivots = [];
// Child rows rewritten with new ids when their parent is saved (a role's
// permission_role rows): for a parent changed in dev after $since, production's
// set for that parent is REPLACED by dev's (else it would get duplicates / keep
// what dev removed); the generic per-row rules skip these tables.
$parentSets = ['permission_role' => ['roles', 'role_id', ['role_id', 'permission_id']]];
$replaceSets = [];
// "menu_hide:*" permissions are created by the role editor (not by a migration):
// a new one goes up by name, with its id when that id is free in production.
$newPerms = [];
foreach ($d->table('permissions')->where('name', 'like', 'menu_hide:%')->where('created_at', '>', $since)->get() as $perm) {
    if ($p->table('permissions')->where('name', $perm->name)->exists()) continue;
    if ($p->table('permissions')->where('id', $perm->id)->exists()) { $conflicts[] = "permissions #{$perm->id} {$perm->name}: id already used in production"; continue; }
    $newPerms[] = (array) $perm;
}
if ($newPerms) {
    $plan['permissions'] = ['ins' => array_map(fn ($r) => array_intersect_key($r, array_flip($colsOf($p, 'permissions'))), $newPerms), 'upd' => []];
    echo '  permissions: new ', count($newPerms), ' [', implode(', ', array_column($newPerms, 'name')), "]\n";
}
foreach ($parentSets as $t => [$pt, $fk, $pair]) {
    $parents = $d->table($pt)->where('updated_at', '>', $since)->pluck('updated_at', 'id');
    foreach ($parents as $pid => $devU) {
        $prodU = $p->table($pt)->where('id', $pid)->value('updated_at');
        if ($prodU === null) continue;   // a new parent: its rows go with the normal rules
        if ((string) $prodU > $since) { $conflicts[] = "$pt #$pid: changed in BOTH dev and production -- its $t not sent"; continue; }
        $devSet = $d->table($t)->where($fk, $pid)->get($pair)->map(fn ($r) => (array) $r)->all();
        $prodN = $p->table($t)->where($fk, $pid)->count();
        $replaceSets[$t][$pid] = $devSet;
        echo "  $t: $pt #$pid set replaced in production ($prodN rows -> ".count($devSet).")\n";
    }
}
foreach (array_diff(array_intersect($tablesOf($d), $tablesOf($p)), $skip, array_keys($parentSets)) as $t) {
    $c = array_values(array_intersect($colsOf($p, $t), $colsOf($d, $t)));
    if (! in_array('id', $c)) { $pivots[$t] = $c; continue; }
    $hasC = in_array('created_at', $c); $hasU = in_array('updated_at', $c);
    if (! $hasC && ! $hasU) continue;   // no way to tell what changed in dev
    $changed = $d->table($t)->where(function ($q) use ($hasC, $hasU, $since) {
        if ($hasC) $q->orWhere('created_at', '>', $since);
        if ($hasU) $q->orWhere('updated_at', '>', $since);
    })->get($c);
    if ($changed->isEmpty()) continue;
    $prodRows = $p->table($t)->whereIn('id', $changed->pluck('id'))->get($c)->keyBy('id');
    $ins = []; $upd = [];
    foreach ($changed as $row) {
        $row = (array) $row;
        $pr = $prodRows[$row['id']] ?? null;
        $isNew = $hasC && (string) $row['created_at'] > $since;
        if (! $pr) {
            if ($isNew) { $ins[] = $row; continue; }
            $conflicts[] = "$t #{$row['id']}: changed in dev but missing in production";
            continue;
        }
        $pr = (array) $pr;
        if ($isNew && ($pr['created_at'] ?? null) != $row['created_at']) { $conflicts[] = "$t #{$row['id']}: id already used in production by another row"; continue; }
        $diff = array_keys(array_filter($c, fn ($col) => ! in_array($col, ['id'], true) && (string) ($row[$col] ?? '') !== (string) ($pr[$col] ?? '')));
        if (! $diff) continue;
        if ($hasU && (string) ($pr['updated_at'] ?? '') > $since) { $conflicts[] = "$t #{$row['id']}: changed in BOTH dev and production (".implode(', ', array_slice(array_map(fn ($i) => $c[$i], $diff), 0, 4)).')'; continue; }
        $upd[] = ['row' => $row, 'cols' => array_map(fn ($i) => $c[$i], $diff)];
    }
    if ($ins || $upd) {
        $plan[$t] = compact('ins', 'upd');
        $sentIds[$t] = array_merge(array_column($ins, 'id'), array_map(fn ($u) => $u['row']['id'], $upd));
    }
}
// pivots: dev rows production lacks that point to rows sent now
$fkTable = fn ($col) => ['user_id' => 'users', 'employee_id' => 'employees', 'role_id' => 'roles', 'warehouse_id' => 'warehouses',
    'project_id' => 'projects', 'task_id' => 'tasks', 'permission_id' => 'permissions', 'client_id' => 'clients', 'product_id' => 'products'][$col] ?? null;
foreach ($pivots as $t => $c) {
    $refs = array_filter($c, fn ($col) => ($tb = $fkTable($col)) && ! empty($sentIds[$tb]));
    if (! $refs) continue;
    $hash = 'MD5(CONCAT_WS("|", '.implode(', ', array_map(fn ($x) => "IFNULL(`$x`, '\\\\N')", $c)).'))';
    $have = collect($p->select("SELECT $hash h FROM `$t`"))->pluck('h')->flip()->all();
    $rows = [];
    foreach ($d->table($t)->where(function ($q) use ($refs, $fkTable, $sentIds) { foreach ($refs as $col) $q->orWhereIn($col, $sentIds[$fkTable($col)]); })->get($c) as $r) {
        $h = md5(implode('|', array_map(fn ($x) => $r->$x ?? '\\N', $c)));
        if (! isset($have[$h])) $rows[] = (array) $r;
    }
    if ($rows) $plan[$t] = ['ins' => $rows, 'upd' => [], 'pivot' => true];
}

// files: public/files written in dev after $since that production doesn't have (same size)
$sinceTs = \Carbon\Carbon::parse($since, 'UTC')->getTimestamp();
$devFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path('files'), FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getMTime() > $sinceTs) $devFiles[str_replace('\\', '/', substr($f->getPathname(), strlen(public_path()) + 1))] = $f->getSize();
}
$filesToSend = [];
$sshCfg = is_file(base_path('.env.production-ssh')) ? Dotenv\Dotenv::parse(file_get_contents(base_path('.env.production-ssh'))) : null;
if ($devFiles && $sshCfg) {
    $sftp = new phpseclib3\Net\SFTP($sshCfg['PROD_SSH_HOST'], (int) $sshCfg['PROD_SSH_PORT'], 60);
    $sftp->setTimeout(600);
    if ($sftp->login($sshCfg['PROD_SSH_USER'], $sshCfg['PROD_SSH_PASSWORD'] ?? '')) {
        foreach ($devFiles as $rel => $size) {
            $st = $sftp->stat(rtrim($sshCfg['PROD_PATH'], '/').'/public/'.$rel);
            if (! $st || (int) $st['size'] !== $size) $filesToSend[] = $rel;
        }
    }
} elseif ($devFiles) {
    echo "  (files: no .env.production-ssh on this PC -- not checked)\n";
}
echo '  files: ', count($filesToSend), $filesToSend ? ' ['.implode(', ', array_slice($filesToSend, 0, 8)).']' : '', PHP_EOL;

foreach ($plan as $t => $x) {
    echo "  $t: new ", count($x['ins']), $x['ins'] && empty($x['pivot']) ? ' ['.implode(',', array_slice(array_column($x['ins'], 'id'), 0, 10)).']' : '',
        ', updated ', count($x['upd']), $x['upd'] ? ' ['.implode('; ', array_map(fn ($u) => '#'.$u['row']['id'].' '.implode(',', array_slice($u['cols'], 0, 4)), array_slice($x['upd'], 0, 5))).']' : '', PHP_EOL;
}
$hasWork = $plan || $filesToSend || $replaceSets;
if (! $hasWork) echo "  nothing to send.\n";
if ($conflicts) echo 'CONFLICTS (not sent): ', count($conflicts), "\n  ", implode("\n  ", array_slice($conflicts, 0, 30)), PHP_EOL;
if (! $apply || ! $hasWork) { if (! $apply && $hasWork) echo "Dry run -- nothing written. Run again with \$apply = true.\n"; return; }

// files first (never overwrites a production file of another size without a copy)
foreach ($filesToSend as $rel) {
    $remote = rtrim($sshCfg['PROD_PATH'], '/').'/public/'.$rel;
    $q = fn (string $s) => "'".str_replace("'", "'\\''", $s)."'";
    $sftp->exec('mkdir -p '.$q(dirname($remote)).' && (test -f '.$q($remote).' && cp -p '.$q($remote).' '.$q($remote.'.bak-'.date('Ymd-His')).' || true)');
    echo $sftp->put($remote, public_path($rel), phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE) ? "  file sent: $rel\n" : "  FILE FAILED: $rel\n";
}
if (! $plan && ! $replaceSets) { echo "Sent.\n"; return; }

// production backup first
$dir = base_path('KT-TOOLS/database/production');
if (! is_dir($dir)) mkdir($dir, 0775, true);
$sql = "$dir/{$prodEnv['PROD_DB_DATABASE']}_before_push_".now()->format('Y-m-d_h-i-s-A').'.sql';
$dump = is_file('C:/Program Files/MariaDB 11.8/bin/mysqldump.exe') ? 'C:/Program Files/MariaDB 11.8/bin/mysqldump.exe' : 'mysqldump';
putenv('MYSQL_PWD='.$prodEnv['PROD_DB_PASSWORD']);
exec(sprintf('"%s" --host=%s --port=%s --user=%s --single-transaction --skip-lock-tables --no-tablespaces --default-character-set=utf8mb4 %s --result-file="%s" 2>&1',
    $dump, $prodEnv['PROD_DB_HOST'], $prodEnv['PROD_DB_PORT'] ?? 3306, $prodEnv['PROD_DB_USERNAME'], $prodEnv['PROD_DB_DATABASE'], $sql), $out, $code);
putenv('MYSQL_PWD');
if ($code !== 0 || ! is_file($sql) || filesize($sql) < 1000) { echo "Production backup FAILED -- nothing sent.\n", implode("\n", $out), PHP_EOL; return; }
$zip = new ZipArchive; $zip->open("$sql.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFile($sql, basename($sql)); $zip->close(); unlink($sql);
echo 'production backup: ', "$sql.zip", PHP_EOL;

$p->transaction(function () use ($plan, $p, $replaceSets, $parentSets) {
    $p->statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($replaceSets as $t => $sets) {
        $fk = $parentSets[$t][1];
        foreach ($sets as $pid => $rows) {
            $p->table($t)->where($fk, $pid)->delete();
            foreach (array_chunk($rows, 200) as $chunk) $p->table($t)->insert($chunk);
            echo "  $t: $fk $pid -> ", count($rows), " rows\n";
        }
    }
    foreach ($plan as $t => $x) {
        foreach (array_chunk($x['ins'], 200) as $chunk) $p->table($t)->insert($chunk);
        foreach ($x['upd'] as $u) $p->table($t)->where('id', $u['row']['id'])->update(array_intersect_key($u['row'], array_flip($u['cols'])));
        echo "  $t: inserted ", count($x['ins']), ', updated ', count($x['upd']), PHP_EOL;
    }
    $p->statement('SET FOREIGN_KEY_CHECKS = 1');
});
echo "Sent.\n";
