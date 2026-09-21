<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;

/**
 * Central directory layout for the upload-based system updater.
 *
 * Everything lives under storage/app/updater which is never web-accessible
 * (this project does not use the public storage symlink) and is excluded
 * from backups and from the file-replacement scope.
 */
class UpdaterPaths
{
    public static function root(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'updater');
    }

    public static function uploads(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'uploads';
    }

    public static function packageZip(): string
    {
        return self::uploads().DIRECTORY_SEPARATOR.'package.zip';
    }

    public static function packagePart(): string
    {
        return self::uploads().DIRECTORY_SEPARATOR.'package.zip.part';
    }

    public static function packageMeta(): string
    {
        return self::uploads().DIRECTORY_SEPARATOR.'package.meta.json';
    }

    public static function backups(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'backups';
    }

    public static function backupDir(string $id): string
    {
        return self::backups().DIRECTORY_SEPARATOR.$id;
    }

    public static function staging(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'staged';
    }

    public static function work(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'work';
    }

    public static function stateFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'state.json';
    }

    public static function historyFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'history.json';
    }

    public static function configFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'config.json';
    }

    public static function lockFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'lock.json';
    }

    /** flock() target that serializes step() execution across requests. */
    public static function stepMutex(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'step.mutex';
    }

    public static function logFile(): string
    {
        return storage_path('logs'.DIRECTORY_SEPARATOR.'system-update.log');
    }

    /**
     * Relative paths (from base_path) that the updater must never replace,
     * delete or accept from an uploaded package. Kept identical to the
     * legacy updater's protected list.
     */
    public static function protectedPaths(): array
    {
        return [
            '.env',
            'storage',
            'public/images',
            'Modules',
            'modules_statuses.json',
        ];
    }

    /**
     * Relative paths excluded from the pre-update application backup ZIP.
     *
     * The protected paths (.env, storage, public/images, Modules, ...) are
     * excluded on purpose: the updater never replaces them, and the restore
     * skips them when extracting, so archiving them adds nothing that can
     * be rolled back. Leaving them out keeps the backup proportional to
     * the application code instead of to the size of the uploads folder —
     * ZipArchive rewrites the whole archive on every batch close, so
     * gigabytes of images would turn the backup phase into hours of I/O.
     * .env is copied separately by BackupManager::backupEnvAndMeta().
     */
    public static function backupExcludedPaths(): array
    {
        return array_values(array_unique(array_merge(self::protectedPaths(), [
            // updater's own working area — must never be nested into itself
            'storage/app/updater',
            // legacy updater working dirs
            'storage/app/updates',
            'storage/app/public/backup',
            'node_modules',
            '.git',
        ])));
    }

    public static function ensureDirectories(): void
    {
        foreach ([self::root(), self::uploads(), self::backups(), self::staging(), self::work()] as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }
        // Belt and braces: deny direct web access should the directory ever
        // end up under a public docroot on a misconfigured host.
        $ht = self::root().DIRECTORY_SEPARATOR.'.htaccess';
        if (! File::exists($ht)) {
            @File::put($ht, "Require all denied\nDeny from all\n");
        }
        $index = self::root().DIRECTORY_SEPARATOR.'index.html';
        if (! File::exists($index)) {
            @File::put($index, '');
        }
    }
}
