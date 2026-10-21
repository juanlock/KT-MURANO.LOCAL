<?php

namespace App\Services;

use App\Models\HospitalInvoice;
use App\Models\HospitalPayment;
use App\Services\AccountingV2\AccountingHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital billing -> Accounting (user's request, 2026-10-20). A hospital bills
 * through Hospital Management, not Sales, so its money must reach the books
 * from here:
 *  - an invoice posts Accounts Receivable / Revenue for its total; saved again
 *    -> its entry is replaced (the total may have changed); cancelled or
 *    deleted -> its entry is removed;
 *  - a payment posts Cash / clears A/R; deleted -> its entry is removed.
 * On the invoice's company chart. Only when the company's "Automatic journal
 * entries" is on (settings.auto_journal_enabled, else the config default),
 * like sales.
 */
class HospitalAccounting
{
    public static function syncInvoice(HospitalInvoice $invoice): void
    {
        self::remove('hospital_invoice', (int) $invoice->id);
        if (! $invoice->trashed() && $invoice->status !== 'cancelled' && (float) $invoice->total > 0 && self::enabled($invoice->company_id)) {
            AccountingHelper::fromHospitalInvoice($invoice);
        }
    }

    public static function postPayment(HospitalPayment $payment): void
    {
        if (self::enabled($payment->company_id)) {
            AccountingHelper::fromHospitalPayment($payment);
        }
    }

    public static function removePayment(HospitalPayment $payment): void
    {
        self::remove('hospital_payment', (int) $payment->id);
    }

    /** The company's "Automatic journal entries" switch (null = the config default). */
    public static function enabled(?int $companyId): bool
    {
        if (! Schema::hasTable('acc_journal_entries')) {
            return false;
        }
        $flag = $companyId && Schema::hasColumn('settings', 'auto_journal_enabled')
            ? DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('auto_journal_enabled')
            : null;

        return $flag !== null ? (bool) $flag : (bool) Config::get('accounting_v2.auto_generate_journals', false);
    }

    private static function remove(string $type, int $id): void
    {
        if (! $id || ! Schema::hasTable('acc_journal_entries')) {
            return;
        }
        $ids = DB::table('acc_journal_entries')->where('reference_type', $type)->where('reference_id', $id)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('acc_journal_entry_lines')->whereIn('journal_entry_id', $ids)->delete();
            DB::table('acc_journal_entries')->whereIn('id', $ids)->delete();
        }
    }
}
