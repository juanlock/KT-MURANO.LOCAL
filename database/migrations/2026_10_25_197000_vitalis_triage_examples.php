<?php

use App\Support\HospitalTriageExamples;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vitalis (hospital test company, 2026-10-25): example arrivals so the triage
 * nurse has patients to call -- two emergencies waiting for triage (one with
 * insurance, one without financial data) and one referred surgery waiting for
 * a bed. Safe to run twice (HospitalTriageExamples skips when present).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->where('name', 'Vitalis')->whereNull('deleted_at')->pluck('id') as $cid) {
            HospitalTriageExamples::seed((int) $cid, true);
        }
    }

    public function down(): void
    {
        // Examples stay (they are ordinary arrivals now).
    }
};
