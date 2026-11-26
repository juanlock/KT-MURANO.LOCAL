<?php

namespace App\Console\Commands;

use App\Support\AuraSoftImport;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import a folder of AuraSoft / Jelaf Excel files at once (2026-11-26: the year's "Reporte producción por bus", downloaded
 * by fortnight) with the same importer as the upload button, oldest file first. A file already imported (same sha256) is
 * skipped. The files are copied first: the importer moves what it imports into the company's private folder.
 *
 *   php artisan transport:aurasoft-folder {folder} [--company=] [--dry]
 */
class TransportAuraSoftFolder extends Command
{
    protected $signature = 'transport:aurasoft-folder {folder} {--company= : company id (default: Turismo Cautivo)} {--dry : list only}';

    protected $description = 'Import every AuraSoft / Jelaf Excel of a folder (Turismo Cautivo).';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id'));
        $files = glob(rtrim($this->argument('folder'), '\\/').DIRECTORY_SEPARATOR.'*.xls*') ?: [];
        sort($files);
        if (! $cid || ! $files) {
            $this->error(! $cid ? 'Company not found.' : 'No Excel files in the folder.');

            return self::FAILURE;
        }
        $done = DB::table('transport_aurasoft_imports')->where('company_id', $cid)->whereNotNull('sha256')->pluck('sha256')->flip();
        $tmp = storage_path('app/jelaf/folder-import');
        @mkdir($tmp, 0775, true);
        $ok = $fail = $skip = 0;
        foreach ($files as $f) {
            $name = basename($f);
            if (isset($done[hash_file('sha256', $f)])) {
                $this->line("skip (already imported): $name");
                $skip++;
                continue;
            }
            if ($this->option('dry')) {
                $this->line("would import: $name");
                continue;
            }
            $copy = $tmp.DIRECTORY_SEPARATOR.$name;
            copy($f, $copy);
            try {
                $imp = AuraSoftImport::run($cid, new UploadedFile($copy, $name, null, null, true), null);
                $this->line(sprintf('%s: %s rows (new %s, changed %s, same %s, skipped %s)', $name, $imp->rows_total ?? '?', $imp->rows_new ?? '?',
                    $imp->rows_updated ?? '?', $imp->rows_same ?? '?', $imp->rows_skipped ?? '?'));
                $ok++;
            } catch (Throwable $e) {
                $this->error("$name: ".$e->getMessage());
                @unlink($copy);
                $fail++;
            }
        }
        $this->info("imported $ok, skipped $skip, failed $fail");

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
