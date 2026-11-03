<?php

use App\Support\CompanyLogins;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A fourth standard login per company (user's request, 2026-11-02): {slug}-manager@kcoltech.com
 * with the Manager role and the standard access password (changed at first sign-in).
 * Not made where the company already has someone with the Manager role (Ruba, New
 * Era...). Every company except AdminSystem (999). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->whereNull('deleted_at')->where('id', '!=', \App\Models\Setting::TEMPLATE_COMPANY_ID)->orderBy('id')->pluck('id') as $cid) {
            CompanyLogins::ensureOne((int) $cid, 'manager');
        }
    }

    public function down(): void
    {
    }
};
