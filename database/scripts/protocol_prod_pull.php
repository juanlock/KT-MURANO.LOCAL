<?php

/*
 * Protocol Pa Llevar -- step "production -> dev": brings production's new and
 * changed records into dev (dbmurano). Production is only READ.
 *
 * Run (dry run first, it only reports):
 *   php artisan tinker --execute="$apply = false; require '<repo>/database/scripts/protocol_prod_pull.php';"
 *   php artisan tinker --execute="$apply = true;  require '<repo>/database/scripts/protocol_prod_pull.php';"
 *
 * Production access comes from .env.production-db (per machine, git-ignored):
 * PROD_DB_HOST / PROD_DB_PORT / PROD_DB_DATABASE / PROD_DB_USERNAME / PROD_DB_PASSWORD.
 *
 * Rules, per table with an `id` (common columns only):
 *  - row only in production            -> inserted in dev
 *  - row in both, content differs      -> production's copy wins when its updated_at is
 *                                         newer (or the table has no updated_at);
 *                                         when dev's is newer, dev is kept and reported
 *  - row only in dev                   -> kept, reported (may clash with production's next ids)
 *  - tables without `id` (pivots)      -> production rows dev lacks (whole row) added
 *  - $naturalKeys tables (menu_positions, rebuilt with new ids) -> matched by
 *    company_id + item_key: only items dev lacks are added (never a 2nd menu)
 * Never touched: migrations, kt_logs (dev's history), queue / cache / session tables.
 * TIMESTAMP columns are compared in UTC on both sides (session time_zone +00:00).
 * Everything is written in ONE transaction; the dev rows it replaces and the ids it
 * inserts are saved first to KT-TOOLS/database/production/dev_before_prod_pull_<stamp>.json
 * (git-ignored).
 */

use Illuminate\Support\Facades\DB;

$apply = $apply ?? false;
if (! require __DIR__.'/protocol_prod_conn.php') return;
$p = DB::connection('prod');
$d = DB::connection();
$p->statement("SET time_zone = '+00:00'");
$d->statement("SET time_zone = '+00:00'");

