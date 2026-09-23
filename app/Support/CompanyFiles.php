<?php

namespace App\Support;

/**
 * Single place for a "images/company_{id}/{module}/..." convention, so
 * uploaded files (product images, logos, avatars, sale/purchase documents,
 * etc.) are isolated per company instead of one shared folder for the whole
 * install -- mirrors billing's app/Support/CompanyFiles.php (files/company_{id}/...),
 * adapted to keep Murano's own existing "images/" base folder rather than
 * renaming it, since every already-stored path in the DB already assumes
 * that base.
 *
 * Only NEW uploads go through this -- files already on disk under the old
 * flat images/{module}/ paths are left in place (their DB rows still point
 * there and keep working); this is not a retroactive migration.
 */
class CompanyFiles
{
    /**
     * Relative path (from public/) to a company's folder for a given
     * module, e.g. dir(2, 'products') -> 'images/company_2/products'.
     * Creates the directory on disk if it doesn't exist yet and returns
     * the relative path -- callers store "relPath/filename" in the DB and
     * resolve the absolute path with public_path() only when writing/
     * deleting a file.
     */
    public static function dir(int $companyId, string $module): string
    {
        $rel = 'images/company_'.$companyId.'/'.$module;
        $abs = public_path($rel);
        if (! is_dir($abs)) {
            mkdir($abs, 0755, true);
        }

        return $rel;
    }
}
