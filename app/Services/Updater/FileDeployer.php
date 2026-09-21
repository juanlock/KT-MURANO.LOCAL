<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * File-level mechanics of the update:
 *
 *  - chunked, sanitized ZIP extraction into the staging area
 *  - validation of the staged tree
 *  - the APPLY step: a rename-based swap of top-level items (near-instant,
 *    so the window where the application is inconsistent is milliseconds,
 *    not minutes) — old items are MOVED into the backup's "replaced/" dir,
 *    which doubles as the fast rollback source
 *  - the reverse swap for rollback
 *
 * Protected paths (.env, storage, public/images, Modules,
 * modules_statuses.json) are never touched in either direction.
 */
class FileDeployer
{
    /** @var callable|null notified after each swap move for incremental log persistence */
    private $onSwapEntry = null;

    /** Top-level items that must never be pruned even when absent from a package. */
    private const NEVER_PRUNE = [
        'node_modules', '.git', '.idea', '.vscode', '.htaccess', 'php.ini', '.user.ini',
        'cgi-bin', '.well-known', 'error_log',
    ];

    /**
     * Items never taken FROM a package either — local dev/host artifacts.
     * Even if an uploaded ZIP ships them, they are not extracted or swapped
     * over the local ones.
     */
    private const NEVER_DEPLOY = ['node_modules', '.git'];

    // ------------------------------------------------------------ extraction

