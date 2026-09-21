<?php

namespace App\Services\Updater;

use App\Http\Middleware\CheckForMaintenanceMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The update state machine.
 *
 * The whole update is a sequence of short, resumable phases persisted in
 * storage/app/updater/state.json. The admin UI drives it by calling step()
 * repeatedly; each call performs at most ONE phase (or one chunk of a
 * chunked phase) within a small time budget and returns. This survives PHP
 * time limits, PHP-FPM restarts, closed browsers and server crashes — the
 * state always records exactly where the run stopped, and any failure at or
 * after the file swap triggers the automatic rollback phases.
 *
 * Each phase runs in its own HTTP request on purpose: once the file swap
 * has happened, the next request executes the NEW code, so migrate/finalize
 * logic always comes from the version being installed.
 */
class UpdateManager
{
    /** Per-request work budget (seconds) for chunked phases. */
    private const TIME_BUDGET = 18;

    private const UPDATE_PHASES = [
        'init', 'backup_db', 'backup_files', 'backup_verify',
        'stage_extract', 'stage_validate', 'maintenance_on',
        'apply', 'migrate', 'finalize', 'cache', 'verify',
        'maintenance_off', 'cleanup',
    ];

    private const ROLLBACK_PHASES = ['rb_files', 'rb_db', 'rb_finalize'];

    private const RESTORE_PHASES = [
        'rst_init', 'rst_backup_db', 'rst_extract', 'maintenance_on', 'rst_swap',
        'rst_db', 'rst_cache', 'maintenance_off', 'rst_cleanup',
    ];

    /**
     * Phases whose failure means the live installation may have been
     * modified → automatic rollback. Post-verification phases
     * (maintenance_off, cleanup) are deliberately NOT here: at that point
     * the new version is verified healthy and rolling it back over a
     * cleanup hiccup would be worse than finishing.
     */
    private const MODIFYING_PHASES = ['apply', 'migrate', 'finalize', 'cache', 'verify', 'rst_swap', 'rst_db', 'rst_cache'];

    private UpdateState $state;
    private BackupManager $backups;
    private DatabaseBackupService $database;
    private FileDeployer $files;
    private float $deadline = 0;

    public function __construct()
    {
        $this->state = new UpdateState;
        $this->backups = new BackupManager;
        $this->database = new DatabaseBackupService;
        $this->files = new FileDeployer;
    }

    // ------------------------------------------------------------- lifecycle

    /** Start an update run from a validated uploaded package. */
    public function startUpdate(array $packageMeta, ?array $user): array
    {
        $this->assertNoStepInFlight();
        $existing = $this->state->get();
        if ($existing && ($existing['status'] ?? '') === 'running') {
            if (! $this->state->isStalled($existing)) {
                throw new \RuntimeException('An update is currently in progress. Please wait until it has finished.');
            }
            throw new \RuntimeException('A previous update did not finish. Resolve it from the Recovery panel before starting a new one.');
        }
        if ($existing && ($existing['status'] ?? '') === 'recovery_required') {
            // The state of that run is the only map back to a working
            // system — starting a new run would overwrite it.
            throw new \RuntimeException('The previous update needs attention: its rollback did not finish. Retry the rollback or restore a backup from the Recovery panel before starting a new update.');
        }

        $validator = new PackageValidator;
        $report = $validator->validate(UpdaterPaths::packageZip());
        if (! $report['ok']) {
            throw new \RuntimeException('The package failed validation. Re-run validation to see details.');
        }

        $runId = 'run-'.date('Ymd-His').'-'.Str::lower(Str::random(6));
        if (! $this->state->acquireLock($runId)) {
            throw new \RuntimeException('An update is currently in progress. Please wait until it has finished.');
        }

        $toVersion = $report['package']['version'] ?? ($packageMeta['version'] ?? 'unknown');
        $state = [
            'id' => $runId,
            'type' => 'update',
            'status' => 'running',
            'phase' => 'init',
            'from_version' => $validator->currentVersion(),
            'to_version' => $toVersion,
            'user' => $user,
            'started_at' => time(),
            'package' => [
                'path' => UpdaterPaths::packageZip(),
                'version' => $toVersion,
                'size' => $report['package']['size'] ?? 0,
                'files' => $report['package']['files'] ?? 0,
                'root_prefix' => $report['package']['root_prefix'] ?? null,
            ],
            'backup' => ['id' => null, 'status' => 'pending'],
            'staging' => ['dir' => UpdaterPaths::staging().DIRECTORY_SEPARATOR.$runId, 'cursor' => []],
            'maintenance' => ['secret' => Str::lower(Str::random(24)), 'on' => false],
            'swap_log' => [],
            'migrations' => ['started' => false, 'status' => 'pending', 'before' => [], 'ran' => []],
            'rollback' => ['status' => null],
            'error' => null,
            'log' => [],
        ];
        $this->state->log($state, "=== Update started: v{$state['from_version']} → v{$toVersion} by ".($user['name'] ?? 'unknown').' ===');
        $this->state->put($state);
        $this->state->recordHistory($this->state->historyFromState($state));

        return $state;
    }

