<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office clean-up (user's rules, 2026-10-05):
 *  1. The company's own mailbox is never a contact: received emails FROM it
 *     that were matched to a customer / supplier saved with that address
 *     (Ruba's "Ruba Garden Landscape LLC") are un-assigned. Their type stays
 *     (a forwarded supplier bill is still a bill).
 *  2. A customer's email is never a bill to record (bills are what the
 *     company pays): automatically-sorted customer "bills" -> general.
 *  3. Claim is no longer used automatically: every claim -> general.
 * Not reversible (the previous automatic guesses aren't kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_emails')) {
            return;
        }

        foreach (DB::table('email_office_settings')->get(['company_id', 'from_email', 'username']) as $s) {
            $own = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), [$s->from_email, $s->username]))));
            if ($own) {
                DB::table('office_emails')->where('company_id', $s->company_id)->where('direction', 'in')
                    ->whereIn(DB::raw('LOWER(from_email)'), $own)->whereIn('party_type', ['client', 'provider', 'employee'])
                    ->update(['party_type' => null, 'party_id' => null]);
            }
        }

        DB::table('office_emails')->where('direction', 'in')->where('category', 'bill')
            ->where('category_manual', false)->where('party_type', 'client')
            ->update(['category' => 'general']);

        DB::table('office_emails')->where('category', 'claim')->update(['category' => 'general']);
    }

    public function down(): void
    {
        // Nothing to restore: the old automatic guesses were wrong.
    }
};
