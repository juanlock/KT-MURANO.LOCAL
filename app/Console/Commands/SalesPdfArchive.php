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
}
