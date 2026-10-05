<?php

use App\Services\EmailOffice\EmailOffice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bills vs their history (user's design, 2026-10-05): a supplier's payment
 * reminders and payment confirmations are not bills to record -- they go into
 * the history of the expense created from the bill.
 *  - office_emails.link_role: bill | reminder | payment (what an email linked
 *    to an expense is to it).
 *  - The automatically sorted, not yet recorded "bills" are sorted again with
 *    the new rules (payment confirmation -> payment, payment reminder ->
 *    reminder, notices -> general). Types set by hand are kept.
 *  - Emails already recorded as an expense get link_role = bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_emails')) {
            return;
        }
        if (! Schema::hasColumn('office_emails', 'link_role')) {
            Schema::table('office_emails', function (Blueprint $table) {
                $table->string('link_role', 12)->nullable()->after('linked_id');
            });
        }
        DB::table('office_emails')->where('linked_type', 'expense')->whereNull('link_role')->update(['link_role' => 'bill']);

        // Auto-sorted "bills" and "reminders" (the old rule called some
        // statements reminders), and a supplier's auto "general" emails (a
        // payment confirmation could have been general before).
        DB::table('office_emails')->where('direction', 'in')->where('category_manual', false)->whereNull('linked_type')
            ->where(fn ($q) => $q->whereIn('category', ['bill', 'reminder'])->orWhere(fn ($w) => $w->where('category', 'general')->where('party_type', 'provider')))
            ->orderBy('id')->select(['id', 'subject', 'body_text', 'party_type', 'category'])
            ->chunkById(300, function ($rows) {
                foreach ($rows as $e) {
                    $new = EmailOffice::classify('in', (string) $e->subject, (string) $e->body_text, [], $e->party_type);
                    // A "general" one only moves to payment / reminder (never becomes a bill here).
                    if ($new !== $e->category && ($e->category !== 'general' || in_array($new, ['payment', 'reminder'], true))) {
                        DB::table('office_emails')->where('id', $e->id)->update(['category' => $new]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('office_emails', 'link_role')) {
            Schema::table('office_emails', function (Blueprint $table) {
                $table->dropColumn('link_role');
            });
        }
    }
};
