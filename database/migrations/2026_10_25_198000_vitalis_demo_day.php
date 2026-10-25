<?php

use App\Support\HospitalDemoDay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vitalis (2026-10-25): more examples for doctors, nurses, lab and pharmacy --
 * four example stays with treatment, today's doses, diet, vitals, progress,
 * lab results (a critical glucose) and interconsultations, today's roster and
 * the patients assigned to each doctor / nurse (hospital:demo-day keeps the
 * roster going every day). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->where('name', 'Vitalis')->whereNull('deleted_at')->pluck('id') as $cid) {
            HospitalDemoDay::run((int) $cid);
        }
    }

    public function down(): void
    {
        // Example data stays.
    }
};
