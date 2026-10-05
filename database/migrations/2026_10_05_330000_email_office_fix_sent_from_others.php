<?php

use App\Services\EmailOffice\EmailOffice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emails imported from a mailbox's Sent folder whose sender is NOT the
 * company's own address (forwards / synced copies -- e.g. American Express
 * emails in Ruba's Sent folder) were received from that sender: direction ->
 * "in", read, and re-matched to the sender (customer / supplier / its
 * account) instead of the recipient (the company's own mailbox, which a
 * customer had in its additional emails).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_emails')) {
            return;
        }
        foreach (DB::table('email_office_settings')->get(['company_id', 'from_email', 'username']) as $s) {
            $own = array_values(array_filter([strtolower(trim((string) $s->from_email)), strtolower(trim((string) $s->username))]));
            if (! $own) {
                continue;
            }
            DB::table('office_emails')->where('company_id', $s->company_id)->where('direction', 'out')->where('source', 'mailbox')
                ->whereNotNull('from_email')->where('from_email', '!=', '')
                ->whereNotIn(DB::raw('LOWER(from_email)'), $own)
                ->orderBy('id')->select(['id', 'from_email', 'from_name', 'subject', 'body_text', 'category_manual'])
                ->chunkById(200, function ($rows) use ($s) {
                    foreach ($rows as $e) {
                        $cid = (int) $s->company_id;
                        [$type, $id] = EmailOffice::matchParty($cid, [$e->from_email]);
                        if (! $type && ($sid = EmailOffice::supplierByName($cid, $e->from_name))) {
                            [$type, $id] = ['provider', $sid];
                        }
                        if ($acc = EmailOffice::supplierByAccount($cid, $e->subject."\n".mb_substr((string) $e->body_text, 0, 5000), $type === 'provider' ? (int) $id : null, $e->from_name)) {
                            [$type, $id] = ['provider', $acc];
                        }
                        DB::table('office_emails')->where('id', $e->id)->update([
                            'direction' => 'in', 'is_read' => true, 'party_type' => $type, 'party_id' => $id,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Nothing to restore: they were received emails.
    }
};