    /**
     * Extract entries [$cursor['index'] ..] of $zipPath into $targetDir,
     * stripping $stripPrefix, refusing traversal and protected paths.
     * Sets $cursor['done'] when every entry has been written.
     */
    public function extractChunk(string $zipPath, string $targetDir, ?string $stripPrefix, array &$cursor, callable $shouldStop): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open archive for extraction: '.basename($zipPath));
        }
        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        try {
            $total = $zip->numFiles;
            $i = (int) ($cursor['index'] ?? 0);
            $cursor['total'] = $total;
            $prefixLen = $stripPrefix ? strlen($stripPrefix) : 0;

            for (; $i < $total; $i++) {
                $stat = $zip->statIndex($i);
                $cursor['index'] = $i + 1;
                if (! $stat || ! isset($stat['name'])) {
                    continue;
                }
                $name = str_replace('\\', '/', $stat['name']);
                $rel = $prefixLen && strpos($name, $stripPrefix) === 0 ? substr($name, $prefixLen) : $name;
                $rel = ltrim($rel, '/');
                if ($rel === '' || ! $this->isSafeRelPath($rel) || $this->isProtected($rel)) {
                    continue;
                }
                $top = strstr($rel, '/', true) ?: $rel;
                if (in_array($top, self::NEVER_DEPLOY, true)) {
                    continue;
                }

                $target = $targetDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (substr($name, -1) === '/') {
                    if (! File::exists($target)) {
                        File::makeDirectory($target, 0755, true);
                    }
                    continue;
                }
                $parent = dirname($target);
                if (! File::exists($parent)) {
                    File::makeDirectory($parent, 0755, true);
                }
                $in = $zip->getStream($stat['name']);
                if (! $in) {
                    throw new \RuntimeException('Failed reading archive entry: '.$rel);
                }
                $out = @fopen($target, 'wb');
                if (! $out) {
                    fclose($in);
                    throw new \RuntimeException('Failed writing extracted file: '.$rel);
                }
                if (stream_copy_to_stream($in, $out) === false) {
                    fclose($in);
                    fclose($out);
                    throw new \RuntimeException('Failed extracting entry (disk full?): '.$rel);
                }
                fclose($in);
                fclose($out);

                if ((($i + 1) % 50) === 0 && $shouldStop()) {
                    return;
                }
            }
            $cursor['done'] = true;
        } finally {
            $zip->close();
        }
    }

    /** Staged tree must look like a complete application of the expected version. */
    public function validateStagedTree(string $stagedRoot, ?string $expectedVersion): array
    {
        $problems = [];
        foreach (['artisan', 'composer.json', 'version.txt', 'public/index.php', 'vendor/autoload.php',
            'app/Services/Updater/UpdateManager.php', 'app/Http/Controllers/SystemUpdateController.php'] as $required) {
            if (! File::exists($stagedRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $required))) {
                $problems[] = 'Missing from extracted package: '.$required;
            }
        }
        $versionFile = $stagedRoot.DIRECTORY_SEPARATOR.'version.txt';
        if ($expectedVersion && File::exists($versionFile)) {
            $found = trim((string) File::get($versionFile));
            if ($found !== trim($expectedVersion)) {
                $problems[] = "Version mismatch after extraction (expected {$expectedVersion}, found {$found})";
            }
        }

        return $problems;
    }

    // ------------------------------------------------------------------ swap

    /**
     * Swap the live application with the staged tree using renames.
     * Old items are moved into $replacedDir; every operation is recorded in
     * the returned log so it can be reversed exactly.
     *
     * Log entries: ['rel' => 'vendor', 'action' => 'swapped'|'added'|'pruned']
     *
     * $onEntry (if given) is invoked after every individual move so the log
     * can be persisted incrementally — a crash mid-swap stays reversible.
     */
    public function swap(string $stagedRoot, string $replacedDir, ?callable $onEntry = null): array
    {
        @set_time_limit(0);
        $base = base_path();
        if (! File::exists($replacedDir)) {
            File::makeDirectory($replacedDir, 0755, true);
        }
        $log = [];
        $this->onSwapEntry = $onEntry;

        $stagedTop = $this->children($stagedRoot);
        $baseTop = $this->children($base);

        foreach ($stagedTop as $name) {
            if ($this->isProtected($name) || in_array($name, self::NEVER_DEPLOY, true)) {
                continue;
            }
            if ($name === 'public') {
                $this->swapPublic($stagedRoot, $replacedDir, $log);
                continue;
            }
            $this->swapItem($name, $stagedRoot, $base, $replacedDir, $log);
        }

        // Prune top-level items that the new version no longer ships.
        foreach ($baseTop as $name) {
            if ($this->isProtected($name) || in_array($name, self::NEVER_PRUNE, true)) {
                continue;
            }
            if (in_array($name, $stagedTop, true)) {
                continue;
            }
            $this->recordSwap($log, ['rel' => $name, 'action' => 'pruned']);
            $this->moveItem($base.DIRECTORY_SEPARATOR.$name, $replacedDir.DIRECTORY_SEPARATOR.$name);
        }

        $this->afterSwapHousekeeping();
        $this->onSwapEntry = null;

        return $log;
    }

    private function recordSwap(array &$log, array $entry): void
    {
        $log[] = $entry;
        if ($this->onSwapEntry) {
            ($this->onSwapEntry)($entry);
        }
    }

    private function swapPublic(string $stagedRoot, string $replacedDir, array &$log): void
    {
        $base = base_path();
        $stagedPublic = $stagedRoot.DIRECTORY_SEPARATOR.'public';
        $basePublic = $base.DIRECTORY_SEPARATOR.'public';
        if (! File::exists($basePublic)) {
            File::makeDirectory($basePublic, 0755, true);
        }
        $replacedPublic = $replacedDir.DIRECTORY_SEPARATOR.'public';
        if (! File::exists($replacedPublic)) {
            File::makeDirectory($replacedPublic, 0755, true);
        }

        $stagedChildren = $this->children($stagedPublic);
        $baseChildren = $this->children($basePublic);

        foreach ($stagedChildren as $name) {
            if ($this->isProtected('public/'.$name)) {
                continue;
            }
            $this->swapItem($name, $stagedPublic, $basePublic, $replacedPublic, $log, 'public/');
        }
        foreach ($baseChildren as $name) {
            $rel = 'public/'.$name;
            if ($this->isProtected($rel) || in_array($name, self::NEVER_PRUNE, true) || $name === 'storage') {
                continue;
            }
            if (in_array($name, $stagedChildren, true)) {
                continue;
            }
            $this->recordSwap($log, ['rel' => $rel, 'action' => 'pruned']);
            $this->moveItem($basePublic.DIRECTORY_SEPARATOR.$name, $replacedPublic.DIRECTORY_SEPARATOR.$name);
        }
    }

    private function swapItem(string $name, string $fromRoot, string $toRoot, string $replacedRoot, array &$log, string $relPrefix = ''): void
    {
        $staged = $fromRoot.DIRECTORY_SEPARATOR.$name;
        $live = $toRoot.DIRECTORY_SEPARATOR.$name;
        $hadOld = File::exists($live);
        // Record intent BEFORE moving: if the process dies between the two
        // renames, the rollback still knows this item was in flight
        // (reverseSwap tolerates a missing live or replaced side).
        $this->recordSwap($log, ['rel' => $relPrefix.$name, 'action' => $hadOld ? 'swapped' : 'added']);
        if ($hadOld) {
            $this->moveItem($live, $replacedRoot.DIRECTORY_SEPARATOR.$name);
        }
        $this->moveItem($staged, $live);
    }

    /**
     * Reverse a swap using its log: new items are discarded, old items are
     * moved back from the "replaced" dir. Processed in reverse order.
     */
    public function reverseSwap(array $log, string $replacedDir): void
    {
        @set_time_limit(0);
        $base = base_path();
        foreach (array_reverse($log) as $entry) {
            $rel = str_replace('/', DIRECTORY_SEPARATOR, $entry['rel']);
            $live = $base.DIRECTORY_SEPARATOR.$rel;
            $replaced = $replacedDir.DIRECTORY_SEPARATOR.$rel;
            switch ($entry['action']) {
                case 'swapped':
                    // Only discard the live item when the old one is actually
                    // waiting in "replaced" — if the crash happened before the
                    // old item was moved out, live still IS the old item.
                    if (File::exists($replaced)) {
                        $this->removeItem($live);
                        $this->moveItem($replaced, $live);
                    }
                    break;
                case 'added':
                    $this->removeItem($live);
                    break;
                case 'pruned':
                    if (File::exists($replaced) && ! File::exists($live)) {
                        $this->moveItem($replaced, $live);
                    }
                    break;
            }
        }
        $this->afterSwapHousekeeping();
    }

    /** Reset caches/compiled state after files changed under a running app. */
    public function afterSwapHousekeeping(): void
    {
        $cacheDir = base_path('bootstrap'.DIRECTORY_SEPARATOR.'cache');
        if (! File::exists($cacheDir)) {
            @File::makeDirectory($cacheDir, 0775, true);
            @File::put($cacheDir.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n");
        }
        // Stale compiled files reference classes/paths from the other version.
        foreach (File::glob($cacheDir.DIRECTORY_SEPARATOR.'*.php') as $file) {
            @File::delete($file);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    // --------------------------------------------------------------- helpers

    public function isProtected(string $rel): bool
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        foreach (UpdaterPaths::protectedPaths() as $protected) {
            if ($rel === $protected || strpos($rel, $protected.'/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function isSafeRelPath(string $rel): bool
    {
        if (strpos($rel, '..') !== false) {
            return false;
        }
        if (preg_match('/^([a-zA-Z]:\/|\/)/', $rel)) {
            return false;
        }

        return true;
    }

    private function children(string $dir): array
    {
        if (! File::exists($dir)) {
            return [];
        }
        $items = @scandir($dir);
        if ($items === false) {
            return [];
        }

        return array_values(array_diff($items, ['.', '..']));
    }

    /** Move via rename, falling back to copy+delete (cross-device, locks). */
    private function moveItem(string $from, string $to): void
    {
        $parent = dirname($to);
        if (! File::exists($parent)) {
            File::makeDirectory($parent, 0755, true);
        }
        if (@rename($from, $to)) {
            return;
        }
        clearstatcache();
        if (is_dir($from)) {
            if (! File::copyDirectory($from, $to)) {
                throw new \RuntimeException('Failed moving directory: '.$from);
            }
            File::deleteDirectory($from);
        } else {
            if (! @File::copy($from, $to)) {
                throw new \RuntimeException('Failed moving file: '.$from);
            }
            @File::delete($from);
        }
    }

    private function removeItem(string $path): void
    {
        if (! File::exists($path)) {
            return;
        }
        if (is_dir($path) && ! is_link($path)) {
            File::deleteDirectory($path);
        } else {
            File::delete($path);
        }
    }
}
