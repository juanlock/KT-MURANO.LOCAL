<?php

use App\Services\DemoData\DemoRunner;
use App\Support\BusinessTypes;
use App\Support\SettingsAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User's rules, 2026-10-20 (by name / type, not id; safe to run twice):
 *  1. Every company's roles lose the SuperAdmin-only settings (PayPal, ZATCA,
 *     currencies, multi-currency, backup, system health, translations, update,
 *     payment gateway, SMS, POS settings, integrations, loyalty) unless that
 *     module is on for the company (App\Support\SettingsAccess). AdminSystem
 *     (999) is not touched.
 *  2. School companies: only School, Portfolio, Intranet, Documents and Website
 *     (BusinessTypes::modules('school')); every other switch off, and the DEMO
 *     rows of the modules turned off removed (only ids in demo_records).
 *  3. School and real estate companies: their module first in the menu
 *     (BusinessTypes::promoteMenu); they open on their own dashboard by code.
 */
return new class extends Migration
{
    /**
     * Demo modules of a school that its modules no longer use -- in this order:
     * the ones that point at demo employees / products first (payrolls, meetings,
     * assets...), HRM and Core last (a foreign key refuses the other way round).
     */
    private const SCHOOL_DEMO_OFF = ['payroll_history', 'regular_expenses', 'meeting', 'assets', 'holidays', 'hrm', 'core'];

    public function up(): void
    {
        // 1. Settings access, every company.
        if (Schema::hasTable('permission_role')) {
            foreach (DB::table('companies')->whereNull('deleted_at')->pluck('id') as $cid) {
                SettingsAccess::trimRoles((int) $cid);
            }
        }
        if (! Schema::hasColumn('companies', 'business_type')) {
            return;
        }

        // 2. School companies' modules + demo.
        $keep = BusinessTypes::modules('school');
        foreach (DB::table('companies')->where('business_type', 'school')->whereNull('deleted_at')->pluck('id') as $cid) {
            $cid = (int) $cid;
            $row = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'module_flags']);
            if ($row) {
                $flags = json_decode((string) $row->module_flags, true) ?: [];
                foreach (array_unique(array_merge(array_keys($flags), BusinessTypes::EXCLUDE['school'], $keep)) as $k) {
                    $flags[$k] = in_array($k, $keep, true);
                }
                DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
            }
            if (Schema::hasTable('demo_records')) {
                $userId = (int) DB::table('users')->where('company_id', $cid)->where('type', 'admin')->whereNull('deleted_at')->orderBy('id')->value('id');
                foreach (self::SCHOOL_DEMO_OFF as $module) {
                    if (DB::table('demo_records')->where('company_id', $cid)->where('module', $module)->exists()) {
                        DemoRunner::reset($cid, $userId, $module);
                    }
                }
            }
            // The roles again: with its new switches (POS off...).
            SettingsAccess::trimRoles($cid);
        }

        // 3. Their module first in the menu.
        foreach (DB::table('companies')->whereIn('business_type', ['school', 'real_estate'])->whereNull('deleted_at')->pluck('id') as $cid) {
            DB::transaction(fn () => BusinessTypes::promoteMenu((int) $cid));
        }
    }

    public function down(): void
    {
        // Permissions can be granted again in Roles (by a SuperAdmin); removed demo rows are not recreated.
    }
};
