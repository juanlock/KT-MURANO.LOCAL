<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Pre-update backups: a full application ZIP (built chunked so it can span
 * several short requests), the database dump, a copy of .env and a metadata
 * file — all stored under storage/app/updater/backups/<id>/ which is never
 * web-accessible and excluded from the update itself.
 */
class BackupManager
{
    public const FILES_ZIP = 'app-files.zip';
    public const DB_DUMP = 'database.sql';
    public const ENV_COPY = 'env.backup';
    public const META = 'meta.json';
    public const MANIFEST = 'manifest.txt';

    public function newBackupId(string $version): string
    {
        return date('Ymd-His').'-v'.preg_replace('/[^0-9a-zA-Z._-]/', '', $version ?: 'unknown');
    }

    public function createBackupDir(string $id): string
    {
        $dir = UpdaterPaths::backupDir($id);
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Build the list of files to include in the application backup ZIP and
     * persist it (one relative path per line) into the backup dir. Returns
     * the number of files.
     */
    public function buildManifest(string $backupId): int
    {
        @set_time_limit(0);
        $base = base_path();
        $excluded = UpdaterPaths::backupExcludedPaths();
        $manifestPath = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::MANIFEST;

        $out = fopen($manifestPath, 'wb');
        if (! $out) {
            throw new \RuntimeException('Cannot create backup manifest');
        }
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    function ($item) use ($base, $excluded) {
                        $rel = str_replace('\\', '/', ltrim(substr($item->getPathname(), strlen($base)), '/\\'));
                        foreach ($excluded as $ex) {
                            if ($rel === $ex || strpos($rel, $ex.'/') === 0) {
                                return false;
                            }
                        }

                        return true;
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                $rel = str_replace('\\', '/', ltrim(substr($item->getPathname(), strlen($base)), '/\\'));
                fwrite($out, $rel."\n");
                $count++;
            }
        } finally {
            fclose($out);
        }

        return $count;
    }

    /**
     * Add files from the manifest to the backup ZIP starting at line
     * $cursor['position'], until $shouldStop() says to yield. Sets
     * $cursor['done'] when the whole manifest has been archived.
     */
    public function zipChunk(string $backupId, array &$cursor, callable $shouldStop): void
    {
        // Batches of ~600 files per ZIP open/close (ZipArchive holds source
        // handles until close — stay under OS limits), repeated until the
        // request's time budget runs out.
        while (empty($cursor['done']) && ! $shouldStop()) {
            $this->zipBatch($backupId, $cursor, $shouldStop);
        }
    }

    private function zipBatch(string $backupId, array &$cursor, callable $shouldStop): void
    {
        $dir = UpdaterPaths::backupDir($backupId);
        $zipPath = $dir.DIRECTORY_SEPARATOR.self::FILES_ZIP;
        $manifestPath = $dir.DIRECTORY_SEPARATOR.self::MANIFEST;
        $base = base_path();

        $manifest = fopen($manifestPath, 'rb');
        if (! $manifest) {
            throw new \RuntimeException('Backup manifest missing');
        }

        $zip = new ZipArchive;
        $flags = File::exists($zipPath) ? 0 : ZipArchive::CREATE;
        if ($zip->open($zipPath, $flags) !== true) {
            fclose($manifest);
            throw new \RuntimeException('Cannot open backup archive for writing');
        }

        try {
            fseek($manifest, (int) ($cursor['offset'] ?? 0));
            $added = 0;
            while (($line = fgets($manifest)) !== false) {
                $rel = trim($line);
                if ($rel === '') {
                    $cursor['offset'] = ftell($manifest);
                    continue;
                }
                $full = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (File::exists($full)) {
                    if (! $zip->addFile($full, $rel)) {
                        throw new \RuntimeException('Failed adding file to backup: '.$rel);
                    }
                }
                $added++;
                $cursor['position'] = ($cursor['position'] ?? 0) + 1;
                $cursor['offset'] = ftell($manifest);

                // ZipArchive keeps source files open until close(); cap the
                // batch to stay well below OS file-handle limits.
                if ($added >= 600 || ($added % 50 === 0 && $shouldStop())) {
                    return; // reopened and resumed on the next call
                }
            }
            $cursor['done'] = true;
        } finally {
            fclose($manifest);
            if ($zip->close() !== true) {
                throw new \RuntimeException('Failed finalizing backup archive chunk (disk full?)');
            }
        }
    }

