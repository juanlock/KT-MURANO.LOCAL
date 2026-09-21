<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Validates an uploaded update package and the environment before any
 * change is made. Every check is reported; any failed "critical" check
 * blocks the update.
 */
class PackageValidator
{
    /** PHP extensions the application cannot run without. */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'openssl', 'zip', 'ctype', 'json', 'curl', 'gd', 'fileinfo',
    ];

    /**
     * Entries the package must contain (relative to the package root) for
     * the update to be performable and resumable. The updater's own files
     * are mandatory: after the file swap the NEXT request runs the new
     * code, so the new code must still contain the updater.
     */
    private const REQUIRED_ENTRIES = [
        'version.txt',
        'artisan',
        'composer.json',
        'public/index.php',
        'vendor/autoload.php',
        'app/Services/Updater/UpdateManager.php',
        'app/Http/Controllers/SystemUpdateController.php',
    ];

    public function validate(string $zipPath): array
    {
        $checks = [];
        $package = ['size' => File::exists($zipPath) ? File::size($zipPath) : 0];

        // ---- ZIP integrity -------------------------------------------------
        $zip = new ZipArchive;
        $openResult = File::exists($zipPath) ? $zip->open($zipPath, ZipArchive::CHECKCONS) : false;
        $zipOk = $openResult === true;
        $checks[] = $this->check('zip_integrity', 'Package archive is a valid, consistent ZIP', $zipOk, 'critical',
            $zipOk ? null : 'The uploaded file is not a readable ZIP archive (code: '.var_export($openResult, true).'). Re-download it from CodeCanyon and upload again.');

        $entries = [];
        $rootPrefix = null;
        $forbidden = [];
        $traversal = [];
        $uncompressed = 0;

        if ($zipOk) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat || ! isset($stat['name'])) {
                    continue;
                }
                $name = str_replace('\\', '/', $stat['name']);
                $entries[] = $name;
                $uncompressed += (int) ($stat['size'] ?? 0);

                if (strpos($name, '..') !== false || $name[0] === '/' || preg_match('/^[a-zA-Z]:\//', $name)) {
                    $traversal[] = $name;
                }
            }

            $rootPrefix = $this->detectRootPrefix($entries);
            $prefixLen = $rootPrefix ? strlen($rootPrefix) : 0;

            foreach ($entries as $name) {
                $rel = $prefixLen && strpos($name, $rootPrefix) === 0 ? substr($name, $prefixLen) : $name;
                $lower = strtolower(rtrim($rel, '/'));
                if ($lower === '' ) {
                    continue;
                }
                if ($lower === '.env' || substr($lower, -5) === '/.env'
                    || strpos($lower, 'storage/') === 0 || $lower === 'storage'
                    || strpos($lower, 'public/images/') === 0 || $lower === 'public/images'
                    || strpos($lower, 'modules/') === 0 || $lower === 'modules_statuses.json') {
                    $forbidden[] = $rel;
                }
            }

            $package['files'] = count($entries);
            $package['uncompressed_size'] = $uncompressed;
            $package['root_prefix'] = $rootPrefix;

            $checks[] = $this->check('zip_no_traversal', 'No path traversal or absolute paths inside the package', empty($traversal), 'critical',
                empty($traversal) ? null : 'Unsafe entries: '.implode(', ', array_slice($traversal, 0, 5)));
            $checks[] = $this->check('zip_no_forbidden', 'Package does not contain protected files (.env, storage, uploads, Modules)', empty($forbidden), 'critical',
                empty($forbidden) ? null : 'Forbidden entries: '.implode(', ', array_slice($forbidden, 0, 5)));

            // ---- required structure ----------------------------------------
            $missing = [];
            foreach (self::REQUIRED_ENTRIES as $required) {
                if ($zip->locateName(($rootPrefix ?? '').$required) === false) {
                    $missing[] = $required;
                }
            }
            $structureOk = empty($missing);
            $checks[] = $this->check('package_structure', 'Package contains a complete application (vendor, updater, public assets)', $structureOk, 'critical',
                $structureOk ? null : 'Missing from package: '.implode(', ', $missing).'. Upload the FULL application ZIP from CodeCanyon — not a partial/patch archive.');

            // ---- version ----------------------------------------------------
            $packageVersion = null;
            $idx = $zip->locateName(($rootPrefix ?? '').'version.txt');
            if ($idx !== false) {
                $packageVersion = trim((string) $zip->getFromIndex($idx));
            }
            $currentVersion = $this->currentVersion();
            $package['version'] = $packageVersion;
            $package['current_version'] = $currentVersion;
            $versionOk = $packageVersion && $currentVersion && version_compare($packageVersion, $currentVersion, '>');
            $checks[] = $this->check('version_compatibility',
                'Package version ('.($packageVersion ?: 'unknown').') is newer than installed ('.($currentVersion ?: 'unknown').')',
                (bool) $versionOk, 'critical',
                $versionOk ? null : ($packageVersion && $currentVersion && version_compare($packageVersion, $currentVersion, '=')
                    ? 'This version is already installed.'
                    : 'Downgrades are not supported. Only a package newer than the installed version can be applied.'));

            // ---- PHP requirement from the package's composer.json ----------
            $phpConstraintOk = true;
            $phpDetail = null;
            $composerIdx = $zip->locateName(($rootPrefix ?? '').'composer.json');
            if ($composerIdx !== false) {
                $composer = json_decode((string) $zip->getFromIndex($composerIdx), true);
                $constraint = $composer['require']['php'] ?? null;
                if ($constraint && preg_match('/(\d+\.\d+)/', $constraint, $m)) {
                    $phpConstraintOk = version_compare(PHP_VERSION, $m[1], '>=');
                    $phpDetail = $phpConstraintOk ? null : 'This package requires PHP '.$constraint.'; server runs '.PHP_VERSION.'.';
                }
            }
            $checks[] = $this->check('php_version', 'PHP version '.PHP_VERSION.' satisfies the package requirement', $phpConstraintOk, 'critical', $phpDetail);

            $zip->close();
        }

        // ---- PHP extensions ------------------------------------------------
        $missingExt = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn ($e) => ! extension_loaded($e)));
        $checks[] = $this->check('php_extensions', 'Required PHP extensions are loaded', empty($missingExt), 'critical',
            empty($missingExt) ? null : 'Missing extensions: '.implode(', ', $missingExt));

        // ---- write permissions --------------------------------------------
        $unwritable = [];
        foreach ([base_path(), app_path(), base_path('bootstrap/cache'), base_path('routes'),
            base_path('vendor'), public_path(), storage_path(), storage_path('app'), storage_path('framework')] as $p) {
            if (File::exists($p) && ! is_writable($p)) {
                $unwritable[] = $p;
            }
        }
        $checks[] = $this->check('permissions', 'Application directories are writable by PHP', empty($unwritable), 'critical',
            empty($unwritable) ? null : 'Not writable: '.implode(', ', $unwritable));

        // ---- disk space ----------------------------------------------------
        // Needs: extracted copy (staging) + swap headroom + DB dump + backup zip.
        $zipSize = $package['size'];
        $needed = (int) (($package['uncompressed_size'] ?? $zipSize * 3) + $zipSize * 2 + 200 * 1024 * 1024);
        $free = @disk_free_space(storage_path('app'));
        $diskOk = $free === false ? true : $free > $needed;
        $checks[] = $this->check('disk_space', 'Enough free disk space ('.$this->fmtBytes((float) $free).' free, ~'.$this->fmtBytes($needed).' needed)', $diskOk, 'critical',
            $diskOk ? null : 'Free up disk space before updating.');

        // ---- database ------------------------------------------------------
        $dbOk = false;
        $dbDetail = null;
        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbDetail = $e->getMessage();
        }
        $checks[] = $this->check('database', 'Database connection is working', $dbOk, 'critical', $dbDetail);

        // ---- configuration -------------------------------------------------
        $configOk = File::exists(base_path('.env')) && (string) config('app.key') !== '';
        $checks[] = $this->check('configuration', '.env exists and application key is set', $configOk, 'critical',
            $configOk ? null : 'The .env file or APP_KEY is missing.');

        // ---- maintenance mode ----------------------------------------------
        $downDir = storage_path('framework');
        $maintenanceOk = is_writable($downDir);
        $checks[] = $this->check('maintenance_mode', 'Maintenance mode can be enabled (storage/framework writable)', $maintenanceOk, 'critical', null);

        // ---- concurrent update ---------------------------------------------
        $stateService = new UpdateState;
        $notRunning = ! $stateService->isActive();
        $checks[] = $this->check('no_update_running', 'No other update is currently running', $notRunning, 'critical',
            $notRunning ? null : 'An update is currently in progress. Please wait until it has finished.');

        // ---- warnings (non-blocking) ---------------------------------------
        $checks[] = $this->check('exec_available', 'Shell access available for fast native DB dump (falls back to PHP dump if not)',
            function_exists('exec'), 'warning', null);

        $criticalFailed = array_values(array_filter($checks, fn ($c) => ! $c['ok'] && $c['level'] === 'critical'));

        return [
            'ok' => empty($criticalFailed),
            'checks' => $checks,
            'package' => $package,
        ];
    }

    public function currentVersion(): ?string
    {
        try {
            if (File::exists(base_path('version.txt'))) {
                return trim((string) File::get(base_path('version.txt')));
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** If every entry lives under one top-level folder, return "folder/". */
    public function detectRootPrefix(array $entries): ?string
    {
        $top = null;
        foreach ($entries as $name) {
            $slash = strpos($name, '/');
            $first = $slash === false ? null : substr($name, 0, $slash + 1);
            if ($first === null) {
                return null; // file at archive root — no prefix
            }
            if ($top === null) {
                $top = $first;
            } elseif ($top !== $first) {
                return null;
            }
        }

        return $top;
    }

    private function check(string $key, string $label, bool $ok, string $level, ?string $detail = null): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'level' => $level, 'detail' => $detail];
    }

    private function fmtBytes(float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }
}
