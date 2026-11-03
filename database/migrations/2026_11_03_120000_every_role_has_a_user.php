<?php

use App\Support\CompanyDefaultPeople;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every role of every company has a user (user's rule, 2026-11-03, live
 * companies included -- the user's yes): the automatic roles (Supplier, Sales
 * Agent, Online Store) in every company, the {slug}-website login (Website),
 * and a demo customer / employee / supplier / sales agent / store customer
 * with its login where that role had nobody (CompanyDefaultPeople). One
 * transaction per company; safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tpl = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        foreach (\App\Support\CompanyRoles::AUTO as $name) {
            \App\Support\CompanyRoles::ensure($tpl, $name);
        }
        $ids = DB::table('companies')->whereNull('deleted_at')->where('id', '!=', $tpl)->orderBy('id')->pluck('id');
        foreach ($ids as $cid) {
            $log = DB::transaction(fn () => CompanyDefaultPeople::ensure((int) $cid));
            if ($log) {
                echo "  company {$cid}: ".implode('; ', $log)."\n";
            }
        }
    }

    public function down(): void
    {
        // Not undone: the logins and records are the companies' own now.
    }
};
