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
 *  - tables without `id`               -> reported only
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
$tablesOf = fn ($c) => collect($c->select("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"))->pluck('t')->all();
$colsOf = fn ($c, $t) => collect($c->select('SELECT column_name c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$t]))->pluck('c')->all();

echo 'production: ', $p->getDatabaseName(), ' -> dev: ', $d->getDatabaseName(), ($apply ? '  (APPLY)' : '  (dry run)'), PHP_EOL;

$plan = [];
$report = [];
foreach (array_diff(array_intersect($tablesOf($d), $tablesOf($p)), $skip) as $t) {
    $cp = $colsOf($p, $t);
    $c = array_values(array_intersect($cp, $colsOf($d, $t)));
    if (! in_array('id', $c)) {
        $nP = $p->table($t)->count(); $nD = $d->table($t)->count();
        if ($nP !== $nD) $report[] = "$t: no id column, production $nP rows / dev $nD (not copied)";
        continue;
    }
    $hash = 'MD5(CONCAT_WS("|", '.implode(', ', array_map(fn ($x) => "IFNULL(`$x`, '\\\\N')", $c)).'))';
    $hasU = in_array('updated_at', $c);
    $sel = "SELECT id, $hash h".($hasU ? ', updated_at u' : '')." FROM `$t`";
    $P = collect($p->select($sel))->keyBy('id');
    $D = collect($d->select($sel))->keyBy('id');

    $insert = $P->keys()->diff($D->keys())->values()->all();
    $update = []; $keptDev = [];
    foreach ($P as $id => $r) {
        if (! isset($D[$id]) || $D[$id]->h === $r->h) continue;
        if (! $hasU || (string) $r->u >= (string) $D[$id]->u) $update[] = $id; else $keptDev[] = $id;
    }
    $onlyDev = $D->keys()->diff($P->keys())->values()->all();
    if ($insert || $update) $plan[$t] = compact('c', 'insert', 'update');
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
    $backup[$t] = ['inserted_ids' => $x['insert'], 'replaced_rows' => $x['update'] ? $d->table($t)->whereIn('id', $x['update'])->get($x['c']) : []];
}
$file = $dir.'/dev_before_prod_pull_'.now()->format('Y-m-d_h-i-s-A').'.json';
file_put_contents($file, json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo PHP_EOL, 'backup of dev rows: ', $file, PHP_EOL;

$done = DB::transaction(function () use ($plan, $p, $d) {
    $d->statement('SET FOREIGN_KEY_CHECKS = 0');
    $n = [];
    foreach ($plan as $t => $x) {
        $ins = 0; $upd = 0;
        foreach (array_chunk(array_merge($x['insert'], $x['update']), 100) as $ids) {
            $rows = $p->table($t)->whereIn('id', $ids)->get($x['c']);
            foreach ($rows as $row) {
                $row = (array) $row;
                if (in_array($row['id'], $x['update'])) { $d->table($t)->where('id', $row['id'])->update($row); $upd++; }
                else { $d->table($t)->insert($row); $ins++; }
            }
        }
        $n[$t] = "inserted $ins, updated $upd";
    }
    $d->statement('SET FOREIGN_KEY_CHECKS = 1');
    return $n;
});
foreach ($done as $t => $s) echo "  $t: $s", PHP_EOL;
echo 'Done.', PHP_EOL;
