<?php

namespace App\Support;

/**
 * Where the developer PCs keep database backups (user's rule, 2026-10-27): the archive
 * lives OUTSIDE the repo, in Google Drive -- G:\My Drive\Bck_WEBSITE-LOCAL\KT-MURANO\Versions\database
 * on every developer PC (or .env BACKUP_ARCHIVE_DIR) -- with one folder per PC
 * (<PC>/), production/ (production backups, from_prod_latest.zip, last_sync.json)
 * and HOSTINGER/. Only the copy of the latest backup stays in the repo, in
 * KT-TOOLS/database/. Without the archive folder (Drive not mounted) everything
 * falls back to KT-TOOLS/database/ as before.
 */
class BackupPaths
{
    public const DEFAULT_ARCHIVE = 'G:\\My Drive\\Bck_WEBSITE-LOCAL\\KT-MURANO\\Versions\\database';

    /** The archive root (Drive), else KT-TOOLS/database. */
    public static function archive(): string
    {
        $dir = trim((string) (env('BACKUP_ARCHIVE_DIR') ?: self::DEFAULT_ARCHIVE));

        return $dir !== '' && is_dir($dir) ? rtrim($dir, '\\/') : self::latest();
    }

    /** KT-TOOLS/database/ in the repo: only the copy of the latest backup. */
    public static function latest(): string
    {
        return base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'database');
    }

    public static function pcFolder(?string $host = null): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $host ?: (gethostname() ?: 'unknown-host'));
    }

    /** <archive>/<PC>/ (made when missing). */
    public static function pc(?string $host = null): string
    {
        return self::dir(self::archive().DIRECTORY_SEPARATOR.self::pcFolder($host));
    }

    /** <archive>/production/[file] (made when missing). */
    public static function production(string $file = ''): string
    {
        $dir = self::dir(self::archive().DIRECTORY_SEPARATOR.'production');

        return $file === '' ? $dir : $dir.DIRECTORY_SEPARATOR.$file;
    }

    /** <archive>/HOSTINGER/ (made when missing). */
    public static function hostinger(): string
    {
        return self::dir(self::archive().DIRECTORY_SEPARATOR.'HOSTINGER');
    }

    private static function dir(string $dir): string
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
