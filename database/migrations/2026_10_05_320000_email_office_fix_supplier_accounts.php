<?php

use App\Services\EmailOffice\EmailOffice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office clean-up (user's report, 2026-10-05: two American Express
 * cards, both emails on one supplier):
 *  1. A received email FROM a supplier's address that sits on a customer who
 *     doesn't have that address goes back to that supplier.
 *  2. A supplier's email that names an account ("Account ending: 52003") goes
 *     to the supplier of that account when several suppliers of the same
 *     brand carry account numbers (EmailOffice::supplierByAccount).
 * Emails naming an account that no supplier has stay where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_emails')) {
            return;
        }

        // 1. Supplier address on a customer that isn't theirs.
        foreach (DB::table('providers')->whereNull('deleted_at')->whereNotNull('email')->where('email', '!=', '')->get(['id', 'company_id', 'email']) as $p) {
            $addr = strtolower(trim($p->email));
            $rows = DB::table('office_emails as e')->join('clients as c', 'c.id', '=', 'e.party_id')
                ->where('e.company_id', $p->company_id)->where('e.direction', 'in')->where('e.party_type', 'client')
                ->whereRaw('LOWER(e.from_email) = ?', [$addr])
                ->whereRaw('LOWER(COALESCE(c.email, "")) <> ?', [$addr])
                ->where(fn ($q) => $q->whereNull('c.additional_emails')->orWhere('c.additional_emails', 'not like', '%"'.$addr.'"%'))
                ->pluck('e.id');
            if ($rows->isNotEmpty()) {
                DB::table('office_emails')->whereIn('id', $rows)->update(['party_type' => 'provider', 'party_id' => $p->id]);
            }
        }

        // 2. The account named in the email.
        DB::table('office_emails')->where('direction', 'in')->where('party_type', 'provider')->orderBy('id')
            ->select(['id', 'company_id', 'party_id', 'subject', 'body_text', 'from_name'])
            ->chunkById(300, function ($rows) {
                foreach ($rows as $e) {
                    $acc = EmailOffice::supplierByAccount((int) $e->company_id, $e->subject."\n".mb_substr((string) $e->body_text, 0, 5000), (int) $e->party_id, $e->from_name);
                    if ($acc && $acc !== (int) $e->party_id) {
                        DB::table('office_emails')->where('id', $e->id)->update(['party_id' => $acc]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to restore: the old links were wrong.
    }
};
