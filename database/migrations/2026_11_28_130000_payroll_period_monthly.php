<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Payroll period per company (the user, 2026-11-28: "si es un pago mensual adáptalo"): settings.payroll_period weekly
 * (every company until now) | monthly. Turismo Cautivo pays by month. PayrollRun: in a monthly run a monthly salary is
 * the whole month (30-day basis, prorated when the person joined / left inside it). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'payroll_period')) {
            Schema::table('settings', fn (Blueprint $t) => $t->string('payroll_period', 10)->default('weekly'));
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($cid) {
            DB::table('settings')->where('company_id', $cid)->update(['payroll_period' => 'monthly']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'payroll_period')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('payroll_period'));
        }
    }
};
