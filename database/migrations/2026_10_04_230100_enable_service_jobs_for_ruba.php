<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ruba Garden (company 2, landscaping) runs its jobs on Service Jobs, now that
 * the module is company-scoped (2026_10_04_230000). Bookings stays off for it
 * (2026_10_04_220000).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->set(['service' => true]);
    }

    public function down(): void
    {
        $this->set(['service' => false]);
    }

    private function set(array $flags): void
    {
        $row = DB::table('settings')->where('company_id', 2)->whereNull('deleted_at')->first(['id', 'module_flags']);
        if ($row) {
            $current = json_decode((string) $row->module_flags, true) ?: [];
            DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode(array_merge($current, $flags))]);
        }
    }
};
