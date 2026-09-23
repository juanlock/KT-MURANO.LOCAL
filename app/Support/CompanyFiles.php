<?php

namespace App\Support;

/**
 * Single place for a "files/company_{id}/{module}/..." convention, so
 * uploaded files (product images, logos, avatars, sale/purchase documents,
 * etc.) are isolated per company instead of one shared folder for the whole
 * install -- matches billing's app/Support/CompanyFiles.php exactly, and
 * this exact "files/company_{id}/{module}" shape already exists and works
 * on disk in this repo (e.g. companies.logo -> files/company_1/logos/...).
 * `public/images/` root stays reserved for install-wide defaults/system
 * assets, not company data.
 *
 * Only NEW uploads go through this -- files already on disk under the old
 * flat images/{module}/ paths are left in place (their DB rows still point
 * there and keep working); this is not a retroactive migration.
 */
class CompanyFiles
{
    /**
     * Relative path (from public/) to a company's folder for a given
     * module, e.g. dir(2, 'products') -> 'files/company_2/products'.
     * Creates the directory on disk if it doesn't exist yet and returns
     * the relative path -- callers store "relPath/filename" in the DB and
     * resolve the absolute path with public_path() only when writing/
     * deleting a file.
     */
    public static function dir(int $companyId, string $module): string
    {
        $rel = 'files/company_'.$companyId.'/'.$module;
        $abs = public_path($rel);
        if (! is_dir($abs)) {
            mkdir($abs, 0755, true);
        }

        return $rel;
    }
}
