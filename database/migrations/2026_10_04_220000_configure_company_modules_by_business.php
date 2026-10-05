<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modules per company by type of business (kt_logs #422-#426):
 *  - KcolTech (1, IT services): Bookings (appointments) + Service Jobs
 *    (repairs) + Helpdesk (tickets) -- all on.
 *  - Ruba Garden (2, landscaping): Bookings off (salon-style appointments
 *    don't fit; it had none). Service Jobs stays off until the module is
 *    company-scoped (technicians / checklists are shared today).
 *  - New Era (3, salon): Bookings on, Service Jobs off -- unchanged.
 * Only the listed keys are touched; a missing key already means "on".
 */
return new class extends Migration
{
    private const FLAGS = [
        1 => ['bookings' => true, 'service' => true, 'helpdesk' => true],
        2 => ['bookings' => false],
        3 => ['bookings' => true, 'service' => false],
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $companyId => $set) {
            $row = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['id', 'module_flags']);
            if (! $row) {
                continue;
            }
            $flags = json_decode((string) $row->module_flags, true) ?: [];
            DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode(array_merge($flags, $set))]);
        }
    }

    public function down(): void
    {
        // Ruba's Bookings back on (the only real change).
        $row = DB::table('settings')->where('company_id', 2)->whereNull('deleted_at')->first(['id', 'module_flags']);
        if ($row) {
            $flags = json_decode((string) $row->module_flags, true) ?: [];
            DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode(array_merge($flags, ['bookings' => true]))]);
        }
    }
};
