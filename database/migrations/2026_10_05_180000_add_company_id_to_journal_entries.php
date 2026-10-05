<?php

use App\Services\AccountingV2\JournalSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multicompany for Accounting -> Journal Entries: acc_journal_entries gets the
 * company it belongs to (the list showed every company's entries). Existing
 * entries: the source document's company (sale, payment, expense...), else
 * the company of the accounts its lines use, else its creator's company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('acc_journal_entries', 'company_id')) {
            Schema::table('acc_journal_entries', function (Blueprint $t) {
                $t->unsignedInteger('company_id')->nullable()->index()->after('id');
            });
        }

        foreach (DB::table('acc_journal_entries')->whereNull('company_id')->get(['id', 'reference_type', 'reference_id', 'created_by']) as $e) {
            $cid = JournalSource::companyOf($e->reference_type, $e->reference_id)
                ?: DB::table('acc_journal_entry_lines as l')->join('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
                    ->where('l.journal_entry_id', $e->id)->whereNotNull('c.company_id')
                    ->groupBy('c.company_id')->orderByRaw('COUNT(*) DESC')->value('c.company_id')
                ?: ($e->created_by ? DB::table('users')->where('id', $e->created_by)->value('company_id') : null);
            if ($cid) {
                DB::table('acc_journal_entries')->where('id', $e->id)->update(['company_id' => $cid]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('acc_journal_entries', 'company_id')) {
            Schema::table('acc_journal_entries', function (Blueprint $t) {
                $t->dropIndex(['company_id']);
                $t->dropColumn('company_id');
            });
        }
    }
};