    /** Re-open the finished backup ZIP and verify it against the manifest. */
    public function verifyFilesZip(string $backupId): bool
    {
        $dir = UpdaterPaths::backupDir($backupId);
        $zipPath = $dir.DIRECTORY_SEPARATOR.self::FILES_ZIP;
        $manifestPath = $dir.DIRECTORY_SEPARATOR.self::MANIFEST;
        if (! File::exists($zipPath) || ! File::exists($manifestPath)) {
            return false;
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        $zipCount = $zip->numFiles;
        $zip->close();

        // Manifest lines (files may legitimately be missing if deleted mid-backup;
        // allow a tiny tolerance but never a large shortfall)
        $manifestCount = 0;
        $handle = fopen($manifestPath, 'rb');
        while (fgets($handle) !== false) {
            $manifestCount++;
        }
        fclose($handle);

        return $zipCount > 0 && $zipCount >= (int) floor($manifestCount * 0.995);
    }

    /** Copy .env + metadata into the backup dir. */
    public function backupEnvAndMeta(string $backupId, array $meta): void
    {
        $dir = UpdaterPaths::backupDir($backupId);
        if (File::exists(base_path('.env'))) {
            File::copy(base_path('.env'), $dir.DIRECTORY_SEPARATOR.self::ENV_COPY);
        }
        $meta['created_at'] = date('Y-m-d H:i:s');
        $meta['php_version'] = PHP_VERSION;
        File::put($dir.DIRECTORY_SEPARATOR.self::META, json_encode($meta, JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------------------ management

    public function listBackups(): array
    {
        $root = UpdaterPaths::backups();
        if (! File::exists($root)) {
            return [];
        }
        $backups = [];
        foreach (File::directories($root) as $dir) {
            $id = basename($dir);
            $meta = [];
            $metaFile = $dir.DIRECTORY_SEPARATOR.self::META;
            if (File::exists($metaFile)) {
                $meta = json_decode((string) @File::get($metaFile), true) ?: [];
            }
            $zip = $dir.DIRECTORY_SEPARATOR.self::FILES_ZIP;
            $sql = $dir.DIRECTORY_SEPARATOR.self::DB_DUMP;
            $backups[] = [
                'id' => $id,
                'version' => $meta['version'] ?? null,
                'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s', File::exists($zip) ? filemtime($zip) : filemtime($dir)),
                'files_zip' => File::exists($zip),
                'files_size' => File::exists($zip) ? File::size($zip) : 0,
                'db_dump' => File::exists($sql),
                'db_size' => File::exists($sql) ? File::size($sql) : 0,
                'complete' => ! empty($meta['complete']),
            ];
        }
        usort($backups, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $backups;
    }

    /**
     * Delete one backup. Refuses to delete the only complete backup so a
     * recovery path always remains available.
     */
    public function deleteBackup(string $id): void
    {
        $id = basename($id); // never allow traversal via the id
        $dir = UpdaterPaths::backupDir($id);
        if (! File::exists($dir)) {
            throw new \RuntimeException('Backup not found');
        }
        $complete = array_values(array_filter($this->listBackups(), fn ($b) => $b['complete']));
        if (count($complete) === 1 && ($complete[0]['id'] ?? null) === $id) {
            throw new \RuntimeException('This is the only complete backup and cannot be deleted — it is your recovery point.');
        }
        File::deleteDirectory($dir);
    }

    /** Apply the retention policy, always keeping at least one complete backup. */
    public function applyRetention(int $keep): void
    {
        $keep = max(1, $keep);
        $backups = $this->listBackups(); // newest first
        $complete = array_values(array_filter($backups, fn ($b) => $b['complete']));
        $keepIds = array_map(fn ($b) => $b['id'], array_slice($complete, 0, $keep));
        // Never end up with zero complete backups
        if (empty($keepIds) && ! empty($complete)) {
            $keepIds = [$complete[0]['id']];
        }
        foreach ($backups as $backup) {
            if (in_array($backup['id'], $keepIds, true)) {
                continue;
            }
            // Incomplete backups older than a day are debris; newest incomplete
            // may belong to a run in progress — leave it alone.
            if (! $backup['complete'] && strtotime($backup['created_at']) > time() - 86400) {
                continue;
            }
            try {
                File::deleteDirectory(UpdaterPaths::backupDir($backup['id']));
            } catch (\Throwable $e) {
            }
        }
    }

    public function markComplete(string $backupId): void
    {
        $metaFile = UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.self::META;
        $meta = File::exists($metaFile) ? (json_decode((string) File::get($metaFile), true) ?: []) : [];
        $meta['complete'] = true;
        File::put($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
    }
}
