<?php

/*
 * Kelsy: write one kt_logs row (user's rule, 2026-10-20: the log lives in
 * PRODUCTION, so every PC sees the same history and ids never clash). Set $ktLog
 * (column => value; version / created_at / updated_at are filled in when left
 * out) before the require:
 *
 *   php artisan tinker --execute="$ktLog = [...]; require '<repo>/database/scripts/protocol_kt_log.php';"
 *
 * Production unreachable (no .env.production-db, network down) -> the row is
 * written to the local dbmurano instead; the next `php artisan murano:from-prod`
 * (Wake-up) sends it up before replacing the local database.
 */

use Illuminate\Support\Facades\DB;

$row = $ktLog ?? null;
if (! is_array($row) || empty($row['summary'])) {
    echo "Set \$ktLog = [...] (with at least summary) before the require.\n";

    return;
}
$now = now();
$row += ['log_date' => $now->toDateString(), 'status' => 'Completed', 'created_at' => $now, 'updated_at' => $now];

$where = 'local (production unreachable -- goes up with the next murano:from-prod)';
$conn = config('database.default');
try {
    ob_start();
    $prodEnv = require __DIR__.'/protocol_prod_conn.php';
    $msg = ob_get_clean();
    if ($prodEnv) {
        DB::connection('prod')->getPdo();
        $conn = 'prod';
        $where = 'production';
    } else {
        $where = 'local ('.trim($msg).')';
    }
} catch (\Throwable $e) {
    $conn = config('database.default');
}
$id = DB::connection($conn)->table('kt_logs')->insertGetId($row);
$version = DB::connection($conn)->table('kt_logs')->where('id', $id)->value('version');
echo "kt_logs #{$id} (v{$version}) written to {$where}\n";
