<?php

/*
 * Wake-up / Pa Llevar, "update production" -- data step (user's rule, 2026-10-18): what was CREATED or
 * CHANGED in dev since the last production pull (Wake-up / Pa Llevar) goes up to production (a user, a
 * company's logo setting...). Production stays the owner of everything else.
 *
 *   php artisan tinker --execute="$apply = false; require '<repo>/database/scripts/protocol_prod_push.php';"   (dry run)
 *   php artisan tinker --execute="$apply = true;  require '<repo>/database/scripts/protocol_prod_push.php';"
 *   optional: $since = 'YYYY-MM-DD HH:MM:SS' (dev's clock) instead of the last production pull time
 *
 * Rules, per table (common columns only):
 *  - "created in dev" = created_at after $since, OR an id above that table's highest dev id at the
 *    last pull (last_sync.json dev_max_ids, 2026-10-20: back-dated demo rows, tables without
 *    timestamps); a new role takes its whole permission_role set with it
 *  - COMPANIES IN PRODUCTION (user's rule, 2026-10-26; companies.is_live, read from production:
 *    Ruba, New Era, Turismo Cautivo...): nothing of theirs is ever sent -- rows with their
 *    company_id, rows hanging from one of their rows (sale_details.sale_id -> a Ruba sale), the
 *    company row itself and files/company_{id}/. Every other company's dev data goes up.
 *  - a dev row created after $since that production doesn't have -> inserted
 *    with the SAME id when that id is free in production; taken by another row
 *    (e.g. Ruba's sales of the day) -> inserted with a NEW id, and the rows sent in
 *    this run that point to it (`<name>_id` -> <names>, parent_id, company_id, and
 *    "files/company_{id}/" paths for a new company) are rewritten to match
 *  - a dev row changed after $since that production has -> production's copy
 *    is replaced only when production did NOT change it after $since too;
 *    both changed = CONFLICT (reported, not sent)
 *  - tables without id (user_warehouse...): dev rows production lacks whose
 *    *_id points to a row sent in this run
 *  - never: migrations, kt_logs, translations / permissions / menu_positions
 *    (they come with migrations), sessions, queues, caches, login history
 * Production is backed up first (mysqldump, KT-TOOLS/database/production/),
 * then everything is written in ONE transaction. $since = the last production pull
 * (KT-TOOLS/database/production/last_sync.json, written by protocol_prod_pull).
 */

use Illuminate\Support\Facades\DB;

$apply = $apply ?? false;
$prodEnv = require __DIR__.'/protocol_prod_conn.php';
if (! $prodEnv) return;
$p = DB::connection('prod');
$d = DB::connection();

$syncFile = \App\Support\BackupPaths::production('last_sync.json');
$syncInfo = json_decode((string) @file_get_contents($syncFile), true) ?: [];
$since = $since ?? ($syncInfo['pulled_at'] ?? null);
// Each table's highest dev id at the last pull (2026-10-20): a higher id = created in dev after it, even
// with an older created_at (back-dated demo data) or no timestamps at all (permission_role). Missing for a
// table (older sync file) = only the created_at / updated_at rules.
$devMaxAtPull = $syncInfo['dev_max_ids'] ?? [];
$isNewInDev = fn (string $t, $row) => isset($devMaxAtPull[$t]) && (int) ($row['id'] ?? 0) > $devMaxAtPull[$t];
if (! $since) { echo "No production pull recorded on this PC -- run Wake-up (it brings production down first), or pass \$since.\n"; return; }
if (! empty($syncInfo['remapped_pending'])) { echo "The last push gave new ids to {$syncInfo['remapped_pending']} row(s): dev no longer matches production -- run `php artisan murano:from-prod` before pushing again.\n"; return; }
echo 'dev changes since ', $since, " (dev's clock) -> production ", $p->getDatabaseName(), ($apply ? '  (APPLY)' : '  (dry run)'), PHP_EOL;
// Both sides read TIMESTAMP columns in UTC (else the same row looks different);
// $since was written on dev's clock (app timezone) -> UTC too.
$p->statement("SET time_zone = '+00:00'");
$d->statement("SET time_zone = '+00:00'");
$since = \Carbon\Carbon::parse($since, config('app.timezone'))->utc()->format('Y-m-d H:i:s');

