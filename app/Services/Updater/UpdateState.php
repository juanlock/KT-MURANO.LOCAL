<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;

/**
 * Persistent state for the resumable update state machine, plus the update
 * history and the small updater config. Everything is stored as JSON files
 * under storage/app/updater so that it survives a database restore and can
 * be read even when the database is unavailable.
 */
class UpdateState
{
    /** Seconds without a heartbeat after which a "running" update counts as stalled. */
    public const STALE_AFTER = 120;

    // ---------------------------------------------------------------- state

    public function get(): ?array
    {
        $file = UpdaterPaths::stateFile();
        if (! File::exists($file)) {
            return null;
        }
        $data = json_decode((string) @File::get($file), true);

        return is_array($data) ? $data : null;
    }

    public function put(array $state): void
    {
        UpdaterPaths::ensureDirectories();
        $state['heartbeat_at'] = time();
        // Atomic-ish write: temp file + rename, so a crash mid-write never
        // leaves a truncated state file.
        $file = UpdaterPaths::stateFile();
        $tmp = $file.'.tmp';
        File::put($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @File::move($tmp, $file);
        if (File::exists($tmp)) { // move failed (Windows overwrite) — fall back
            @File::delete($file);
            @File::move($tmp, $file);
        }
    }

    public function clear(): void
    {
        @File::delete(UpdaterPaths::stateFile());
    }

    public function isStalled(?array $state = null): bool
    {
        $state = $state ?? $this->get();
        if (! $state || ($state['status'] ?? '') !== 'running') {
            return false;
        }
        if ((time() - (int) ($state['heartbeat_at'] ?? 0)) <= self::STALE_AFTER) {
            return false;
        }

        // A long single-shot phase (mysqldump of a big database, the file
        // swap, migrations, seeders) cannot heartbeat while it runs. As long
        // as a request still holds the step mutex the run is alive, not
        // stalled — reporting it as stalled would let a second tab start a
        // rollback on top of the running phase.
        return ! $this->stepInProgress();
    }

    /** True while some request is executing UpdateManager::step(). */
    public function stepInProgress(): bool
    {
        $file = UpdaterPaths::stepMutex();
        if (! File::exists($file)) {
            return false;
        }
        $handle = @fopen($file, 'c');
        if (! $handle) {
            return false;
        }
        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $acquired;
    }

    /** An update (or restore) is actively being processed and is not stalled. */
    public function isActive(): bool
    {
        $state = $this->get();

        return $state !== null
            && ($state['status'] ?? '') === 'running'
            && ! $this->isStalled($state);
    }

    public function log(array &$state, string $message): void
    {
        $line = '['.date('Y-m-d H:i:s').'] '.$message;
        $state['log'][] = $line;
        // Keep the in-state log bounded; the full trail goes to the log file.
        if (count($state['log']) > 400) {
            $state['log'] = array_slice($state['log'], -400);
        }
        $this->fileLog($message);
    }

    public function fileLog(string $message): void
    {
        $path = UpdaterPaths::logFile();
        try {
            if (File::exists($path) && File::size($path) > 10 * 1024 * 1024) {
                @File::move($path, storage_path('logs/system-update-'.date('Ymd-His').'.log'));
            }
        } catch (\Throwable $e) {
        }
        @File::append($path, '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL);
    }

    // -------------------------------------------------------------- history

    public function history(): array
    {
        $file = UpdaterPaths::historyFile();
        if (! File::exists($file)) {
            return [];
        }
        $data = json_decode((string) @File::get($file), true);

        return is_array($data) ? $data : [];
    }

    /** Insert or replace (matched by run id) one history record. */
    public function recordHistory(array $record): void
    {
        UpdaterPaths::ensureDirectories();
        $history = $this->history();
        $replaced = false;
        foreach ($history as $i => $row) {
            if (($row['id'] ?? null) === ($record['id'] ?? '')) {
                $history[$i] = $record;
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            $history[] = $record;
        }
        $history = array_slice($history, -50);
        File::put(UpdaterPaths::historyFile(), json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Build the history record for a run from its state snapshot. */
    public function historyFromState(array $state): array
    {
        $started = (int) ($state['started_at'] ?? time());
        $finished = in_array($state['status'] ?? '', ['completed', 'failed', 'rolled_back'], true)
            ? (int) ($state['finished_at'] ?? time())
            : null;

        return [
            'id' => $state['id'] ?? '',
            'type' => $state['type'] ?? 'update',
            'from_version' => $state['from_version'] ?? null,
            'to_version' => $state['to_version'] ?? null,
            'user' => $state['user'] ?? null,
            'started_at' => date('Y-m-d H:i:s', $started),
            'finished_at' => $finished ? date('Y-m-d H:i:s', $finished) : null,
            'duration' => $finished ? max(0, $finished - $started) : null,
            'status' => $state['status'] ?? 'running',
            'phase' => $state['phase'] ?? null,
            'backup_id' => $state['backup']['id'] ?? null,
            'backup_status' => $state['backup']['status'] ?? null,
            'migrations_ran' => $state['migrations']['ran'] ?? [],
            'migration_status' => $state['migrations']['status'] ?? null,
            'rollback_status' => $state['rollback']['status'] ?? null,
            'error' => $state['error'] ?? null,
        ];
    }

    // --------------------------------------------------------------- config

    public function config(): array
    {
        $defaults = ['retention' => 3];
        $file = UpdaterPaths::configFile();
        if (! File::exists($file)) {
            return $defaults;
        }
        $data = json_decode((string) @File::get($file), true);

        return is_array($data) ? array_merge($defaults, $data) : $defaults;
    }

    public function saveConfig(array $config): void
    {
        UpdaterPaths::ensureDirectories();
        File::put(UpdaterPaths::configFile(), json_encode($config, JSON_PRETTY_PRINT));
    }

    // ----------------------------------------------------------------- lock

    /**
     * Acquire the updater lock. Returns false when another update is
     * actively running (fresh heartbeat). A lock whose owning run has a
     * stale heartbeat does NOT block — that run is recovered, not restarted,
     * and recovery goes through the same state machine.
     */
    public function acquireLock(string $runId): bool
    {
        UpdaterPaths::ensureDirectories();
        $file = UpdaterPaths::lockFile();
        if (File::exists($file)) {
            $lock = json_decode((string) @File::get($file), true) ?: [];
            $state = $this->get();
            $sameRun = $state && (($state['id'] ?? null) === ($lock['run_id'] ?? ''));
            if ($sameRun && ($state['status'] ?? '') === 'running' && ! $this->isStalled($state)) {
                return ($lock['run_id'] ?? '') === $runId;
            }
            // Orphaned or finished lock — safe to take over.
            @File::delete($file);
        }
        // Exclusive create so two simultaneous "Start Update" clicks cannot
        // both win — exactly one fopen('x') succeeds.
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, json_encode(['run_id' => $runId, 'locked_at' => time(), 'pid' => getmypid()]));
        fclose($handle);

        return true;
    }

    public function lockOwner(): ?string
    {
        $file = UpdaterPaths::lockFile();
        if (! File::exists($file)) {
            return null;
        }
        $lock = json_decode((string) @File::get($file), true) ?: [];

        return $lock['run_id'] ?? null;
    }

    public function releaseLock(): void
    {
        @File::delete(UpdaterPaths::lockFile());
    }
}
