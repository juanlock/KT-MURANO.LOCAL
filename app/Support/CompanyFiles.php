<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Single place for a "files/company_{id}/{module}/..." convention, so
 * uploaded files (product images, logos, avatars, sale/purchase documents,
 * helpdesk / email attachments, etc.) are isolated per company instead of one
 * shared folder for the whole install -- matches billing's
 * app/Support/CompanyFiles.php, and this exact "files/company_{id}/{module}"
 * shape already exists and works on disk in this repo (e.g. companies.logo ->
 * files/company_1/logos/...). `public/images/` root stays reserved for
 * install-wide defaults/system assets, not company data.
 *
 * PROJECT STANDARD (CLAUDE.md -> "File uploads"): every module that stores
 * files writes them here, public/files/company_{id}/{module}/, through this
 * class -- never storage/app or a shared folder.
 *
 * Private modules (attachments, bills, documents nobody should open by URL)
 * pass $private = true: the module folder gets a .htaccess that blocks
 * direct web access, and the files are served only by an authorized
 * controller (CompanyFiles::download).
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
     * deleting a file. $private: block direct web access to the folder.
     */
    public static function dir(int $companyId, string $module, bool $private = false): string
    {
        $rel = 'files/company_'.$companyId.'/'.$module;
        $abs = public_path($rel);
        if (! is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
        if ($private) {
            self::protect($abs);
        }

        return $rel;
    }

    /** No direct URL to this folder (Apache 2.2 / 2.4 / LiteSpeed); PHP still reads it. */
    public static function protect(string $absDir): void
    {
        $file = rtrim($absDir, '/\\').DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($file)) {
            file_put_contents($file, "# Private module files: download through the app only.\n"
                ."<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                ."<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
    }

    /**
     * Saves bytes as files/company_{id}/{module}/{Y}/{m}/{random}-{name};
     * returns the path relative to public/ (what goes in the DB).
     */
    public static function putBytes(int $companyId, string $module, string $name, string $bytes, bool $private = false): string
    {
        $dir = self::dir($companyId, $module, $private).'/'.date('Y/m');
        if (! is_dir(public_path($dir))) {
            mkdir(public_path($dir), 0755, true);
        }
        $rel = $dir.'/'.Str::random(12).'-'.self::safeName($name);
        file_put_contents(public_path($rel), $bytes);

        return $rel;
    }

    /**
     * A photo saved as a JPEG of at most 1600 px (camera photos are large), turned upright
     * from the camera's EXIF orientation; null if the bytes are not a picture.
     */
    public static function putPhoto(int $companyId, string $module, string $bytes, string $name, bool $private = false): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $img = $info ? @imagecreatefromstring($bytes) : false;
        if (! $img) {
            return null;
        }
        if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
            $turn = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($turn) {
                $img = imagerotate($img, $turn, 0);
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $k = min(1, 1600 / max($w, $h));
        if ($k < 1) {
            $small = imagecreatetruecolor((int) round($w * $k), (int) round($h * $k));
            imagecopyresampled($small, $img, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
            $img = $small;
        }
        ob_start();
        imagejpeg($img, null, 85);
        $out = (string) ob_get_clean();

        return self::putBytes($companyId, $module, (Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'photo').'.jpg', $out, $private);
    }

    /** Same as putBytes for an uploaded file. */
    public static function putUpload(int $companyId, string $module, UploadedFile $file, bool $private = false): string
    {
        $dir = self::dir($companyId, $module, $private).'/'.date('Y/m');
        $name = Str::random(12).'-'.self::safeName($file->getClientOriginalName());
        $file->move(public_path($dir), $name);

        return $dir.'/'.$name;
    }

    /** Is $rel a file of this company + module (no ../ tricks) that exists? */
    public static function owns(?string $rel, int $companyId, string $module): bool
    {
        $rel = (string) $rel;

        return $rel !== '' && ! str_contains($rel, '..')
            && str_starts_with($rel, 'files/company_'.$companyId.'/'.$module.'/')
            && is_file(public_path($rel));
    }

    /** Download response for a stored file (call after owns() + permission checks). */
    public static function download(string $rel, ?string $name = null)
    {
        return response()->download(public_path($rel), $name ?: basename($rel));
    }

    /**
     * The company a request writes files for: an Admin's own company; a
     * SuperAdmin's company picked in the header (company_id), else their own
     * -- same rule as ScopesToCompany. For controllers without that trait.
     */
    public static function actingCompanyId(?\Illuminate\Http\Request $request = null): int
    {
        $request ??= request();
        $user = $request->user('api') ?? $request->user();
        if ($user && $user->type === 'SuperAdmin') {
            $cid = (int) $request->input('company_id', 0);
            if ($cid && $cid !== 999) {
                return $cid;
            }
        }

        return (int) (optional($user)->company_id ?: 1);
    }

    public static function safeName(string $name): string
    {
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name ?: 'file') ?: 'file';

        return Str::limit(trim($name), 120, '');
    }
}
