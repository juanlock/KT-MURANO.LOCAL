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
     * One PDF per invoice, named "Invoice_{Ref}.pdf" / "Invoice_{Ref} - Delete.pdf" (user's rules, 2026-11-15: every invoice
     * PDF starts with "Invoice_"; no earlier version is kept, "se repite"):
     *  - an older name "INV_1.pdf" becomes "Invoice_INV_1.pdf"; if that name is taken, the older copy is removed;
     *  - copies "Invoice_X (2).pdf" / "Invoice_X (anterior).pdf" are removed when "Invoice_X.pdf" (or its "- Delete") exists;
     *  - the earlier system's zero-padded "Invoice_INV_0005.pdf" is removed when the sale is INV_5 and has its own PDF;
     *  - an invoice with both "Invoice_X.pdf" and "Invoice_X - Delete.pdf": the one that matches the sale stays.
     */
    private function renameOld(): void
    {
        $dry = (bool) $this->option('dry');
        $renamed = $removed = 0;
        $drop = function (string $file) use ($dry, &$removed) {
            if (! $dry) {
                @unlink($file);
            }
            $removed++;
        };
        foreach (glob(public_path('files/company_*/'.SalePdfArchive::MODULE), GLOB_ONLYDIR) ?: [] as $dir) {
            $cid = (int) preg_replace('/\D/', '', basename(dirname($dir)));
            $sales = DB::table('sales')->where('company_id', $cid)->get(['Ref', 'deleted_at'])->keyBy('Ref');
            $has = fn (string $ref) => is_file($dir.'/Invoice_'.$ref.'.pdf') || is_file($dir.'/Invoice_'.$ref.' - Delete.pdf');
            foreach (glob($dir.'/*.pdf') ?: [] as $file) {
                $name = basename($file);
                if (! str_starts_with($name, 'Invoice_')) {
                    $target = $dir.'/Invoice_'.$name;
                    if (is_file($target)) {
                        $drop($file);
                    } else {
                        if (! $dry) {
                            @rename($file, $target);
                        }
                        $renamed++;
                    }
                    continue;
                }
                $base = substr($name, 8, -4);  // without "Invoice_" and ".pdf"
                if (preg_match('/^(.*) \((2|anterior)\)$/', $base, $m)) {
                    if ($has($m[1])) {
                        $drop($file);
                    }
                    continue;
                }
                $ref = preg_replace('/ - Delete$/', '', $base);
                if (! $sales->has($ref) && preg_match('/^(.*_)0+(\d+)$/', $ref, $m) && $sales->has($m[1].$m[2]) && $has($m[1].$m[2])) {
                    $drop($file);
                    continue;
                }
                $sale = $sales->get($ref);
                if ($sale && $base === $ref && is_file($dir.'/Invoice_'.$ref.' - Delete.pdf')) {
                    // both names: keep the one that matches the sale
                    $drop($sale->deleted_at ? $file : $dir.'/Invoice_'.$ref.' - Delete.pdf');
                }
            }
        }
        $this->line(($dry ? '[dry] ' : '')."renamed to Invoice_: $renamed | repeated copies removed: $removed");
    }
}