$skip = ['migrations', 'kt_logs', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens'];
// Tables whose rows are rebuilt with NEW ids (the menu is regenerated per company):
// matched by these columns, not by id -- by id every menu would come in twice.
$naturalKeys = ['menu_positions' => ['company_id', 'item_key']];
// Child rows rewritten with NEW ids whenever their parent is saved (editing a
// role replaces all its permission_role rows): they follow the side that edited
// the parent last -- a role edited in dev keeps dev's permissions -- and an
// existing pair is never inserted twice.
$parentWins = ['permission_role' => ['roles', 'role_id', ['role_id', 'permission_id']]];
$tablesOf = fn ($c) => collect($c->select("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"))->pluck('t')->all();
$colsOf = fn ($c, $t) => collect($c->select('SELECT column_name c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$t]))->pluck('c')->all();

echo 'production: ', $p->getDatabaseName(), ' -> dev: ', $d->getDatabaseName(), ($apply ? '  (APPLY)' : '  (dry run)'), PHP_EOL;

$plan = [];
$report = [];
foreach (array_diff(array_intersect($tablesOf($d), $tablesOf($p)), $skip) as $t) {
    $cp = $colsOf($p, $t);
    $c = array_values(array_intersect($cp, $colsOf($d, $t)));
    $hash = 'MD5(CONCAT_WS("|", '.implode(', ', array_map(fn ($x) => "IFNULL(`$x`, '\\\\N')", $c)).'))';
    // No id (pivot tables: user_warehouse, employee_project...): production rows
    // dev doesn't have (whole row compared) are added; nothing removed.
    if (! in_array('id', $c)) {
        if ($p->table($t)->count() > 50000) { $report[] = "$t: no id column, too big to compare (not copied)"; continue; }
        $have = collect($d->select("SELECT $hash h FROM `$t`"))->pluck('h')->countBy()->all();
        $rows = [];
        foreach ($p->select("SELECT $hash h, `$t`.* FROM `$t`") as $r) {
            if (($have[$r->h] ?? 0) > 0) { $have[$r->h]--; continue; }
            $rows[] = array_intersect_key((array) $r, array_flip($c));
        }
        if ($rows) { $plan[$t] = ['c' => $c, 'rows' => $rows, 'insert' => [], 'update' => []]; $report[] = "$t: no id column, new rows ".count($rows); }
        continue;
    }
    // Natural key: new = a (company, item) dev doesn't have; dev's own id kept for the rest.
    if (isset($naturalKeys[$t]) && ! array_diff($naturalKeys[$t], $c)) {
        $k = $naturalKeys[$t];
        $keyOf = fn ($r) => implode('|', array_map(fn ($x) => (string) $r->$x, $k));
        $devKeys = collect($d->table($t)->get($k))->map($keyOf)->flip();
        $devIds = $d->table($t)->pluck('id')->flip();
        $new = collect($p->table($t)->get(array_merge(['id'], $k)))->reject(fn ($r) => isset($devKeys[$keyOf($r)]))->pluck('id')->all();
        // Same item in both, content changed in production later (a reordered
        // menu): production's values (all but id / created_at) win.
        $same = array_values(array_diff($c, ['id', 'created_at']));
        $hashN = 'MD5(CONCAT_WS("|", '.implode(', ', array_map(fn ($x) => "IFNULL(`$x`, '\\\\N')", array_diff($same, ['updated_at']))).'))';
        $selN = 'SELECT '.implode(', ', array_map(fn ($x) => "`$x`", $k)).", $hashN h".(in_array('updated_at', $c) ? ', updated_at u' : '')." FROM `$t`";
        $Dn = collect($d->select($selN))->keyBy($keyOf);
        $changedKeys = [];
        foreach ($p->select($selN) as $r) {
            $key = $keyOf($r);
            if (isset($Dn[$key]) && $Dn[$key]->h !== $r->h && (string) ($r->u ?? '') >= (string) ($Dn[$key]->u ?? '')) $changedKeys[] = $key;
        }
        if ($new || $changedKeys) {
            $plan[$t] = ['c' => $c, 'insert' => $new, 'update' => [], 'freshId' => array_values(array_filter($new, fn ($id) => isset($devIds[$id]))),
                'byKey' => ['cols' => $k, 'set' => $same, 'keys' => $changedKeys]];
        }
        $report[] = "$t: matched by ".implode(' + ', $k).' -- new '.count($new).($new ? ' ['.implode(',', array_slice($new, 0, 10)).(count($new) > 10 ? ',...' : '').']' : '')
            .', changed in production '.count($changedKeys);
        continue;
    }
    $hasU = in_array('updated_at', $c);
    $sel = "SELECT id, $hash h".($hasU ? ', updated_at u' : '')." FROM `$t`";
    $P = collect($p->select($sel))->keyBy('id');
    $D = collect($d->select($sel))->keyBy('id');

    $insert = $P->keys()->diff($D->keys())->values()->all();
    $skippedByParent = 0;
    if (isset($parentWins[$t]) && $insert) {
        [$pt, $fk, $pair] = $parentWins[$t];
        $pu = collect($p->table($pt)->get(['id', 'updated_at']))->pluck('updated_at', 'id');
        $du = collect($d->table($pt)->get(['id', 'updated_at']))->pluck('updated_at', 'id');
        $devPairs = collect($d->table($t)->get($pair))->map(fn ($r) => implode('-', array_map(fn ($c) => $r->$c, $pair)))->flip();
        $rows = $p->table($t)->whereIn('id', $insert)->get(array_merge(['id'], $pair))->keyBy('id');
        $insert = array_values(array_filter($insert, function ($id) use ($rows, $fk, $pair, $pu, $du, $devPairs) {
            $r = $rows[$id] ?? null;
            if (! $r) return false;
            $parent = $r->$fk;
            if (isset($du[$parent]) && (string) $du[$parent] > (string) ($pu[$parent] ?? '')) return false; // parent edited in dev last
            return ! isset($devPairs[implode('-', array_map(fn ($c) => $r->$c, $pair))]);              // pair already in dev
        }));
        $skippedByParent = $rows->count() - count($insert);
    }
    $update = []; $keptDev = [];
    foreach ($P as $id => $r) {
        if (! isset($D[$id]) || $D[$id]->h === $r->h) continue;
        if (! $hasU || (string) $r->u >= (string) $D[$id]->u) $update[] = $id; else $keptDev[] = $id;
    }
    $onlyDev = $D->keys()->diff($P->keys())->values()->all();
    if ($insert || $update) $plan[$t] = compact('c', 'insert', 'update');
    if ($skippedByParent) $report[] = "$t: $skippedByParent production rows skipped (their parent was edited in dev later, or the pair is already in dev)";
    if ($insert || $update || $keptDev || $onlyDev) {
        $report[] = sprintf('%s: new %d%s, updated %d%s%s%s', $t,
            count($insert), $insert ? ' ['.implode(',', array_slice($insert, 0, 10)).(count($insert) > 10 ? ',...' : '').']' : '',
            count($update), $update ? ' ['.implode(',', array_slice($update, 0, 10)).(count($update) > 10 ? ',...' : '').']' : '',
            $keptDev ? ', dev newer (kept) '.count($keptDev).' ['.implode(',', array_slice($keptDev, 0, 10)).']' : '',
            $onlyDev ? ', only in dev '.count($onlyDev).' ['.implode(',', array_slice($onlyDev, 0, 10)).']' : '');
    }
}
echo implode(PHP_EOL, $report ?: ['dev already matches production']), PHP_EOL;

if (! $apply || ! $plan) {
    if (! $apply && $plan) echo PHP_EOL, 'Dry run -- nothing written. Run again with $apply = true.', PHP_EOL;
    return;
}

// backup of what dev is about to change
$dir = base_path('KT-TOOLS/database/production');
if (! is_dir($dir)) mkdir($dir, 0775, true);
$backup = [];
foreach ($plan as $t => $x) {
    $backup[$t] = isset($x['rows'])
        ? ['inserted_rows' => $x['rows']]
        : ['inserted_ids' => $x['insert'], 'replaced_rows' => $x['update'] ? $d->table($t)->whereIn('id', $x['update'])->get($x['c']) : []];
    if (! empty($x['byKey']['keys'])) {
        $backup[$t]['replaced_by_key'] = $d->table($t)->get($x['c'])->filter(fn ($r) => in_array(implode('|', array_map(fn ($col) => (string) $r->$col, $x['byKey']['cols'])), $x['byKey']['keys'], true))->values();
    }
}
$file = $dir.'/dev_before_prod_pull_'.now()->format('Y-m-d_h-i-s-A').'.json';
file_put_contents($file, json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo PHP_EOL, 'backup of dev rows: ', $file, PHP_EOL;

$done = DB::transaction(function () use ($plan, $p, $d) {
    $d->statement('SET FOREIGN_KEY_CHECKS = 0');
    $n = [];
    foreach ($plan as $t => $x) {
        $ins = 0; $upd = 0;
        foreach (array_chunk($x['rows'] ?? [], 200) as $chunk) {
            $d->table($t)->insert($chunk);
            $ins += count($chunk);
        }
        if (! empty($x['byKey']['keys'])) {
            $kc = $x['byKey']['cols'];
            $want = array_flip($x['byKey']['keys']);
            foreach ($p->table($t)->get($x['byKey']['set']) as $r) {
                $key = implode('|', array_map(fn ($col) => (string) $r->$col, $kc));
                if (! isset($want[$key])) continue;
                $q = $d->table($t);
                foreach ($kc as $col) $q->where($col, $r->$col);
                $q->update(array_diff_key((array) $r, array_flip($kc)));
                $upd++;
            }
        }
        $fresh = array_flip($x['freshId'] ?? []);
        foreach (array_chunk(array_merge($x['insert'], $x['update']), 100) as $ids) {
            $rows = $p->table($t)->whereIn('id', $ids)->get($x['c']);
            foreach ($rows as $row) {
                $row = (array) $row;
                if (in_array($row['id'], $x['update'])) { $d->table($t)->where('id', $row['id'])->update($row); $upd++; }
                else {
                    if (isset($fresh[$row['id']])) unset($row['id']); // that id is taken in dev: a new one
                    $d->table($t)->insert($row); $ins++;
                }
            }
        }
        $n[$t] = "inserted $ins, updated $upd";
    }
    $d->statement('SET FOREIGN_KEY_CHECKS = 1');
    return $n;
});
foreach ($done as $t => $s) echo "  $t: $s", PHP_EOL;
// the reference point for the data step of "update production" (Wake-up / Pa Llevar): dev changes after this go up.
// dev_max_ids = each table's highest dev id right now: a dev row with a higher id was created in dev AFTER this
// pull, whatever its created_at says (demo data is back-dated; permission_role has no timestamps) -- 2026-10-20.
$devMax = [];
foreach (collect($d->select("SELECT table_name t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'id'"))->pluck('t') as $t) {
    $devMax[$t] = (int) $d->table($t)->max('id');
}
file_put_contents(base_path('KT-TOOLS/database/production/last_sync.json'), json_encode(['pulled_at' => now()->format('Y-m-d H:i:s'), 'dev_max_ids' => $devMax]));
echo 'Done.', PHP_EOL;