    /** Start a restore-from-backup run. */
    public function startRestore(string $backupId, ?array $user): array
    {
        $this->assertNoStepInFlight();
        $backupId = basename($backupId);
        $existing = $this->state->get();
        if ($existing && ($existing['status'] ?? '') === 'running' && ! $this->state->isStalled($existing)) {
            throw new \RuntimeException('An update is currently in progress. Please wait until it has finished.');
        }
        $dir = UpdaterPaths::backupDir($backupId);
        $zip = $dir.DIRECTORY_SEPARATOR.BackupManager::FILES_ZIP;
        $sql = $dir.DIRECTORY_SEPARATOR.BackupManager::DB_DUMP;
        if (! File::exists($zip) || ! File::exists($sql)) {
            throw new \RuntimeException('This backup is incomplete and cannot be restored.');
        }

        $runId = 'restore-'.date('Ymd-His').'-'.Str::lower(Str::random(6));
        if (! $this->state->acquireLock($runId)) {
            throw new \RuntimeException('An update is currently in progress. Please wait until it has finished.');
        }

        $meta = json_decode((string) @File::get($dir.DIRECTORY_SEPARATOR.BackupManager::META), true) ?: [];
        $validator = new PackageValidator;
        $state = [
            'id' => $runId,
            'type' => 'restore',
            'status' => 'running',
            'phase' => 'rst_init',
            'from_version' => $validator->currentVersion(),
            'to_version' => $meta['version'] ?? null,
            'user' => $user,
            'started_at' => time(),
            'backup' => ['id' => $backupId, 'status' => 'available'],
            'staging' => ['dir' => UpdaterPaths::staging().DIRECTORY_SEPARATOR.$runId, 'cursor' => []],
            'maintenance' => ['secret' => Str::lower(Str::random(24)), 'on' => false],
            'swap_log' => [],
            'migrations' => ['started' => false, 'status' => 'n/a', 'before' => [], 'ran' => []],
            'rollback' => ['status' => null],
            'error' => null,
            'log' => [],
        ];
        $this->state->log($state, "=== Restore of backup {$backupId} started by ".($user['name'] ?? 'unknown').' ===');
        $this->state->put($state);
        $this->state->recordHistory($this->state->historyFromState($state));

        return $state;
    }

