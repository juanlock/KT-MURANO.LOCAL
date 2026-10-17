<?php

// Protocol Sincroniza BD -- COMPARE ONLY (read-only on both sides).
//
// Dev = this machine's Laravel connection (dbmurano). Production = the database
// in .env.production-db at the repo root (per machine, git-ignored -- never in
// .env, never committed). Reports:
//   - schema: tables only in dev / only in prod; per common table, columns
//     added / removed / changed (type, null, default, extra) and indexes;
//   - migrations: run in dev but not in prod (= prod is behind), and the reverse;
//   - data: per table, row count, MAX(id), MAX(updated_at) on both sides and,
//     for tables up to $checksumMaxRows rows with the same count, CHECKSUM TABLE
//     (equal / different).
// Writes KT-TOOLS/Report/kt_murano_sincroniza_bd_<date>.html (no secrets) and
// prints a short summary. Updating production is a separate, confirmed step
// (see .claude/commands/sincroniza-bd.md) -- this script never writes to it.
//
// Run: php artisan tinker --execute="require '<repo>\database\scripts\protocol_sincroniza_bd.php';"

use Illuminate\Support\Facades\DB;

$checksumMaxRows = $checksumMaxRows ?? 20000;

// ---------------- production credentials ----------------
$credFile = base_path('.env.production-db');
if (! is_file($credFile)) {
    echo "ERROR: .env.production-db not found at the repo root. Create it on this machine (it is git-ignored):\n";
    echo "PROD_DB_HOST=..., PROD_DB_PORT=3306, PROD_DB_DATABASE=..., PROD_DB_USERNAME=..., PROD_DB_PASSWORD=\"...\"\n";
    return;
}
$cred = [];
foreach (file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*(PROD_DB_[A-Z]+)\s*=\s*(.*)\s*$/', $line, $m)) {
        $v = trim($m[2]);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $cred[$m[1]] = $v;
    }
}
$prodDb = $cred['PROD_DB_DATABASE'] ?? '';
try {
    $prod = new PDO(
        'mysql:host='.($cred['PROD_DB_HOST'] ?? '').';port='.($cred['PROD_DB_PORT'] ?? 3306).';dbname='.$prodDb.';charset=utf8mb4',
        $cred['PROD_DB_USERNAME'] ?? '', $cred['PROD_DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    // Never echo the password; the PDO message doesn't contain it.
    echo 'ERROR: cannot connect to production ('.($cred['PROD_DB_HOST'] ?? '?').' / '.$prodDb.'): '.$e->getMessage()."\n";
    echo "Hostinger: hPanel -> Databases -> Remote MySQL must allow this machine's public IP.\n";
    return;
}
$devDb = DB::connection()->getDatabaseName();
$dev = DB::connection()->getPdo();
$dev->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// Read TIMESTAMP columns in the same zone on both servers (they run in
// different zones: the same row showed 4 hours apart and looked "newer").
$devTz = $dev->query('SELECT @@session.time_zone')->fetchColumn();
$dev->exec("SET time_zone = '+00:00'");
$prod->exec("SET time_zone = '+00:00'");

$q = function (PDO $pdo, string $sql, array $b = []) {
    $st = $pdo->prepare($sql);
    $st->execute($b);

    return $st->fetchAll();
};

// ---------------- schema ----------------
$tablesOf = fn (PDO $pdo, string $db) => array_column($q($pdo, "SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$db]), 't');
$devTables = $tablesOf($dev, $devDb);
$prodTables = $tablesOf($prod, $prodDb);
$onlyDev = array_values(array_diff($devTables, $prodTables));
$onlyProd = array_values(array_diff($prodTables, $devTables));
$common = array_values(array_intersect($devTables, $prodTables));

$colsOf = function (PDO $pdo, string $db) use ($q) {
    $out = [];
    foreach ($q($pdo, 'SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty, IS_NULLABLE n, COLUMN_DEFAULT d, EXTRA x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$db]) as $r) {
        $out[$r['t']][$r['c']] = $r['ty'].($r['n'] === 'YES' ? ' NULL' : ' NOT NULL').($r['d'] !== null ? ' DEFAULT '.$r['d'] : '').($r['x'] ? ' '.$r['x'] : '');
    }

    return $out;
};
$idxOf = function (PDO $pdo, string $db) use ($q) {
    $out = [];
    foreach ($q($pdo, 'SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE u, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE', [$db]) as $r) {
        $out[$r['t']][$r['i']] = ($r['u'] ? 'INDEX' : 'UNIQUE').' ('.$r['cols'].')';
    }

    return $out;
};
$devCols = $colsOf($dev, $devDb);
$prodCols = $colsOf($prod, $prodDb);
$devIdx = $idxOf($dev, $devDb);
$prodIdx = $idxOf($prod, $prodDb);

$schemaDiffs = [];
foreach ($common as $t) {
    $d = $devCols[$t] ?? [];
    $p = $prodCols[$t] ?? [];
    $rows = [];
    foreach (array_diff_key($d, $p) as $c => $def) $rows[] = ['column only in dev', $c, $def, ''];
    foreach (array_diff_key($p, $d) as $c => $def) $rows[] = ['column only in prod', $c, '', $def];
    foreach (array_intersect_key($d, $p) as $c => $def) if ($def !== $p[$c]) $rows[] = ['column differs', $c, $def, $p[$c]];
    $di = $devIdx[$t] ?? [];
    $pi = $prodIdx[$t] ?? [];
    foreach (array_diff_key($di, $pi) as $i => $def) $rows[] = ['index only in dev', $i, $def, ''];
    foreach (array_diff_key($pi, $di) as $i => $def) $rows[] = ['index only in prod', $i, '', $def];
    foreach (array_intersect_key($di, $pi) as $i => $def) if ($def !== $pi[$i]) $rows[] = ['index differs', $i, $def, $pi[$i]];
    if ($rows) $schemaDiffs[$t] = $rows;
}

// ---------------- migrations ----------------
$migs = fn (PDO $pdo, array $tables) => in_array('migrations', $tables, true) ? array_column($q($pdo, 'SELECT migration FROM migrations'), 'migration') : [];
$devMigs = $migs($dev, $devTables);
$prodMigs = $migs($prod, $prodTables);
$pendingOnProd = array_values(array_diff($devMigs, $prodMigs));
sort($pendingOnProd);
$onlyProdMigs = array_values(array_diff($prodMigs, $devMigs));
sort($onlyProdMigs);

// ---------------- data ----------------
$stat = function (PDO $pdo, string $t, array $cols) use ($q) {
    $sel = ['COUNT(*) n'];
    if (isset($cols['id'])) $sel[] = 'MAX(id) maxid';
    if (isset($cols['updated_at'])) $sel[] = 'MAX(updated_at) upd';

    return $q($pdo, 'SELECT '.implode(', ', $sel).' FROM `'.str_replace('`', '', $t).'`')[0] ?? [];
};
$checksum = fn (PDO $pdo, string $t) => (string) ($q($pdo, 'CHECKSUM TABLE `'.str_replace('`', '', $t).'`')[0]['Checksum'] ?? '');

$dataRows = [];
$dataDiffCount = 0;
foreach ($common as $t) {
    $cols = array_intersect_key($devCols[$t] ?? [], $prodCols[$t] ?? []);
    $sd = $stat($dev, $t, $cols);
    $sp = $stat($prod, $t, $cols);
    $same = (int) ($sd['n'] ?? 0) === (int) ($sp['n'] ?? 0) && ($sd['maxid'] ?? null) == ($sp['maxid'] ?? null) && ($sd['upd'] ?? null) == ($sp['upd'] ?? null);
    $check = '';
    if ($same && (int) ($sd['n'] ?? 0) > 0 && (int) $sd['n'] <= $checksumMaxRows && ! isset($schemaDiffs[$t])) {
        $check = $checksum($dev, $t) === $checksum($prod, $t) ? 'equal' : 'different';
        if ($check === 'different') $same = false;
    } elseif ($same && (int) ($sd['n'] ?? 0) > $checksumMaxRows) {
        $check = 'not checked (large)';
    }
    if (! $same) $dataDiffCount++;
    $dataRows[] = ['t' => $t, 'same' => $same, 'dev' => $sd, 'prod' => $sp, 'check' => $check];
}

// ---------------- report ----------------
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$now = now();
$css = 'body{font:14px/1.5 system-ui,Segoe UI,sans-serif;margin:24px;color:#1d1b33;background:#f6f6fb}h1{font-size:22px}h2{font-size:17px;margin-top:28px}
table{border-collapse:collapse;background:#fff;width:100%;margin:8px 0}th,td{border:1px solid #e3e2ef;padding:5px 8px;text-align:left;vertical-align:top;font-size:13px}
th{background:#eeecfd}.ok{color:#157a4b}.bad{color:#b42318;font-weight:600}.muted{color:#5d5a78}.pill{display:inline-block;padding:1px 8px;border-radius:999px;background:#eeecfd;margin:2px}';
$html = "<!doctype html><html><head><meta charset='utf-8'><title>Sincroniza BD {$now->format('Y-m-d')}</title><style>$css</style></head><body>";
$html .= "<h1>Sincroniza BD — dev vs production</h1><p class='muted'>Dev: <b>{$h($devDb)}</b> (this machine) · Production: <b>{$h($prodDb)}</b> · {$now->format('Y-m-d g:i A')} · read-only comparison, nothing was changed.</p>";
$html .= '<h2>Summary</h2><ul>'
    .'<li>Tables only in dev: <b>'.count($onlyDev).'</b> · only in production: <b>'.count($onlyProd).'</b> · in both: '.count($common).'</li>'
    .'<li>Tables whose structure differs: <b>'.count($schemaDiffs).'</b></li>'
    .'<li>Migrations run in dev but not in production: <b>'.count($pendingOnProd).'</b> · only in production: <b>'.count($onlyProdMigs).'</b></li>'
    .'<li>Tables whose data differs: <b>'.$dataDiffCount.'</b></li></ul>';
$list = fn ($a) => $a ? implode('', array_map(fn ($x) => "<span class='pill'>{$h($x)}</span>", $a)) : '<span class="muted">none</span>';
$html .= '<h2>Tables only in dev</h2><p>'.$list($onlyDev).'</p><h2>Tables only in production</h2><p>'.$list($onlyProd).'</p>';
$html .= '<h2>Migrations run in dev, not in production</h2><p>'.$list($pendingOnProd).'</p><h2>Migrations only in production</h2><p>'.$list($onlyProdMigs).'</p>';
$html .= '<h2>Structure differences</h2>';
if (! $schemaDiffs) $html .= '<p class="muted">none</p>';
foreach ($schemaDiffs as $t => $rows) {
    $html .= "<h3>{$h($t)}</h3><table><tr><th>What</th><th>Name</th><th>Dev</th><th>Production</th></tr>";
    foreach ($rows as $r) $html .= "<tr><td>{$h($r[0])}</td><td>{$h($r[1])}</td><td>{$h($r[2])}</td><td>{$h($r[3])}</td></tr>";
    $html .= '</table>';
}
$html .= '<h2>Data (tables in both)</h2><table><tr><th>Table</th><th>Rows dev</th><th>Rows prod</th><th>Max id dev / prod</th><th>Last update dev / prod</th><th>Checksum</th><th></th></tr>';
usort($dataRows, fn ($a, $b) => [$a['same'], $a['t']] <=> [$b['same'], $b['t']]);
foreach ($dataRows as $r) {
    $html .= '<tr><td>'.$h($r['t']).'</td><td>'.$h($r['dev']['n'] ?? '').'</td><td>'.$h($r['prod']['n'] ?? '').'</td><td>'.$h(($r['dev']['maxid'] ?? '-').' / '.($r['prod']['maxid'] ?? '-')).'</td><td>'.$h(($r['dev']['upd'] ?? '-').' / '.($r['prod']['upd'] ?? '-')).'</td><td>'.$h($r['check']).'</td><td class="'.($r['same'] ? 'ok">same' : 'bad">differs').'</td></tr>';
}
$html .= '</table></body></html>';

$reportPath = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'Report'.DIRECTORY_SEPARATOR.'kt_murano_sincroniza_bd_'.$now->format('Y-m-d').'.html');
file_put_contents($reportPath, $html);

// ---------------- console summary ----------------
echo "Sincroniza BD: dev {$devDb} vs production {$prodDb} (read-only)\n";
echo 'Tables only in dev ('.count($onlyDev).'): '.implode(', ', array_slice($onlyDev, 0, 30)).(count($onlyDev) > 30 ? ' ...' : '')."\n";
echo 'Tables only in prod ('.count($onlyProd).'): '.implode(', ', array_slice($onlyProd, 0, 30)).(count($onlyProd) > 30 ? ' ...' : '')."\n";
echo 'Structure differs ('.count($schemaDiffs).'): '.implode(', ', array_slice(array_keys($schemaDiffs), 0, 30)).(count($schemaDiffs) > 30 ? ' ...' : '')."\n";
echo 'Migrations pending on prod ('.count($pendingOnProd).'): '.implode(', ', array_slice($pendingOnProd, 0, 15)).(count($pendingOnProd) > 15 ? ' ...' : '')."\n";
echo 'Migrations only on prod ('.count($onlyProdMigs).")\n";
echo "Data differs in {$dataDiffCount} of ".count($common)." tables\n";
echo "Report: {$reportPath}\n";
// Give the app's shared connection its own time zone back.
$dev->exec('SET time_zone = '.$dev->quote((string) $devTz));
