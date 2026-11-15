<?php

/*
 * Kelsy: attach files to a kt_logs row (user's request, 2026-11-15: "si ese cambio te pidió un archivo, agrégalo a ese
 * record"). Copies each file to public/files/company_999/kt-logs/{id}/ on this PC AND on production (SFTP), and appends
 * the paths to the row's `attachments` (JSON). Used by protocol_kt_log.php ($ktLog['attachments'] = [local paths]) or alone:
 *
 *   php artisan tinker --execute="$ktAttachId = 2027; $ktAttachFiles = ['D:\...\report.html']; require '<repo>/database/scripts/protocol_kt_attach.php';"
 *
 * The folder is private (.htaccess): the files are served only by KtLogController::attachment (SuperAdmin on AdminSystem).
 */

use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use phpseclib3\Net\SFTP;

$id = (int) ($ktAttachId ?? 0);
$files = array_values(array_filter((array) ($ktAttachFiles ?? []), 'is_file'));
if (! $id || ! $files) {
    echo "Set \$ktAttachId and \$ktAttachFiles (existing files) before the require.\n";

    return;
}

$rel = CompanyFiles::dir(999, 'kt-logs', true).'/'.$id;   // files/company_999/kt-logs/{id}
@mkdir(public_path($rel), 0775, true);
$paths = [];
foreach ($files as $f) {
    $name = preg_replace('/[^A-Za-z0-9_\-. ]/', '_', basename($f));
    copy($f, public_path($rel.'/'.$name));
    $paths[] = $rel.'/'.$name;
}

// production: the same files at the same place
$sent = 0;
$envFile = base_path('.env.production-ssh');
if (is_file($envFile)) {
    $cfg = Dotenv\Dotenv::parse(file_get_contents($envFile));
    $sftp = new SFTP($cfg['PROD_SSH_HOST'], (int) ($cfg['PROD_SSH_PORT'] ?? 22), 60);
    if ($sftp->login($cfg['PROD_SSH_USER'], $cfg['PROD_SSH_PASSWORD'] ?? '')) {
        $root = rtrim($cfg['PROD_PATH'], '/').'/public/';
        $q = fn (string $s) => "'".str_replace("'", "'\\''", $s)."'";
        $sftp->exec('mkdir -p '.$q($root.$rel));
        // the private folder's .htaccess too
        $ht = public_path(CompanyFiles::dir(999, 'kt-logs', true).'/.htaccess');
        if (is_file($ht)) {
            $sftp->put($root.CompanyFiles::dir(999, 'kt-logs', true).'/.htaccess', $ht, SFTP::SOURCE_LOCAL_FILE);
        }
        foreach ($paths as $p) {
            $sent += $sftp->put($root.$p, public_path($p), SFTP::SOURCE_LOCAL_FILE) ? 1 : 0;
        }
    }
}

// the row (production when reachable, like protocol_kt_log.php)
$conn = config('database.default');
try {
    ob_start();
    $prodEnv = require __DIR__.'/protocol_prod_conn.php';
    ob_get_clean();
    if ($prodEnv) {
        DB::connection('prod')->getPdo();
        $conn = 'prod';
    }
} catch (\Throwable $e) {
    $conn = config('database.default');
}
$old = json_decode((string) DB::connection($conn)->table('kt_logs')->where('id', $id)->value('attachments'), true) ?: [];
$all = array_values(array_unique(array_merge($old, $paths)));
DB::connection($conn)->table('kt_logs')->where('id', $id)->update(['attachments' => json_encode($all), 'updated_at' => now()]);
echo "kt_logs #{$id}: ".count($paths)." file(s) attached (production copies: {$sent}) on ".($conn === 'prod' ? 'production' : 'local')."\n";
