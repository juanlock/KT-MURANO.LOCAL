<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KcolTech's documents of 2026-11-18 (user: "me sale 004 y 005 cuando debe ser otro... que no salga TEST"): two invoices were
 * made before its prefixes were set right, so they took the old INV_ series (INV_0004, INV_0005) instead of the company's own
 * PP- series (last PP-0385), and its first quotation took QT-TEST_0001. They get the next numbers of the right series, in the
 * order they were made: the sale's Ref, its accounting entries' description and its stored invoice PDF
 * (files/company_1/sales/Invoice_{Ref}.pdf, renamed, never deleted); the quotation's Ref. Sent emails keep their subject
 * (history). Only those documents; safe to run twice (nothing left to rename the second time).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'KcolTech')->value('id');
        if (! $cid) {
            return;
        }
        $s = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['sale_prefix', 'quotation_prefix']);
        $salePrefix = rtrim(trim((string) ($s->sale_prefix ?? '')), '_-');
        $quotePrefix = rtrim(trim((string) ($s->quotation_prefix ?? '')), '_-');

        DB::transaction(function () use ($cid, $salePrefix, $quotePrefix) {
            // Invoices: only when the company's series is PP (what it is in production) and only today's INV_ ones.
            if ($salePrefix === 'PP') {
                $next = $this->top('sales', $cid, 'PP') + 1;
                $old = DB::table('sales')->where('company_id', $cid)->where('Ref', 'like', 'INV\_%')->where('date', '>=', '2026-11-18')->orderBy('id')->get(['id', 'Ref']);
                foreach ($old as $sale) {
                    $new = 'PP-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
                    DB::table('sales')->where('id', $sale->id)->update(['Ref' => $new, 'updated_at' => now()]);
                    if (Schema::hasTable('acc_journal_entries')) {
                        DB::table('acc_journal_entries')->where('description', 'like', '%'.$sale->Ref.'%')
                            ->when(Schema::hasColumn('acc_journal_entries', 'company_id'), fn ($q) => $q->where('company_id', $cid))
                            ->update(['description' => DB::raw('REPLACE(description, '.DB::getPdo()->quote($sale->Ref).', '.DB::getPdo()->quote($new).')')]);
                    }
                    $dir = public_path('files/company_'.$cid.'/sales');
                    foreach (glob($dir.'/Invoice_'.$sale->Ref.'*.pdf') ?: [] as $file) {
                        $target = $dir.'/'.str_replace('Invoice_'.$sale->Ref, 'Invoice_'.$new, basename($file));
                        if (! file_exists($target)) {
                            @rename($file, $target);
                        }
                    }
                }
            }
            // Quotations made with a TEST prefix.
            if ($quotePrefix !== '' && stripos($quotePrefix, 'TEST') === false) {
                $next = $this->top('quotations', $cid, $quotePrefix) + 1;
                foreach (DB::table('quotations')->where('company_id', $cid)->where('Ref', 'like', '%TEST%')->orderBy('id')->get(['id']) as $q) {
                    DB::table('quotations')->where('id', $q->id)->update(['Ref' => $quotePrefix.'-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT), 'updated_at' => now()]);
                }
            }
        });
    }

    /** The highest number of the series "{prefix}-NNNN" / "{prefix}_NNNN" (digits only after the separator). */
    private function top(string $table, int $cid, string $prefix): int
    {
        $max = 0;
        foreach (DB::table($table)->where('company_id', $cid)->where(fn ($q) => $q->where('Ref', 'like', $prefix.'-%')->orWhere('Ref', 'like', $prefix.'\_%'))->pluck('Ref') as $ref) {
            if (preg_match('/^'.preg_quote($prefix, '/').'[-_](\d+)$/', (string) $ref, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    public function down(): void
    {
    }
};