$skip = ['migrations', 'kt_logs', 'translations', 'permissions', 'menu_positions', 'jobs', 'failed_jobs', 'job_batches',
    'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'user_login_sessions', 'oauth_access_tokens',
    'oauth_refresh_tokens', 'oauth_auth_codes', 'personal_access_tokens', 'telescope_entries', 'booking_public_requests',
    // Each server's own records (2026-11-12): a developer PC's tests are not production's history, security log or devices.
    'audit_logs', 'audit_logs_archive', 'login_events', 'login_devices', 'login_links', 'password_histories', 'trusted_devices'];
$tablesOf = fn ($c) => collect($c->select("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"))->pluck('t')->all();
$colsOf = fn ($c, $t) => collect($c->select('SELECT column_name c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$t]))->pluck('c')->all();

// Companies in production (2026-10-26): production's list, never sent.
if (! in_array('is_live', $colsOf($p, 'companies'), true)) { echo "Production has no companies.is_live yet -- deploy the code first (migration 2026_10_26_190000).\n"; return; }
$live = $p->table('companies')->where('is_live', 1)->pluck('name', 'id')->all();
echo '  companies in production (never sent): ', implode(', ', array_map(fn ($id, $n) => "$n ($id)", array_keys($live), $live)), PHP_EOL;
$liveIds = array_map('intval', array_keys($live));
$devTables = $tablesOf($d);
$hasCompanyCol = [];
$companyCol = function (string $t) use (&$hasCompanyCol, $colsOf, $d) { return $hasCompanyCol[$t] ??= in_array('company_id', $colsOf($d, $t), true); };
// The table a `<name>_id` column points to: parent_id = the same table, company_id = companies, else <names>.
$refTable = function (string $t, string $col) use ($devTables) {
    if ($col === 'parent_id') return $t;
    if ($col === 'id' || ! str_ends_with($col, '_id')) return null;
    $rt = \Illuminate\Support\Str::plural(substr($col, 0, -3));
    // Also with the table's own prefix (2026-11-03): acc_journal_entry_lines.journal_entry_id ->
    // acc_journal_entries -- without it a live company's journal lines went up on their own.
    $cands = [$rt];
    $parts = explode('_', $t);
    for ($i = 1; $i < count($parts); $i++) $cands[] = implode('_', array_slice($parts, 0, $i)).'_'.$rt;
    foreach ($cands as $c) if (in_array($c, $devTables, true)) return $c;
    return null;
};
$liveSkipped = [];
// Drops the rows of a live company: own company_id, the company row itself, or a parent row (dev) of a live company.
$dropLive = function (string $t, $rows) use ($liveIds, $companyCol, $refTable, $d, &$liveSkipped) {
    $rows = collect($rows)->map(fn ($r) => (array) $r);
    if (! $liveIds || $rows->isEmpty()) return $rows->values();
    $cols = array_keys($rows->first());
    $bad = fn ($r) => false;
    if ($t === 'companies') {
        $bad = fn ($r) => in_array((int) $r['id'], $liveIds, true);
    } elseif (in_array('company_id', $cols, true)) {
        $bad = fn ($r) => in_array((int) $r['company_id'], $liveIds, true);
    } else {
        $liveRefs = [];
        foreach ($cols as $col) {
            $rt = $refTable($t, $col);
            if (! $rt || $rt === $t || ! $companyCol($rt)) continue;
            $ids = $rows->pluck($col)->filter()->unique()->values()->all();
            if (! $ids) continue;
            $liveRefs[$col] = array_flip($d->table($rt)->whereIn('id', $ids)->whereIn('company_id', $liveIds)->pluck('id')->all());
        }
        if ($liveRefs) {
            $bad = function ($r) use ($liveRefs) {
                foreach ($liveRefs as $col => $set) if (isset($set[$r[$col] ?? null])) return true;
                return false;
            };
        }
    }
    $kept = $rows->reject($bad)->values();
    if ($n = $rows->count() - $kept->count()) $liveSkipped[$t] = ($liveSkipped[$t] ?? 0) + $n;
    return $kept;
};
// New rows whose id production already uses get a new id (per table: above both sides' highest id).
$remap = []; $nextId = [];
$allocId = function (string $t) use (&$nextId, $p, $d) {
    $nextId[$t] ??= max((int) $p->table($t)->max('id'), (int) $d->table($t)->max('id')) + 1;
    return $nextId[$t]++;
};

// Never overwritten on rows production already has: what each person sets in
// production (password, sign-in token, portal language, start page) -- dev only
// has test values there. New rows still go up whole.
$noUpdateCols = ['users' => ['password', 'remember_token', 'preferred_locale', 'start_preferences', 'last_login_at', 'header_icons']];
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
    $parents = $d->table($pt)->where(function ($q) use ($since, $devMaxAtPull, $pt) {
        $q->where('updated_at', '>', $since);
        if (isset($devMaxAtPull[$pt])) $q->orWhere('id', '>', $devMaxAtPull[$pt]);
    })->when($liveIds && $companyCol($pt), fn ($q) => $q->where(fn ($w) => $w->whereNotIn('company_id', $liveIds)->orWhereNull('company_id')))
        ->pluck('updated_at', 'id');
    foreach ($parents as $pid => $devU) {
        $prodU = $p->table($pt)->where('id', $pid)->value('updated_at');
        if (! $p->table($pt)->where('id', $pid)->exists()) {
            // A new parent (a role created in dev): it goes up with the normal rules, and its whole
            // set with it (permission_role has no timestamps, the generic rules never see it).
            $devSet = $d->table($t)->where($fk, $pid)->get($pair)->map(fn ($r) => (array) $r)->all();
            if ($devSet) {
                $replaceSets[$t][$pid] = $devSet;
                echo "  $t: new $pt #$pid -> ".count($devSet)." rows\n";
            }
            continue;
        }
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
    $byId = isset($devMaxAtPull[$t]);
    if (! $hasC && ! $hasU && ! $byId) continue;   // no way to tell what changed in dev
    $changed = $dropLive($t, $d->table($t)->where(function ($q) use ($hasC, $hasU, $since, $byId, $devMaxAtPull, $t) {
        if ($hasC) $q->orWhere('created_at', '>', $since);
        if ($hasU) $q->orWhere('updated_at', '>', $since);
        if ($byId) $q->orWhere('id', '>', $devMaxAtPull[$t]);
    })->get($c));
    if ($changed->isEmpty()) continue;
    $prodRows = $p->table($t)->whereIn('id', $changed->pluck('id'))->get($c)->keyBy('id');
    $ins = []; $upd = [];
    foreach ($changed as $row) {
        $row = (array) $row;
        $pr = $prodRows[$row['id']] ?? null;
        $isNew = ($hasC && (string) $row['created_at'] > $since) || $isNewInDev($t, $row);
        if (! $pr) {
            if ($isNew) { $ins[] = $row; continue; }
            $conflicts[] = "$t #{$row['id']}: changed in dev but missing in production";
            continue;
        }
        $pr = (array) $pr;
        // Created in dev, but production gave that id to another row meanwhile: it goes up with a new id.
        if ($isNew && (! $hasC || ($pr['created_at'] ?? null) != $row['created_at'])) { $remap[$t][$row['id']] = $allocId($t); $ins[] = $row; continue; }
        $skipCols = array_merge(['id', 'updated_at'], $noUpdateCols[$t] ?? []);
        $diff = array_keys(array_filter($c, fn ($col) => ! in_array($col, $skipCols, true) && (string) ($row[$col] ?? '') !== (string) ($pr[$col] ?? '')));
        if (! $diff) continue;
        if (in_array('updated_at', $c, true)) $diff[] = array_search('updated_at', $c, true);
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

// New ids: each remapped row and every row of this run pointing to it; a new company's
// "files/company_{old}/" paths follow its new id.
$remapped = array_sum(array_map('count', $remap));
$pathMap = [];
if ($remap) {
    foreach ($remap['companies'] ?? [] as $old => $new) $pathMap["files/company_$old/"] = "files/company_$new/";
    $fix = function (string $t, array $row) use ($remap, $refTable, $pathMap) {
        foreach ($row as $col => $v) {
            if ($v === null) continue;
            if ($col === 'id') { if (isset($remap[$t][$v])) $row[$col] = $remap[$t][$v]; continue; }
            if (($rt = $refTable($t, $col)) && isset($remap[$rt][$v])) $row[$col] = $remap[$rt][$v];
            elseif ($pathMap && is_string($v) && str_contains($v, 'files/company_')) $row[$col] = strtr($v, $pathMap);
        }
        return $row;
    };
    foreach ($plan as $t => $x) {
        $plan[$t]['ins'] = array_map(fn ($r) => $fix($t, $r), $x['ins']);
        $plan[$t]['upd'] = array_map(function ($u) use ($fix, $t) {
            $row = $fix($t, $u['row']);
            foreach ($row as $k => $v) if ($v !== $u['row'][$k]) $u['cols'][] = $k;   // rewritten = written
            return ['row' => $row, 'cols' => array_values(array_unique($u['cols']))];
        }, $x['upd']);
    }
    foreach ($replaceSets as $t => $sets) {
        $fk = $parentSets[$t][1];
        $out = [];
        foreach ($sets as $pid => $rows) $out[$remap[$parentSets[$t][0]][$pid] ?? $pid] = array_map(fn ($r) => $fix($t, $r), $rows);
        $replaceSets[$t] = $out;
    }
    foreach ($remap as $t => $m) echo "  $t: ", count($m), ' new row(s) with a new id (taken in production) [', implode(', ', array_slice(array_map(fn ($o, $n) => "$o->$n", array_keys($m), $m), 0, 8)), "]\n";
}
foreach ($liveSkipped as $t => $n) echo "  $t: $n row(s) of a company in production -- not sent\n";

// files: public/files written in dev after $since that production doesn't have (same size)
$sinceTs = \Carbon\Carbon::parse($since, 'UTC')->getTimestamp();
$devFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path('files'), FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getMTime() > $sinceTs) $devFiles[str_replace('\\', '/', substr($f->getPathname(), strlen(public_path()) + 1))] = $f->getSize();
}
// never a company in production's files; a remapped company's go to its new folder (local path => production path)
$livePrefixes = array_map(fn ($id) => "files/company_$id/", $liveIds);
$devFiles = array_filter($devFiles, fn ($rel) => ! \Illuminate\Support\Str::startsWith($rel, $livePrefixes), ARRAY_FILTER_USE_KEY);
$remoteRel = fn (string $rel) => strtr($rel, $pathMap);
$filesToSend = [];
$sshCfg = is_file(base_path('.env.production-ssh')) ? Dotenv\Dotenv::parse(file_get_contents(base_path('.env.production-ssh'))) : null;
if ($devFiles && $sshCfg) {
    $sftp = new phpseclib3\Net\SFTP($sshCfg['PROD_SSH_HOST'], (int) $sshCfg['PROD_SSH_PORT'], 60);
    $sftp->setTimeout(600);
    if ($sftp->login($sshCfg['PROD_SSH_USER'], $sshCfg['PROD_SSH_PASSWORD'] ?? '')) {
        foreach ($devFiles as $rel => $size) {
            $st = $sftp->stat(rtrim($sshCfg['PROD_PATH'], '/').'/public/'.$remoteRel($rel));
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
    $remote = rtrim($sshCfg['PROD_PATH'], '/').'/public/'.$remoteRel($rel);
    $q = fn (string $s) => "'".str_replace("'", "'\\''", $s)."'";
    $sftp->exec('mkdir -p '.$q(dirname($remote)).' && (test -f '.$q($remote).' && cp -p '.$q($remote).' '.$q($remote.'.bak-'.date('Ymd-His')).' || true)');
    echo $sftp->put($remote, public_path($rel), phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE) ? "  file sent: $rel\n" : "  FILE FAILED: $rel\n";
}
if (! $plan && ! $replaceSets) { echo "Sent.\n"; return; }

// production backup first (compressed, phpMyAdmin-ready; shared helper)
$backupLabel = 'before_push';
if (! (require __DIR__.'/protocol_prod_backup.php')) { echo "Nothing sent to the production database.\n"; return; }

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
// Sent: the next push starts from here (never the same rows twice). Rows that got a new id
// differ between dev and production until the next murano:from-prod.
$maxIds = [];
foreach (collect($d->select("SELECT table_name t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'id'"))->pluck('t') as $t) $maxIds[$t] = (int) $d->table($t)->max('id');
file_put_contents($syncFile, json_encode(['pulled_at' => now()->format('Y-m-d H:i:s'), 'dev_max_ids' => $maxIds, 'by' => 'protocol_prod_push'] + ($remapped ? ['remapped_pending' => $remapped] : [])));
echo "Sent.\n", $remapped ? "$remapped row(s) went up with a new id: run `php artisan murano:from-prod` before working on them in dev.\n" : '';
