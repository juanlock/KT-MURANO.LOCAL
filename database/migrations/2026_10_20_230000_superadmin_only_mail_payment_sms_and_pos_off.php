<?php

use App\Support\SettingsAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User's rules, 2026-10-20 (safe to run twice; by name / type, not id):
 *  1. SMS templates get their own permission `sms_templates` (they shared
 *     `notification_template` with Email templates); the roles that had
 *     notification_template get it, then SettingsAccess removes it again from
 *     the companies' roles.
 *  2. Mail settings, Payment methods and SMS templates are SuperAdmin-only:
 *     removed from every company's roles (SettingsAccess::trimRoles; 999 kept).
 *  3. POS off for school, hospital and real estate companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('name', 'sms_templates')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'name' => 'sms_templates', 'label' => 'SMS templates', 'description' => 'SMS templates',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $from = DB::table('permissions')->where('name', 'notification_template')->value('id');
            foreach ($from ? DB::table('permission_role')->where('permission_id', $from)->pluck('role_id')->unique() : [] as $rid) {
                DB::table('permission_role')->insert(['permission_id' => $permId, 'role_id' => $rid]);
            }
        }
        if (Schema::hasTable('translations') && ! DB::table('translations')->where('key', 'sms_templates')->exists()) {
            DB::table('translations')->insert(['key' => 'sms_templates', 'is_default' => 1, 'en' => 'SMS Templates', 'es' => 'Plantillas SMS', 'created_at' => now(), 'updated_at' => now()]);
        }

        if (Schema::hasColumn('companies', 'business_type')) {
            $ids = DB::table('companies')->whereNull('deleted_at')->whereIn('business_type', ['school', 'hospital', 'real_estate'])->pluck('id');
            foreach (DB::table('settings')->whereNull('deleted_at')->whereIn('company_id', $ids)->get(['id', 'module_flags']) as $row) {
                $flags = json_decode((string) $row->module_flags, true) ?: [];
                $flags['pos'] = false;
                DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('companies')->whereNull('deleted_at')->pluck('id') as $cid) {
            SettingsAccess::trimRoles((int) $cid);
        }
    }

    public function down(): void
    {
        // A SuperAdmin can grant them back to a company's role on purpose.
    }
};
