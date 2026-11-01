<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-11-01, after the demo campaigns of 2026-10-27 emailed real customers:
 * Marketing off (Business Modules) for the demo companies that had it on, and
 * none of their campaigns left scheduled. By company name, safe to run twice.
 * The sender now skips any company with Marketing off (ProcessScheduledCampaigns,
 * MarketingDispatcher).
 */
return new class extends Migration
{
    private const DEMOS = ['Alo Mundo', 'La Sazon de Lock', 'Quiero ese Inmueble', 'My Bodega'];

    public function up(): void
    {
        foreach (DB::table('companies')->whereIn('name', self::DEMOS)->pluck('id') as $cid) {
            foreach (DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'module_flags']) as $s) {
                $flags = json_decode((string) $s->module_flags, true) ?: [];
                if (($flags['marketing'] ?? true) !== false) {
                    $flags['marketing'] = false;
                    DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
                }
            }
            DB::table('marketing_campaigns')->where('company_id', $cid)->whereIn('status', ['scheduled', 'sending'])
                ->update(['status' => 'draft', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Nothing: turning Marketing back on is a decision made in Business Modules.
    }
};