    /**
     * Advance the state machine by one phase / chunk. Called repeatedly by
     * the UI (and by the recovery screen after a crash).
     */
    public function step(): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);
        ignore_user_abort(true);
        $this->deadline = microtime(true) + self::TIME_BUDGET;

        // Serialize concurrent step calls (two tabs, double-click, poll
        // races): only one request may process a phase at a time; the
        // others just return the current state.
        UpdaterPaths::ensureDirectories();
        $mutex = fopen(UpdaterPaths::stepMutex(), 'c');
        if ($mutex && ! flock($mutex, LOCK_EX | LOCK_NB)) {
            fclose($mutex);

            return $this->state->get() ?? [];
        }

        try {
            return $this->stepLocked();
        } finally {
            if ($mutex) {
                flock($mutex, LOCK_UN);
                fclose($mutex);
            }
        }
    }

    private function stepLocked(): array
    {
        $state = $this->state->get();
        if (! $state) {
            throw new \RuntimeException('No update is in progress.');
        }
        if (($state['status'] ?? '') !== 'running') {
            return $state; // terminal — nothing to do
        }

        $phase = $state['phase'] ?? 'init';
        try {
            $completed = $this->runPhase($phase, $state);
            // A phase may set a terminal status itself (rb_finalize ends the
            // run as rolled_back) — never advance or overwrite it afterwards.
            if ($completed && ($state['status'] ?? '') === 'running') {
                $next = $this->nextPhase($state);
                if ($next === null) {
                    $this->finishSuccessfully($state);
                } else {
                    $state['phase'] = $next;
                    $this->state->log($state, "Phase completed: {$phase} → {$next}");
                }
            }
            $this->state->put($state);
        } catch (\Throwable $e) {
            $this->handleFailure($state, $phase, $e);
        }

        $this->state->recordHistory($this->state->historyFromState($state));

        return $this->state->get() ?? $state;
    }

    /** Explicitly request a rollback for a failed / stalled / recovery run. */
    public function requestRollback(): array
    {
        $this->assertNoStepInFlight();
        $state = $this->state->get();
        if (! $state) {
            throw new \RuntimeException('No update state found.');
        }
        $status = $state['status'] ?? '';
        $stalled = $this->state->isStalled($state);
        $inRollback = in_array($state['phase'] ?? '', self::ROLLBACK_PHASES, true);
        if (! in_array($status, ['recovery_required', 'failed'], true) && ! ($status === 'running' && $stalled)) {
            throw new \RuntimeException('Rollback can only be requested for a failed or interrupted update.');
        }
        if (! $inRollback) {
            $this->initiateRollback($state);
        }
        $state['status'] = 'running';
        $state['error'] = $state['error'] ?? null;
        $this->state->log($state, 'Rollback requested'.($stalled ? ' after interrupted update' : ''));
        $this->state->acquireLock($state['id'] ?? 'recovery');
        $this->state->put($state);

        return $state;
    }

    /** Resume a stalled run exactly where it stopped. */
    public function resume(): array
    {
        $this->assertNoStepInFlight();
        $state = $this->state->get();
        if ($state && ($state['status'] ?? '') === 'recovery_required') {
            // "Continue" on a run whose rollback failed means: retry the
            // rollback phase it stopped in.
            $this->requestRollback();

            return $this->step();
        }
        if (! $state || ($state['status'] ?? '') !== 'running') {
            throw new \RuntimeException('There is no interrupted update to resume.');
        }
        $this->state->log($state, 'Run resumed from phase '.($state['phase'] ?? '?'));
        $this->state->acquireLock($state['id'] ?? 'recovery');
        $this->state->put($state);

        return $this->step();
    }

    /** Clear a terminal state (and optionally the uploaded package). */
    public function discard(): void
    {
        $this->assertNoStepInFlight();
        $state = $this->state->get();
        if ($state && ($state['status'] ?? '') === 'running' && ! $this->state->isStalled($state)) {
            throw new \RuntimeException('Cannot discard a running update.');
        }
        // A run that already modified files/database may only be discarded
        // after its rollback finished — otherwise Resume/Rollback are the
        // only ways out (the state is the map back to a working system).
        if ($state && ! in_array($state['status'] ?? '', ['completed', 'failed', 'rolled_back'], true)) {
            $modified = ! empty($state['apply_done']) || ! empty($state['swap_log']) || ! empty($state['db_modified']);
            if ($modified) {
                throw new \RuntimeException('This run already modified the system — use Resume or Roll back instead of discarding it.');
            }
        }
        if ($state) {
            $staging = $state['staging']['dir'] ?? null;
            if ($staging && File::exists($staging)) {
                File::deleteDirectory($staging);
            }
        }
        $this->state->clear();
        $this->state->releaseLock();
    }

    // ---------------------------------------------------------------- phases

    /** Returns true when the phase is complete (advance), false to re-enter. */
    private function runPhase(string $phase, array &$state): bool
    {
        switch ($phase) {
            case 'init':
                return $this->phaseInit($state);
            case 'backup_db':
                return $this->phaseBackupDb($state);
            case 'backup_files':
                return $this->phaseBackupFiles($state);
            case 'backup_verify':
                return $this->phaseBackupVerify($state);
            case 'stage_extract':
            case 'rst_extract':
                return $this->phaseStageExtract($state);
            case 'stage_validate':
                return $this->phaseStageValidate($state);
            case 'maintenance_on':
                return $this->phaseMaintenanceOn($state);
            case 'apply':
            case 'rst_swap':
                return $this->phaseApply($state);
            case 'migrate':
                return $this->phaseMigrate($state);
            case 'finalize':
                return $this->phaseFinalize($state);
            case 'cache':
            case 'rst_cache':
                return $this->phaseCache($state);
            case 'verify':
                return $this->phaseVerify($state);
            case 'maintenance_off':
                return $this->phaseMaintenanceOff($state);
            case 'cleanup':
            case 'rst_cleanup':
                return $this->phaseCleanup($state);
            case 'rst_init':
                return $this->phaseRestoreInit($state);
            case 'rst_backup_db':
                return $this->phaseRestoreBackupDb($state);
            case 'rst_db':
                return $this->phaseRestoreDb($state);
            case 'rb_files':
                return $this->phaseRollbackFiles($state);
            case 'rb_db':
                return $this->phaseRollbackDb($state);
            case 'rb_finalize':
                return $this->phaseRollbackFinalize($state);
        }
        throw new \RuntimeException('Unknown update phase: '.$phase);
    }

    private function phaseInit(array &$state): bool
    {
        UpdaterPaths::ensureDirectories();
        // Sweep staging leftovers from previous (discarded/rolled-back) runs.
        foreach (File::directories(UpdaterPaths::staging()) as $dir) {
            if (basename($dir) !== ($state['id'] ?? '')) {
                try {
                    File::deleteDirectory($dir);
                } catch (\Throwable $e) {
                }
            }
        }
        $backupId = $this->backups->newBackupId($state['from_version'] ?? 'unknown');
        $this->backups->createBackupDir($backupId);
        $state['backup']['id'] = $backupId;
        $state['backup']['status'] = 'running';
        $count = $this->backups->buildManifest($backupId);
        $state['backup']['manifest_count'] = $count;
        $state['backup']['files_cursor'] = ['offset' => 0, 'position' => 0];
        $state['backup']['db_cursor'] = [];
        $this->backups->backupEnvAndMeta($backupId, [
            'version' => $state['from_version'],
            'reason' => 'pre-update',
            'target_version' => $state['to_version'],
        ]);
        $this->state->log($state, "Backup {$backupId}: manifest built ({$count} files), .env and metadata copied");

        return true;
    }

    private function phaseBackupDb(array &$state): bool
    {
        $backupId = $state['backup']['id'];
        $dumpPath = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.BackupManager::DB_DUMP;
        $cursor = &$state['backup']['db_cursor'];

        if (empty($cursor['tool'])) {
            if ($this->database->dumpWithBinary($dumpPath)) {
                $cursor['tool'] = 'mysqldump';
                $cursor['done'] = true;
                $this->state->log($state, 'Database dumped with mysqldump ('.$this->fmtSize($dumpPath).')');

                return true;
            }
            $cursor['tool'] = 'php';
            $this->state->log($state, 'mysqldump unavailable — using chunked PHP dumper');
        }

        $this->database->dumpChunkPhp($dumpPath, $cursor, fn () => $this->outOfBudget());
        if (! empty($cursor['done'])) {
            $this->state->log($state, 'Database dump finished ('.$this->fmtSize($dumpPath).')');

            return true;
        }

        return false;
    }

    private function phaseBackupFiles(array &$state): bool
    {
        $cursor = &$state['backup']['files_cursor'];
        $this->backups->zipChunk($state['backup']['id'], $cursor, fn () => $this->outOfBudget());
        if (! empty($cursor['done'])) {
            $this->state->log($state, 'Application backup archive finished');

            return true;
        }

        return false;
    }

    private function phaseBackupVerify(array &$state): bool
    {
        $backupId = $state['backup']['id'];
        $dumpPath = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.BackupManager::DB_DUMP;
        $phpDump = ($state['backup']['db_cursor']['tool'] ?? '') === 'php';

        if (! $this->database->verifyDump($dumpPath, $phpDump)) {
            throw new \RuntimeException('Database backup could not be verified — update aborted before any change was made.');
        }
        if (! $this->backups->verifyFilesZip($backupId)) {
            throw new \RuntimeException('Application file backup could not be verified — update aborted before any change was made.');
        }
        $this->backups->markComplete($backupId);
        $state['backup']['status'] = 'verified';
        $this->state->log($state, 'Backup verified (files + database)');

        return true;
    }

    private function phaseStageExtract(array &$state): bool
    {
        if ($state['type'] === 'restore') {
            $zipPath = UpdaterPaths::backupDir($state['backup']['id']).DIRECTORY_SEPARATOR.BackupManager::FILES_ZIP;
            $prefix = null;
        } else {
            $zipPath = $state['package']['path'];
            $prefix = $state['package']['root_prefix'] ?? null;
        }
        $cursor = &$state['staging']['cursor'];
        $this->files->extractChunk($zipPath, $state['staging']['dir'], $prefix, $cursor, fn () => $this->outOfBudget());
        if (! empty($cursor['done'])) {
            $this->state->log($state, 'Extraction complete ('.($cursor['total'] ?? '?').' entries)');

            return true;
        }

        return false;
    }

    private function phaseStageValidate(array &$state): bool
    {
        $problems = $this->files->validateStagedTree($state['staging']['dir'], $state['to_version'] ?? null);
        if (! empty($problems)) {
            throw new \RuntimeException('Staged package failed validation: '.implode(' | ', $problems));
        }
        $this->state->log($state, 'Staged tree validated');

        return true;
    }

    private function phaseMaintenanceOn(array &$state): bool
    {
        $secret = $state['maintenance']['secret'] ?? null;
        $params = ['--retry' => 30];
        if ($secret) {
            $params['--secret'] = $secret;
        }
        if (view()->exists('errors.maintenance-update')) {
            $params['--render'] = 'errors::maintenance-update';
        }
        // DownCommand builds the "except" list stored in the down file from
        // the BASE middleware class, so the $except of our subclass is not
        // seen. With a prerendered template the pre-framework stub
        // (storage/framework/maintenance.php) would then answer 503 to
        // EVERY request — including the updater's own step/status calls —
        // and the run would hang right here. Register the paths statically
        // so the down file carries them.
        $except = (new CheckForMaintenanceMode(app()))->getExcludedPaths();
        PreventRequestsDuringMaintenance::except($except);
        Artisan::call('down', $params);
        $this->ensureDownFileExcept($except);
        $state['maintenance']['on'] = true;
        $this->state->log($state, 'Maintenance mode enabled');

        return true;
    }

    private function phaseApply(array &$state): bool
    {
        // The swap is the point of no return for plain failure handling —
        // from here on, any error triggers the rollback phases.
        $replacedDir = $this->replacedDir($state);
        $stateService = $this->state;
        $this->files->swap($state['staging']['dir'], $replacedDir, function (array $entry) use (&$state, $stateService) {
            $state['swap_log'][] = $entry;
            $stateService->put($state);
        });
        $state['apply_done'] = true;
        $this->state->log($state, 'File swap completed ('.count($state['swap_log']).' items)');

        return true;
    }

    private function phaseMigrate(array &$state): bool
    {
        try {
            $state['migrations']['before'] = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable $e) {
            $state['migrations']['before'] = [];
        }
        $state['migrations']['started'] = true;
        $state['db_modified'] = true;
        $state['migrations']['status'] = 'running';
        $this->state->put($state); // persist BEFORE running — rollback must know

        (new PostUpdateTasks)->preMigration();
        Artisan::call('migrate', ['--force' => true]);

        try {
            $after = DB::table('migrations')->pluck('migration')->all();
            $state['migrations']['ran'] = array_values(array_diff($after, $state['migrations']['before']));
        } catch (\Throwable $e) {
            $state['migrations']['ran'] = [];
        }
        $state['migrations']['status'] = 'completed';
        $this->state->log($state, 'Migrations completed ('.count($state['migrations']['ran']).' new)');

        return true;
    }

    private function phaseFinalize(array &$state): bool
    {
        (new PostUpdateTasks)->run();
        $this->state->log($state, 'Post-update tasks completed (permissions, seeders, templates)');

        return true;
    }

    private function phaseCache(array &$state): bool
    {
        foreach (['optimize:clear', 'cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable $e) {
                $this->state->log($state, "Cache command {$cmd} reported: ".$e->getMessage());
            }
        }
        $this->state->log($state, 'Caches cleared');

        return true;
    }

    private function phaseVerify(array &$state): bool
    {
        $report = (new HealthChecker)->verify($state['to_version'] ?? null);
        $state['health'] = $report;
        if (! $report['ok']) {
            $failed = array_values(array_filter($report['checks'], fn ($c) => ! $c['ok'] && $c['level'] === 'critical'));
            $labels = implode(' | ', array_map(fn ($c) => $c['label'].($c['detail'] ? ' — '.$c['detail'] : ''), $failed));
            throw new \RuntimeException('Post-update verification failed: '.$labels);
        }
        $this->state->log($state, 'Post-update health checks passed');

        return true;
    }

    private function phaseMaintenanceOff(array &$state): bool
    {
        try {
            Artisan::call('up');
        } catch (\Throwable $e) {
            // Last resort: maintenance mode is just a marker file.
            @File::delete(storage_path('framework'.DIRECTORY_SEPARATOR.'down'));
        }
        if (File::exists(storage_path('framework'.DIRECTORY_SEPARATOR.'down'))) {
            throw new \RuntimeException('Could not disable maintenance mode (storage/framework/down is not deletable).');
        }
        $state['maintenance']['on'] = false;
        $this->state->log($state, 'Maintenance mode disabled');

        return true;
    }

    private function phaseCleanup(array &$state): bool
    {
        // The update is verified and live at this point — cleanup problems
        // must never fail (let alone roll back) the run.
        try {
            $staging = $state['staging']['dir'] ?? null;
            if ($staging && File::exists($staging)) {
                File::deleteDirectory($staging);
            }
            // The "replaced" dir holds the old tree moved out by the swap; the
            // durable backup ZIP + DB dump remain per the retention policy.
            $replacedDir = $this->replacedDir($state);
            if (File::exists($replacedDir)) {
                File::deleteDirectory($replacedDir);
            }
            if (($state['type'] ?? '') === 'update') {
                @File::delete(UpdaterPaths::packageZip());
                @File::delete(UpdaterPaths::packageMeta());
                $config = $this->state->config();
                $this->backups->applyRetention((int) ($config['retention'] ?? 3));
            } else {
                @File::delete($this->preRestoreDumpPath($state));
            }
            $this->state->log($state, 'Cleanup finished');
        } catch (\Throwable $e) {
            $this->state->log($state, 'Cleanup reported a non-fatal problem: '.$e->getMessage());
        }

        return true;
    }

    private function phaseRestoreInit(array &$state): bool
    {
        $backupId = $state['backup']['id'];
        if (! $this->backups->verifyFilesZip($backupId)) {
            throw new \RuntimeException('Backup archive failed verification and cannot be restored.');
        }
        $state['staging']['cursor'] = [];
        $this->state->log($state, "Backup {$backupId} verified — starting restore");

        return true;
    }

    /** Safety net for restores: dump the CURRENT database before overwriting it. */
    private function phaseRestoreBackupDb(array &$state): bool
    {
        $dumpPath = $this->preRestoreDumpPath($state);
        $cursor = &$state['pre_restore_db_cursor'];
        if (! is_array($cursor)) {
            $cursor = [];
        }

        if (empty($cursor['tool'])) {
            if ($this->database->dumpWithBinary($dumpPath)) {
                $cursor['tool'] = 'mysqldump';
                $cursor['done'] = true;
            } else {
                $cursor['tool'] = 'php';
            }
        }
        if (empty($cursor['done'])) {
            $this->database->dumpChunkPhp($dumpPath, $cursor, fn () => $this->outOfBudget());
        }
        if (! empty($cursor['done'])) {
            if (! $this->database->verifyDump($dumpPath, ($cursor['tool'] ?? '') === 'php')) {
                throw new \RuntimeException('Could not create a safety dump of the current database — restore aborted before any change.');
            }
            $this->state->log($state, 'Safety dump of current database created ('.$this->fmtSize($dumpPath).')');

            return true;
        }

        return false;
    }

    private function phaseRestoreDb(array &$state): bool
    {
        $state['db_modified'] = true;
        $sqlPath = UpdaterPaths::backupDir($state['backup']['id']).DIRECTORY_SEPARATOR.BackupManager::DB_DUMP;
        $cursor = &$state['restore_db_cursor'];
        if (! is_array($cursor)) {
            $cursor = [];
        }

        return $this->restoreDatabase($state, $sqlPath, $cursor);
    }

    private function preRestoreDumpPath(array $state): string
    {
        return UpdaterPaths::work().DIRECTORY_SEPARATOR.'pre-restore-'.($state['id'] ?? 'run').'.sql';
    }

    // ------------------------------------------------------------- rollback

    private function phaseRollbackFiles(array &$state): bool
    {
        $replacedDir = $this->replacedDir($state);
        if (! empty($state['swap_log'])) {
            $this->files->reverseSwap($state['swap_log'], $replacedDir);
            $state['swap_log'] = [];
            $this->state->log($state, 'File rollback completed (swap reversed)');
        } else {
            $this->state->log($state, 'File rollback: nothing to reverse');
        }

        return true;
    }

    private function phaseRollbackDb(array &$state): bool
    {
        if (empty($state['rollback']['db_needed'])) {
            $this->state->log($state, 'Database rollback not needed (database was never modified)');

            return true;
        }
        $sqlPath = $state['rollback']['sql_path']
            ?? UpdaterPaths::backupDir($state['backup']['id']).DIRECTORY_SEPARATOR.BackupManager::DB_DUMP;
        if (! File::exists($sqlPath)) {
            throw new \RuntimeException('Database backup file is missing — cannot roll the database back automatically.');
        }
        $cursor = &$state['rollback']['db_cursor'];
        if (! is_array($cursor)) {
            $cursor = [];
        }
        $done = $this->restoreDatabase($state, $sqlPath, $cursor);
        if ($done) {
            $state['migrations']['status'] = 'rolled_back';
        }

        return $done;
    }

    private function phaseRollbackFinalize(array &$state): bool
    {
        foreach (['optimize:clear', 'cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable $e) {
            }
        }
        try {
            Artisan::call('up');
            $state['maintenance']['on'] = false;
        } catch (\Throwable $e) {
        }
        $staging = $state['staging']['dir'] ?? null;
        if ($staging && File::exists($staging)) {
            try {
                File::deleteDirectory($staging);
            } catch (\Throwable $e) {
            }
        }
        $state['rollback']['status'] = 'completed';
        $state['status'] = 'rolled_back';
        $state['finished_at'] = time();
        $this->state->log($state, '=== Rollback completed — the previous version was restored ===');
        $this->state->releaseLock();

        return true;
    }

    // -------------------------------------------------------------- helpers

    /** Shared chunked DB restore (used by rollback and by backup-restore). */
    private function restoreDatabase(array &$state, string $sqlPath, array &$cursor): bool
    {
        // The tables are NOT dropped up front. Every step request has to
        // authenticate the admin against the users/roles/permissions tables,
        // so a restore that wipes the database and then spans several
        // requests could never be continued (401 on the next step). Instead
        // each table is dropped and recreated by the dump's own
        // DROP TABLE IF EXISTS / CREATE TABLE right before its rows, the PHP
        // executor only yields between tables, and tables the dump does not
        // contain are removed at the end.
        if (empty($cursor['tried_binary'])) {
            // Try the native client first — single shot.
            $cursor['tried_binary'] = true;
            $this->state->put($state);
            if ($this->database->restoreWithBinary($sqlPath)) {
                $this->database->dropTablesNotInDump($sqlPath);
                $cursor['done'] = true;
                $this->state->log($state, 'Database restored with mysql client');

                return true;
            }
            $this->state->log($state, 'mysql client unavailable — restoring via chunked PHP executor');
            $cursor['offset'] = 0;
        }

        $this->database->restoreChunkPhp($sqlPath, $cursor, fn () => $this->outOfBudget());
        if (! empty($cursor['done'])) {
            $this->state->log($state, 'Database restore finished');

            return true;
        }

        return false;
    }

    /** Mutating entry points must not interleave with a phase being executed. */
    private function assertNoStepInFlight(): void
    {
        if ($this->state->stepInProgress()) {
            throw new \RuntimeException('A step of the current run is executing right now — wait a moment and try again.');
        }
    }

    /**
     * Belt and braces for phaseMaintenanceOn(): make sure the down file
     * really carries the excluded paths, whatever the framework did.
     */
    private function ensureDownFileExcept(array $except): void
    {
        $file = storage_path('framework'.DIRECTORY_SEPARATOR.'down');
        if (! File::exists($file)) {
            return;
        }
        $data = json_decode((string) @File::get($file), true);
        if (! is_array($data)) {
            return;
        }
        $current = (array) ($data['except'] ?? []);
        $merged = array_values(array_unique(array_merge($current, $except)));
        if ($merged !== $current) {
            $data['except'] = $merged;
            File::put($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    private function nextPhase(array $state): ?string
    {
        $phase = $state['phase'];
        $list = match (true) {
            in_array($phase, self::ROLLBACK_PHASES, true) => self::ROLLBACK_PHASES,
            ($state['type'] ?? 'update') === 'restore' => self::RESTORE_PHASES,
            default => self::UPDATE_PHASES,
        };
        $idx = array_search($phase, $list, true);
        if ($idx === false || $idx + 1 >= count($list)) {
            return null;
        }

        return $list[$idx + 1];
    }

    private function finishSuccessfully(array &$state): void
    {
        $state['status'] = 'completed';
        $state['finished_at'] = time();
        $label = ($state['type'] ?? 'update') === 'restore'
            ? 'Restore of backup '.$state['backup']['id']
            : 'Update to v'.($state['to_version'] ?? '?');
        $this->state->log($state, "=== {$label} completed successfully ===");
        $this->state->releaseLock();
    }

    private function handleFailure(array &$state, string $phase, \Throwable $e): void
    {
        $this->state->log($state, "FAILURE in phase {$phase}: ".$e->getMessage());
        $state['error'] = ['phase' => $phase, 'message' => $e->getMessage(), 'time' => date('Y-m-d H:i:s')];

        if (in_array($phase, self::ROLLBACK_PHASES, true)) {
            // The rollback itself failed — recoverable state, manual action.
            $state['status'] = 'recovery_required';
            $state['rollback']['status'] = 'failed';
            $this->state->log($state, 'ROLLBACK FAILED — entering recovery mode. A verified backup is available at: '
                .UpdaterPaths::backupDir($state['backup']['id'] ?? ''));
            $this->state->put($state);

            return;
        }

        $modified = ! empty($state['apply_done'])
            || in_array($phase, self::MODIFYING_PHASES, true)
            || ! empty($state['swap_log']);

        if ($modified) {
            // Automatic rollback via the state machine.
            $this->initiateRollback($state);
            $this->state->log($state, 'Starting automatic rollback');
            $this->state->put($state);

            return;
        }

        // Nothing was modified — fail cleanly.
        if (! empty($state['maintenance']['on'])) {
            try {
                Artisan::call('up');
                $state['maintenance']['on'] = false;
            } catch (\Throwable $up) {
            }
        }
        $state['status'] = 'failed';
        $state['finished_at'] = time();
        $this->state->log($state, 'Update failed before any change was made — the application is untouched.');
        $this->state->put($state);
        $this->state->releaseLock();
    }

    /** Put the run into rollback mode with the right DB restore source. */
    private function initiateRollback(array &$state): void
    {
        $dbNeeded = ! empty($state['db_modified']) || ! empty($state['migrations']['started']);
        $sqlPath = ($state['type'] ?? 'update') === 'restore'
            ? $this->preRestoreDumpPath($state)
            : UpdaterPaths::backupDir($state['backup']['id'] ?? '').DIRECTORY_SEPARATOR.BackupManager::DB_DUMP;
        $state['rollback'] = [
            'status' => 'running',
            'db_cursor' => [],
            'db_needed' => $dbNeeded,
            'sql_path' => $sqlPath,
        ];
        $state['phase'] = 'rb_files';
    }

    private function replacedDir(array $state): string
    {
        $backupId = $state['backup']['id'] ?? 'unknown';

        return UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.'replaced';
    }

    private function outOfBudget(): bool
    {
        return microtime(true) > $this->deadline;
    }

    private function fmtSize(string $path): string
    {
        $bytes = File::exists($path) ? File::size($path) : 0;
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    // --------------------------------------------------------------- status

    /** Full status payload for the admin UI. */
    public function status(): array
    {
        $state = $this->state->get();
        $stalled = $this->state->isStalled($state);
        $config = $this->state->config();

        $packageMeta = null;
        if (File::exists(UpdaterPaths::packageMeta())) {
            $packageMeta = json_decode((string) @File::get(UpdaterPaths::packageMeta()), true);
        }

        return [
            'current_version' => (new PackageValidator)->currentVersion(),
            'state' => $state ? $this->publicState($state, $stalled) : null,
            'package' => $packageMeta,
            'history' => array_reverse($this->state->history()),
            'backups' => $this->backups->listBackups(),
            'retention' => (int) ($config['retention'] ?? 3),
        ];
    }

    private function publicState(array $state, bool $stalled): array
    {
        [$percent, $stepLabel] = $this->progressOf($state);

        return [
            'id' => $state['id'] ?? null,
            'type' => $state['type'] ?? 'update',
            'status' => $state['status'] ?? null,
            'phase' => $state['phase'] ?? null,
            'stalled' => $stalled,
            'percent' => $percent,
            'step_label' => $stepLabel,
            'from_version' => $state['from_version'] ?? null,
            'to_version' => $state['to_version'] ?? null,
            'started_at' => isset($state['started_at']) ? date('Y-m-d H:i:s', $state['started_at']) : null,
            'error' => $state['error'] ?? null,
            'rollback' => $state['rollback'] ?? null,
            'backup_id' => $state['backup']['id'] ?? null,
            'maintenance_secret' => (! empty($state['maintenance']['on'])) ? ($state['maintenance']['secret'] ?? null) : null,
            'health' => $state['health'] ?? null,
            'log_tail' => array_slice($state['log'] ?? [], -30),
        ];
    }

    private function progressOf(array $state): array
    {
        $phase = $state['phase'] ?? 'init';
        $map = [
            // phase => [start%, end%]
            'init' => [0, 4], 'backup_db' => [4, 18], 'backup_files' => [18, 38],
            'backup_verify' => [38, 40], 'stage_extract' => [40, 55], 'stage_validate' => [55, 57],
            'maintenance_on' => [57, 59], 'apply' => [59, 70], 'migrate' => [70, 80],
            'finalize' => [80, 86], 'cache' => [86, 90], 'verify' => [90, 95],
            'maintenance_off' => [95, 97], 'cleanup' => [97, 100],
            'rst_init' => [0, 5], 'rst_backup_db' => [5, 20], 'rst_extract' => [20, 40], 'rst_swap' => [45, 60],
            'rst_db' => [60, 85], 'rst_cache' => [85, 92], 'rst_cleanup' => [97, 100],
            'rb_files' => [0, 40], 'rb_db' => [40, 85], 'rb_finalize' => [85, 100],
        ];
        [$from, $to] = $map[$phase] ?? [0, 100];

        $fraction = 0.0;
        if (in_array($phase, ['backup_files'], true)) {
            $total = max(1, (int) ($state['backup']['manifest_count'] ?? 1));
            $fraction = min(1, ($state['backup']['files_cursor']['position'] ?? 0) / $total);
        } elseif (in_array($phase, ['stage_extract', 'rst_extract'], true)) {
            $total = max(1, (int) ($state['staging']['cursor']['total'] ?? 1));
            $fraction = min(1, ($state['staging']['cursor']['index'] ?? 0) / $total);
        }

        if (($state['status'] ?? '') === 'completed') {
            return [100, 'completed'];
        }

        return [(int) round($from + ($to - $from) * $fraction), $phase];
    }
}
