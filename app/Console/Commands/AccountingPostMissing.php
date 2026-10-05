<?php

namespace App\Console\Commands;

use App\Services\AccountingV2\AccountingHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Accounting -> post the journal entries that are missing: documents that
 * reached the database without going through Murano's forms (imported from
 * Billing, demo data, bulk inserts) never fired the automatic journal.
 * Per company, for every sale, purchase, return, their payments and every
 * expense with no entry yet: the same posting the automatic journal makes
 * (AccountingHelper::fromX -- the company's own chart, the document's date).
 * Skips cancelled documents and drafts, and anything already posted, so it's
 * safe to run again. --dry-run only counts.
 */
class AccountingPostMissing extends Command
{
    protected $signature = 'accounting:post-missing {--company= : one company id} {--dry-run : only count}';

    protected $description = 'Post journal entries for documents that have none (imported / demo data)';

    /** reference_type => [helper method, query builder for the company's unposted documents] */
    private function sources(int $companyId): array
    {
        $whCompany = fn ($q, $col) => $q->whereIn($col, DB::table('warehouses')->where('company_id', $companyId)->select('id'));
        $docs = fn ($table) => DB::table($table)->where("{$table}.company_id", $companyId)->whereNull("{$table}.deleted_at")
            ->where(fn ($w) => $w->whereNull("{$table}.statut")->orWhere("{$table}.statut", '!=', 'cancelled'));
        $payments = fn ($table, $fk, $docTable) => DB::table($table)->whereNull("{$table}.deleted_at")
            ->whereIn("{$table}.{$fk}", DB::table($docTable)->where('company_id', $companyId)->select('id'));

        return [
            'sale' => ['fromSale', $docs('sales')->where('sales.is_draft', false)],
            'payment_sale' => ['fromPaymentSale', $payments('payment_sales', 'sale_id', 'sales')],
            'purchase' => ['fromPurchase', $docs('purchases')],
            'payment_purchase' => ['fromPaymentPurchase', $payments('payment_purchases', 'purchase_id', 'purchases')],
            'sale_return' => ['fromSaleReturn', $docs('sale_returns')],
            'payment_sale_return' => ['fromPaymentSaleReturn', $payments('payment_sale_returns', 'sale_return_id', 'sale_returns')],
            'purchase_return' => ['fromPurchaseReturn', $docs('purchase_returns')],
            'payment_purchase_return' => ['fromPaymentPurchaseReturn', $payments('payment_purchase_returns', 'purchase_return_id', 'purchase_returns')],
            'expense' => ['fromExpense', $whCompany(DB::table('expenses')->whereNull('expenses.deleted_at'), 'expenses.warehouse_id')],
        ];
    }

    public function handle(): int
    {
        $companies = DB::table('companies')->whereNull('deleted_at')
            ->when($this->option('company'), fn ($q) => $q->where('id', (int) $this->option('company')))
            ->orderBy('id')->get(['id', 'name']);
        $dry = (bool) $this->option('dry-run');
        $grand = 0;

        foreach ($companies as $co) {
            $counts = [];
            foreach ($this->sources((int) $co->id) as $type => [$method, $query]) {
                $table = $query->from;
                $unposted = (clone $query)->whereNotIn("{$table}.id", DB::table('acc_journal_entries')->where('reference_type', $type)->select('reference_id'));
                $n = (clone $unposted)->count();
                $counts[$type] = $n;
                if ($dry || ! $n) {
                    continue;
                }
                // By id (keyset), not by page: every posted row leaves the
                // "unposted" set, so offset pages would skip half of them.
                $unposted->select("{$table}.*")->chunkById(200, function ($rows) use ($method) {
                    foreach ($rows as $row) {
                        AccountingHelper::$method($row);
                    }
                }, "{$table}.id", 'id');
            }
            $total = array_sum($counts);
            $grand += $total;
            $this->line(($dry ? '[dry-run] ' : '')."{$co->id} {$co->name}: ".$total.' -- '.collect($counts)->filter()->map(fn ($n, $t) => "{$t} {$n}")->implode(', '));
        }
        $this->info(($dry ? 'Would post ' : 'Posted ').$grand.' entr'.($grand === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
