<?php

namespace App\Services\AccountingV2;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FEATURE - SAFE ADDITION
 *
 * Generates double-entry journal entries without modifying legacy modules.
 * All writes are to new acc_* tables only.
 *
 * Per-company charts: each posting first finds the company of its document
 * (companyOf()) and posts to that company's own accounts -- the old fixed
 * codes map to the company chart via DefaultAccounting::POSTING_CODES, and an
 * expense posts to its category's account (coa_code). A company without its
 * own chart (or a document whose company can't be told) keeps posting to the
 * older shared accounts, exactly as before.
 */
class AccountingHelper
{
    /** Company of the document being posted (null = shared accounts). */
    protected static ?int $companyId = null;

    /** The company a document belongs to: its own, its location's, its parent document's, or its user's. */
    protected static function companyOf($doc): ?int
    {
        return self::safeReturn(function () use ($doc) {
            if (! $doc) {
                return null;
            }
            $get = fn ($k) => is_array($doc) ? ($doc[$k] ?? null) : ($doc->{$k} ?? null);
            if ($get('company_id')) {
                return (int) $get('company_id');
            }
            if ($get('warehouse_id')) {
                $cid = DB::table('warehouses')->where('id', $get('warehouse_id'))->value('company_id');
                if ($cid) {
                    return (int) $cid;
                }
            }
            foreach (['sale_id' => 'sales', 'purchase_id' => 'purchases', 'sale_return_id' => 'sale_returns', 'purchase_return_id' => 'purchase_returns'] as $fk => $table) {
                if ($get($fk)) {
                    $cid = DB::table($table)->where('id', $get($fk))->value('company_id');
                    if ($cid) {
                        return (int) $cid;
                    }
                }
            }
            if ($get('user_id')) {
                $cid = DB::table('users')->where('id', $get('user_id'))->value('company_id');
                if ($cid) {
                    return (int) $cid;
                }
            }

            return null;
        });
    }

    /** The company's own account for a posting code (null when it has none). */
    protected static function companyCoa(string $legacyCode): ?int
    {
        $cid = self::$companyId;
        $code = \App\Support\DefaultAccounting::POSTING_CODES[$legacyCode] ?? null;
        if (! $cid || ! $code || ! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            return null;
        }
        $id = DB::table('acc_chart_of_accounts')->where('company_id', $cid)->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    /** An expense's account: its category's account in the company chart, else the general expense account. */
    protected static function expenseCoa($expense): int
    {
        $cid = self::$companyId;
        $categoryId = $expense->expense_category_id ?? null;
        if ($cid && $categoryId && Schema::hasColumn('expense_categories', 'coa_code')) {
            $code = DB::table('expense_categories')->where('id', $categoryId)->value('coa_code');
            $id = $code ? DB::table('acc_chart_of_accounts')->where('company_id', $cid)->where('code', $code)->value('id') : null;
            if ($id) {
                return (int) $id;
            }
        }

        return self::getOrCreateCoa('EXP', 'Expenses', 'expense');
    }
    public static function fromSale($sale): void
    {
        self::$companyId = self::companyOf($sale);
        self::safe(function () use ($sale) {
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $revCoaId = self::getOrCreateCoa('4000-REV', 'Sales Revenue', 'income');
            $amount = (float) ($sale->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($sale->date ?? null),
                    'description' => 'Sale #'.($sale->Ref ?? $sale->id ?? ''),
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $arCoaId,  'debit' => $amount, 'credit' => 0,       'memo' => 'Accounts Receivable'],
                    ['coa_id' => $revCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Sales Revenue'],
                ],
            ]);
        });
    }

    public static function fromPurchase($purchase): void
    {
        self::$companyId = self::companyOf($purchase);
        self::safe(function () use ($purchase) {
            $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($purchase->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($purchase->date ?? null),
                    'description' => 'Purchase #'.($purchase->Ref ?? $purchase->id ?? ''),
                    'reference_type' => 'purchase',
                    'reference_id' => $purchase->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $invCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => 'Inventory'],
                    ['coa_id' => $apCoaId,  'debit' => 0,       'credit' => $amount, 'memo' => 'Accounts Payable'],
                ],
            ]);
        });
    }

    public static function fromSaleReturn($saleReturn): void
    {
        self::$companyId = self::companyOf($saleReturn);
        self::safe(function () use ($saleReturn) {
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $retCoaId = self::getOrCreateCoa('4010-RET', 'Sales Returns', 'income');
            $amount = (float) ($saleReturn->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($saleReturn->date ?? null),
                    'description' => 'Sale Return #'.($saleReturn->Ref ?? $saleReturn->id ?? ''),
                    'reference_type' => 'sale_return',
                    'reference_id' => $saleReturn->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $retCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => 'Sales Return'],
                    ['coa_id' => $arCoaId,  'debit' => 0,       'credit' => $amount, 'memo' => 'Reduce A/R'],
                ],
            ]);
        });
    }

    public static function fromSaleAdjustment($sale): void
    {
        self::$companyId = self::companyOf($sale);
        self::safe(function () use ($sale) {
            $current = self::getPostedAmount('sale', $sale->id ?? 0);
            $target = (float) ($sale->GrandTotal ?? 0);
            $delta = round($target - $current, 6);
            if (abs($delta) <= 0.0001) {
                return;
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $revCoaId = self::getOrCreateCoa('4000-REV', 'Sales Revenue', 'income');
            if ($delta > 0) {
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($sale->date ?? null),
                        'description' => 'Sale Adjustment #'.($sale->Ref ?? $sale->id ?? ''),
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $arCoaId,  'debit' => $delta, 'credit' => 0,      'memo' => 'A/R Adj'],
                        ['coa_id' => $revCoaId, 'debit' => 0,      'credit' => $delta, 'memo' => 'Revenue Adj'],
                    ],
                ]);
            } else {
                $delta = abs($delta);
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($sale->date ?? null),
                        'description' => 'Sale Adjustment #'.($sale->Ref ?? $sale->id ?? ''),
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $revCoaId, 'debit' => $delta, 'credit' => 0,      'memo' => 'Revenue Adj'],
                        ['coa_id' => $arCoaId,  'debit' => 0,      'credit' => $delta, 'memo' => 'A/R Adj'],
                    ],
                ]);
            }
        });
    }

    public static function fromPurchaseAdjustment($purchase): void
    {
        self::$companyId = self::companyOf($purchase);
        self::safe(function () use ($purchase) {
            $current = self::getPostedAmount('purchase', $purchase->id ?? 0);
            $target = (float) ($purchase->GrandTotal ?? 0);
            $delta = round($target - $current, 6);
            if (abs($delta) <= 0.0001) {
                return;
            }
            $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            if ($delta > 0) {
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($purchase->date ?? null),
                        'description' => 'Purchase Adjustment #'.($purchase->Ref ?? $purchase->id ?? ''),
                        'reference_type' => 'purchase',
                        'reference_id' => $purchase->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $invCoaId, 'debit' => $delta, 'credit' => 0,      'memo' => 'Inventory Adj'],
                        ['coa_id' => $apCoaId,  'debit' => 0,      'credit' => $delta, 'memo' => 'A/P Adj'],
                    ],
                ]);
            } else {
                $delta = abs($delta);
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($purchase->date ?? null),
                        'description' => 'Purchase Adjustment #'.($purchase->Ref ?? $purchase->id ?? ''),
                        'reference_type' => 'purchase',
                        'reference_id' => $purchase->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $apCoaId,  'debit' => $delta, 'credit' => 0,      'memo' => 'A/P Adj'],
                        ['coa_id' => $invCoaId, 'debit' => 0,      'credit' => $delta, 'memo' => 'Inventory Adj'],
                    ],
                ]);
            }
        });
    }

    public static function fromExpenseAdjustment($expense): void
    {
        self::$companyId = self::companyOf($expense);
        self::safe(function () use ($expense) {
            $current = self::getPostedAmount('expense', $expense->id ?? 0);
            $target = (float) ($expense->amount ?? $expense->total ?? 0);
            $delta = round($target - $current, 6);
            if (abs($delta) <= 0.0001) {
                return;
            }
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($expense->account_id) ?: self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            $expenseCoaId = self::expenseCoa($expense);
            if ($delta > 0) {
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($expense->date ?? null),
                        'description' => 'Expense Adjustment #'.($expense->id ?? ''),
                        'reference_type' => 'expense',
                        'reference_id' => $expense->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $expenseCoaId, 'debit' => $delta, 'credit' => 0,      'memo' => 'Expense Adj'],
                        ['coa_id' => $cashCoaId,    'debit' => 0,      'credit' => $delta, 'memo' => 'Cash Adj'],
                    ],
                ]);
            } else {
                $delta = abs($delta);
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($expense->date ?? null),
                        'description' => 'Expense Adjustment #'.($expense->id ?? ''),
                        'reference_type' => 'expense',
                        'reference_id' => $expense->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $cashCoaId,    'debit' => $delta, 'credit' => 0,      'memo' => 'Cash Adj'],
                        ['coa_id' => $expenseCoaId, 'debit' => 0,      'credit' => $delta, 'memo' => 'Expense Adj'],
                    ],
                ]);
            }
        });
    }

    public static function reverseSale($sale): void
    {
        self::$companyId = self::companyOf($sale);
        self::reverseByReference('sale', $sale->id ?? 0, 'Reverse Sale #'.($sale->Ref ?? $sale->id ?? ''));
    }

    public static function reversePurchase($purchase): void
    {
        self::$companyId = self::companyOf($purchase);
        self::reverseByReference('purchase', $purchase->id ?? 0, 'Reverse Purchase #'.($purchase->Ref ?? $purchase->id ?? ''));
    }

    public static function reverseExpense($expense): void
    {
        self::$companyId = self::companyOf($expense);
        self::reverseByReference('expense', $expense->id ?? 0, 'Reverse Expense #'.($expense->id ?? ''));
    }

    protected static function reverseByReference(string $referenceType, int $referenceId, string $desc): void
    {
        self::safe(function () use ($referenceType, $referenceId, $desc) {
            if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
                return;
            }
            $groups = DB::table('acc_journal_entries as j')
                ->join('acc_journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
                ->select('l.coa_id', 'l.account_id', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'))
                ->where('j.status', 'posted')
                ->where('j.reference_type', $referenceType)
                ->where('j.reference_id', $referenceId)
                ->groupBy('l.coa_id', 'l.account_id')
                ->get();
            if ($groups->isEmpty()) {
                return;
            }
            $lines = [];
            foreach ($groups as $g) {
                $net = round((float) $g->debit - (float) $g->credit, 6);
                if (abs($net) <= 0.0001) {
                    continue;
                }
                if ($net > 0) {
                    // previously debit > credit -> now credit net
                    $lines[] = ['coa_id' => $g->coa_id, 'account_id' => $g->account_id, 'debit' => 0, 'credit' => abs($net), 'memo' => 'Reversal'];
                } else {
                    $lines[] = ['coa_id' => $g->coa_id, 'account_id' => $g->account_id, 'debit' => abs($net), 'credit' => 0, 'memo' => 'Reversal'];
                }
            }
            if (empty($lines)) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => Carbon::now()->toDateString(),
                    'description' => $desc,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ],
                'lines' => $lines,
            ]);
        });
    }

    protected static function getPostedAmount(string $referenceType, int $referenceId): float
    {
        return (float) self::safeReturn(function () use ($referenceType, $referenceId) {
            if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
                return 0.0;
            }
            // Net each account first, then sum the net-debit side. Adjustment
            // entries add equally to raw debit and credit totals, so
            // max(debits, credits) overstates the posted amount after any edit
            // and later deltas would compound the error.
            $rows = DB::table('acc_journal_entries as j')
                ->join('acc_journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
                ->select('l.coa_id', DB::raw('SUM(l.debit) as debits'), DB::raw('SUM(l.credit) as credits'))
                ->where('j.status', 'posted')
                ->where('j.reference_type', $referenceType)
                ->where('j.reference_id', $referenceId)
                ->groupBy('l.coa_id')
                ->get();
            $amount = 0.0;
            foreach ($rows as $row) {
                $net = (float) ($row->debits ?? 0) - (float) ($row->credits ?? 0);
                if ($net > 0) {
                    $amount += $net;
                }
            }

            return $amount;
        }) ?: 0.0;
    }

    public static function fromExpense($expense): void
    {
        self::$companyId = self::companyOf($expense);
        self::safe(function () use ($expense) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($expense->account_id) ?: self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            $expenseCoaId = self::expenseCoa($expense);
            $amount = (float) ($expense->amount ?? $expense->total ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($expense->date ?? null),
                    'description' => 'Expense #'.($expense->id ?? ''),
                    'reference_type' => 'expense',
                    'reference_id' => $expense->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $expenseCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Expense'],
                    ['coa_id' => $cashCoaId,    'debit' => 0,      'credit' => $amount, 'memo' => 'Cash'],
                ],
            ]);
        });
    }

    public static function fromPaymentSale($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Sale #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
                    ['coa_id' => $arCoaId,     'debit' => 0,       'credit' => $amount, 'memo' => 'Clear A/R'],
                ],
            ]);
        });
    }

    public static function fromPaymentPurchase($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            // payment_purchases keeps the amount in `montant` (like the other
            // payments); reading only `amount` skipped every purchase payment.
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Purchase #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $apCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Reduce A/P'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Out'],
                ],
            ]);
        });
    }

    public static function reversePaymentSale($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Sale #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $arCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Reinstate A/R'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Out'],
                ],
            ]);
        });
    }

    public static function reversePaymentPurchase($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Purchase #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
                    ['coa_id' => $apCoaId,  'debit' => 0,       'credit' => $amount, 'memo' => 'Reinstate A/P'],
                ],
            ]);
        });
    }

    public static function fromDeposit($deposit): void
    {
        self::$companyId = self::companyOf($deposit);
        self::safe(function () use ($deposit) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($deposit->account_id);
            $equityCoaId = self::getOrCreateCoa(Config::get('accounting_v2.codes.retained_earnings', '3100'), 'Retained Earnings', 'equity');
            $amount = (float) ($deposit->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($deposit->date ?? null),
                    'description' => 'Deposit #'.($deposit->id ?? ''),
                    'reference_type' => 'deposit',
                    'reference_id' => $deposit->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
                    ['coa_id' => $equityCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Equity'],
                ],
            ]);
        });
    }

    public static function fromTransferMoney($transfer): void
    {
        self::$companyId = self::companyOf($transfer);
        self::safe(function () use ($transfer) {
            $fromCoaId = self::getOrCreateCoaForLegacyAccount($transfer->from_account_id ?? $transfer->account_from_id ?? null);
            $toCoaId = self::getOrCreateCoaForLegacyAccount($transfer->to_account_id ?? $transfer->account_to_id ?? null);
            $amount = (float) ($transfer->amount ?? 0);
            if ($amount <= 0 || ! $fromCoaId || ! $toCoaId) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($transfer->date ?? null),
                    'description' => 'Transfer #'.($transfer->id ?? ''),
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $toCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'To'],
                    ['coa_id' => $fromCoaId, 'debit' => 0,      'credit' => $amount, 'memo' => 'From'],
                ],
            ]);
        });
    }

    public static function fromPurchaseReturn($purchaseReturn): void
    {
        self::$companyId = self::companyOf($purchaseReturn);
        self::safe(function () use ($purchaseReturn) {
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $retCoaId = self::getOrCreateCoa('5010-PRET', 'Purchase Returns', 'expense');
            $amount = (float) ($purchaseReturn->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($purchaseReturn->date ?? null),
                    'description' => 'Purchase Return #'.($purchaseReturn->Ref ?? $purchaseReturn->id ?? ''),
                    'reference_type' => 'purchase_return',
                    'reference_id' => $purchaseReturn->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $apCoaId,  'debit' => $amount, 'credit' => 0,       'memo' => 'Reduce A/P'],
                    ['coa_id' => $retCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Purchase Return'],
                ],
            ]);
        });
    }

    public static function fromPurchaseReturnAdjustment($purchaseReturn): void
    {
        self::$companyId = self::companyOf($purchaseReturn);
        self::safe(function () use ($purchaseReturn) {
            $current = self::getPostedAmount('purchase_return', $purchaseReturn->id ?? 0);
            $target = (float) ($purchaseReturn->GrandTotal ?? 0);
            $delta = round($target - $current, 6);
            if (abs($delta) <= 0.0001) {
                return;
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $retCoaId = self::getOrCreateCoa('5010-PRET', 'Purchase Returns', 'expense');
            if ($delta > 0) {
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($purchaseReturn->date ?? null),
                        'description' => 'Purchase Return Adjustment #'.($purchaseReturn->Ref ?? $purchaseReturn->id ?? ''),
                        'reference_type' => 'purchase_return',
                        'reference_id' => $purchaseReturn->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $apCoaId,  'debit' => $delta, 'credit' => 0,      'memo' => 'A/P Adj'],
                        ['coa_id' => $retCoaId, 'debit' => 0,      'credit' => $delta, 'memo' => 'Return Adj'],
                    ],
                ]);
            } else {
                $delta = abs($delta);
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($purchaseReturn->date ?? null),
                        'description' => 'Purchase Return Adjustment #'.($purchaseReturn->Ref ?? $purchaseReturn->id ?? ''),
                        'reference_type' => 'purchase_return',
                        'reference_id' => $purchaseReturn->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $retCoaId, 'debit' => $delta, 'credit' => 0,      'memo' => 'Return Adj'],
                        ['coa_id' => $apCoaId,  'debit' => 0,      'credit' => $delta, 'memo' => 'A/P Adj'],
                    ],
                ]);
            }
        });
    }

    public static function reversePurchaseReturn($purchaseReturn): void
    {
        self::$companyId = self::companyOf($purchaseReturn);
        self::reverseByReference('purchase_return', $purchaseReturn->id ?? 0, 'Reverse Purchase Return #'.($purchaseReturn->Ref ?? $purchaseReturn->id ?? ''));
    }

    public static function fromSaleReturnAdjustment($saleReturn): void
    {
        self::$companyId = self::companyOf($saleReturn);
        self::safe(function () use ($saleReturn) {
            $current = self::getPostedAmount('sale_return', $saleReturn->id ?? 0);
            $target = (float) ($saleReturn->GrandTotal ?? 0);
            $delta = round($target - $current, 6);
            if (abs($delta) <= 0.0001) {
                return;
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $retCoaId = self::getOrCreateCoa('4010-RET', 'Sales Returns', 'income');
            if ($delta > 0) {
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($saleReturn->date ?? null),
                        'description' => 'Sale Return Adjustment #'.($saleReturn->Ref ?? $saleReturn->id ?? ''),
                        'reference_type' => 'sale_return',
                        'reference_id' => $saleReturn->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $retCoaId, 'debit' => $delta, 'credit' => 0,      'memo' => 'Return Adj'],
                        ['coa_id' => $arCoaId,  'debit' => 0,      'credit' => $delta, 'memo' => 'A/R Adj'],
                    ],
                ]);
            } else {
                $delta = abs($delta);
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($saleReturn->date ?? null),
                        'description' => 'Sale Return Adjustment #'.($saleReturn->Ref ?? $saleReturn->id ?? ''),
                        'reference_type' => 'sale_return',
                        'reference_id' => $saleReturn->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $arCoaId,  'debit' => $delta, 'credit' => 0,      'memo' => 'A/R Adj'],
                        ['coa_id' => $retCoaId, 'debit' => 0,      'credit' => $delta, 'memo' => 'Return Adj'],
                    ],
                ]);
            }
        });
    }

    public static function reverseSaleReturn($saleReturn): void
    {
        self::$companyId = self::companyOf($saleReturn);
        self::reverseByReference('sale_return', $saleReturn->id ?? 0, 'Reverse Sale Return #'.($saleReturn->Ref ?? $saleReturn->id ?? ''));
    }

    public static function fromPaymentSaleReturn($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Sale Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $arCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Settle Customer Credit'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Refunded'],
                ],
            ]);
        });
    }

    public static function reversePaymentSaleReturn($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Sale Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => 'Cash Reinstated'],
                    ['coa_id' => $arCoaId,   'debit' => 0,       'credit' => $amount, 'memo' => 'Reinstate Customer Credit'],
                ],
            ]);
        });
    }

    public static function fromPaymentPurchaseReturn($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Purchase Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => 'Cash Received'],
                    ['coa_id' => $apCoaId,   'debit' => 0,       'credit' => $amount, 'memo' => 'Settle Supplier Credit'],
                ],
            ]);
        });
    }

    public static function reversePaymentPurchaseReturn($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Purchase Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $apCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Reinstate Supplier Credit'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Reversed'],
                ],
            ]);
        });
    }

    public static function fromCashRegisterOpening($cashRegister): void
    {
        self::$companyId = self::companyOf($cashRegister);
        self::safe(function () use ($cashRegister) {
            $amount = (float) ($cashRegister->opening_balance ?? 0);
            if ($amount <= 0) {
                return;
            }
            $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            $equityCoaId = self::getOrCreateCoa(Config::get('accounting_v2.codes.retained_earnings', '3100'), 'Retained Earnings', 'equity');
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($cashRegister->opened_at ?? $cashRegister->created_at ?? null),
                    'description' => 'Cash Register Opening #'.($cashRegister->id ?? ''),
                    'reference_type' => 'cash_register_open',
                    'reference_id' => $cashRegister->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Opening Float'],
                    ['coa_id' => $equityCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Opening Balance'],
                ],
            ]);
        });
    }

    /**
     * Hospital Management (2026-10-20): a hospital invoice = Accounts
     * Receivable / Revenue, like a sale; a hospital payment = Cash / clear A/R,
     * like a sale payment -- on the invoice's company chart (its company_id).
     * App\Services\HospitalAccounting decides when (and replaces old entries).
     */
    public static function fromHospitalInvoice($invoice): void
    {
        self::$companyId = self::companyOf($invoice);
        self::safe(function () use ($invoice) {
            $amount = (float) ($invoice->total ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($invoice->invoice_date ?? null),
                    'description' => 'Hospital invoice '.($invoice->reference ?? '#'.$invoice->id),
                    'reference_type' => 'hospital_invoice',
                    'reference_id' => $invoice->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset'), 'debit' => $amount, 'credit' => 0, 'memo' => 'Accounts Receivable'],
                    ['coa_id' => self::getOrCreateCoa('4000-REV', 'Sales Revenue', 'income'), 'debit' => 0, 'credit' => $amount, 'memo' => 'Patient services revenue'],
                ],
            ]);
        });
    }

    public static function fromHospitalPayment($payment): void
    {
        self::$companyId = self::companyOf($payment);
        self::safe(function () use ($payment) {
            $amount = (float) ($payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->paid_on ?? null),
                    'description' => 'Hospital payment '.($payment->reference ?? '#'.$payment->id),
                    'reference_type' => 'hospital_payment',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => self::getOrCreateCoa('1000-CASH', 'Cash', 'asset'), 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In ('.($payment->method ?? 'cash').')'],
                    ['coa_id' => self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset'), 'debit' => 0, 'credit' => $amount, 'memo' => 'Clear A/R'],
                ],
            ]);
        });
    }

    public static function createBalancedEntry(array $data): void
    {
        self::safe(function () use ($data) {
            if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
                return; // not installed yet
            }

            DB::transaction(function () use ($data) {
                $journalId = DB::table('acc_journal_entries')->insertGetId([
                    // Multicompany: the posting's company, else its source document's.
                    'company_id' => self::$companyId
                        ?? JournalSource::companyOf($data['journal']['reference_type'] ?? null, $data['journal']['reference_id'] ?? null),
                    'date' => $data['journal']['date'] ?? Carbon::now()->toDateString(),
                    'reference_type' => $data['journal']['reference_type'] ?? null,
                    'reference_id' => $data['journal']['reference_id'] ?? null,
                    'description' => $data['journal']['description'] ?? null,
                    'status' => Config::get('accounting_v2.auto_post_journals', true) ? 'posted' : 'draft',
                    'posted_at' => Config::get('accounting_v2.auto_post_journals', true) ? Carbon::now() : null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $lines = $data['lines'] ?? [];
                foreach ($lines as $line) {
                    DB::table('acc_journal_entry_lines')->insert([
                        'journal_entry_id' => $journalId,
                        'coa_id' => $line['coa_id'] ?? null,
                        'account_id' => $line['account_id'] ?? null,
                        'debit' => (float) ($line['debit'] ?? 0),
                        'credit' => (float) ($line['credit'] ?? 0),
                        'memo' => $line['memo'] ?? null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }
            });
        });
    }

    public static function getOrCreateCoaForLegacyAccount($accountId): ?int
    {
        if (! $accountId) {
            return null;
        }

        return self::safeReturn(function () use ($accountId) {
            if (! Schema::hasTable('acc_chart_of_accounts')) {
                return null;
            }
            $existing = DB::table('acc_chart_of_accounts')->where('account_id', $accountId)->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $legacy = DB::table('accounts')->where('id', $accountId)->first();
            $code = '1010-'.(string) $accountId;
            $name = $legacy->account_name ?? ('Account #'.$accountId);

            return DB::table('acc_chart_of_accounts')->insertGetId([
                'account_id' => $accountId,
                'code' => $code,
                'name' => $name,
                'type' => 'asset',
                'parent_id' => null,
                'level' => 1,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    public static function getOrCreateCoa(string $code, string $name, string $type): int
    {
        return self::safeReturn(function () use ($code, $name, $type) {
            if (! Schema::hasTable('acc_chart_of_accounts')) {
                return 0;
            }
            // The posting company's own account (per-company charts).
            if ($own = self::companyCoa($code)) {
                return $own;
            }
            // Older shared accounts only -- never another company's row.
            $existing = DB::table('acc_chart_of_accounts')->where('code', $code)
                ->when(Schema::hasColumn('acc_chart_of_accounts', 'company_id'), fn ($q) => $q->whereNull('company_id'))
                ->first();
            if ($existing) {
                return (int) $existing->id;
            }

            return DB::table('acc_chart_of_accounts')->insertGetId([
                'account_id' => null,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_id' => null,
                'level' => 0,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    protected static function dateFrom($date): string
    {
        try {
            return $date ? Carbon::parse($date)->toDateString() : Carbon::now()->toDateString();
        } catch (\Throwable $e) {
            return Carbon::now()->toDateString();
        }
    }

    protected static function safe(callable $fn): void
    {
        try {
            if (! config('accounting_v2.enabled', true)) {
                return;
            }
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[AccountingV2] '.$e->getMessage());
        }
    }

    protected static function safeReturn(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('[AccountingV2] '.$e->getMessage());

            return null;
        }
    }
}
