<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Support\SalePdfArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Completes files/company_{id}/sales/ (user's rule, 2026-11-15): the PDF of every sale that has none yet (Murano's
 * "{Ref}.pdf" or the earlier system's "Invoice_{Ref}.pdf"); deleted sales get "{Ref} - Delete.pdf". Demo sales and drafts
 * are left out. Safe to run again: only what is missing is made.
 *
 *   php artisan sales:pdf-archive [--company=2] [--limit=500] [--dry]
 */
class SalesPdfArchive extends Command
{
    protected $signature = 'sales:pdf-archive {--company= : only this company} {--limit=0 : at most this many PDFs this run} {--dry : count only}';

    protected $description = 'Make the missing invoice PDFs in files/company_{id}/sales/ (deleted sales: "{Ref} - Delete.pdf")';

    public function handle(): int
    {
        @set_time_limit(0);
        $this->renameOld();
        $demo = DB::table('demo_records')->where('record_type', 'sales')->pluck('record_id')->flip();
        $q = Sale::query()->where(fn ($w) => $w->whereNull('is_draft')->orWhere('is_draft', 0)->orWhereNotNull('deleted_at'))
            ->when($this->option('company'), fn ($w, $c) => $w->where('company_id', (int) $c))
            ->orderBy('id');
        $limit = (int) $this->option('limit');
        $made = $deleted = $have = $failed = 0;
        $q->chunkById(200, function ($sales) use ($demo, $limit, &$made, &$deleted, &$have, &$failed) {
            foreach ($sales as $sale) {
                if ($demo->has($sale->id) || ! $sale->company_id || ! $sale->Ref) {
                    continue;
                }
                if ($sale->deleted_at) {
                    if (is_file(SalePdfArchive::path($sale, true))) {
                        $have++;
                        continue;
                    }
                    if (! $this->option('dry')) {
                        SalePdfArchive::markDeleted($sale);
                        is_file(SalePdfArchive::path($sale, true)) ? $deleted++ : $failed++;
                    } else {
                        $deleted++;
                    }
                } elseif (SalePdfArchive::exists($sale)) {
                    $have++;
                    continue;
                } else {
                    if (! $this->option('dry')) {
                        SalePdfArchive::save($sale) ? $made++ : $failed++;
                    } else {
                        $made++;
                    }
                }
                if ($limit && ($made + $deleted) >= $limit) {
                    return false;
                }
            }
        });
        $this->line(($this->option('dry') ? '[dry] ' : '')."made: $made | deleted (renamed or made): $deleted | already there: $have | failed: $failed");

        return self::SUCCESS;
    }

    /**
     * The names before 2026-11-15 ("INV_1.pdf", "INV_1 - Delete.pdf") become "Invoice_INV_1.pdf" / "Invoice_INV_1 - Delete.pdf"
     * (user's rule: every invoice PDF starts with "Invoice_"). Nothing is deleted: when the new name is already taken, the old
     * file is kept as "Invoice_INV_1 (2).pdf".
     */
    private function renameOld(): void
    {
        $renamed = $kept = 0;
        foreach (glob(public_path('files/company_*/'.SalePdfArchive::MODULE), GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir.'/*.pdf') ?: [] as $file) {
                $name = basename($file);
                if (str_starts_with($name, 'Invoice_')) {
                    continue;
                }
                $target = $dir.'/Invoice_'.$name;
                if (is_file($target)) {
                    $target = $dir.'/Invoice_'.substr($name, 0, -4).' (2).pdf';
                    $kept++;
                    if (is_file($target)) {
                        continue;
                    }
                }
                if (! $this->option('dry')) {
                    @rename($file, $target);
                }
                $renamed++;
            }
        }
        $this->line(($this->option('dry') ? '[dry] ' : '')."renamed to Invoice_: $renamed (kept as (2) because the name was taken: $kept)");
    }
}
