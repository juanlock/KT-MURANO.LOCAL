<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll by hours / commission + service job costing (user's request, 2026-10-06):
 *  - employees.pay_type (hourly | commission), commission_rate (%), pay_effective_from
 *    (hourly_rate already existed);
 *  - payrolls: company_id + the period and how the amount was worked out
 *    (hours x rate, or commission base x %);
 *  - service jobs: work_hours (entered on Complete / the Task tab), labor_cost,
 *    asset_cost; service_job_assets = equipment used (hours x asset hourly_cost);
 *    assets.hourly_cost (default: cost less salvage over its life at 160 h / month).
 * Rates (weekly payroll):
 *  - Ruba (2): ManagerRG $25/h, operators EmployeeRG / EmployeeRG2 $20/h, from 2026-01-01;
 *  - KcolTech (1): $35/h;  New Era (3): 40% commission on their sales.
 *  Employees linked to an admin login (the owners' own records) stay off payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            if (! Schema::hasColumn('employees', 'pay_type')) {
                $t->string('pay_type', 12)->nullable();
            }
            if (! Schema::hasColumn('employees', 'commission_rate')) {
                $t->decimal('commission_rate', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('employees', 'pay_effective_from')) {
                $t->date('pay_effective_from')->nullable();
            }
        });

        Schema::table('payrolls', function (Blueprint $t) {
            foreach ([
                'company_id' => fn () => $t->unsignedBigInteger('company_id')->nullable()->index(),
                'period_start' => fn () => $t->date('period_start')->nullable(),
                'period_end' => fn () => $t->date('period_end')->nullable(),
                'pay_type' => fn () => $t->string('pay_type', 12)->nullable(),
                'hours' => fn () => $t->decimal('hours', 8, 2)->nullable(),
                'rate' => fn () => $t->decimal('rate', 10, 2)->nullable(),
                'commission_base' => fn () => $t->decimal('commission_base', 14, 2)->nullable(),
                'commission_rate' => fn () => $t->decimal('commission_rate', 5, 2)->nullable(),
                'notes' => fn () => $t->text('notes')->nullable(),
            ] as $col => $add) {
                if (! Schema::hasColumn('payrolls', $col)) {
                    $add();
                }
            }
        });
        DB::statement('UPDATE payrolls p JOIN employees e ON e.id = p.employee_id SET p.company_id = e.company_id WHERE p.company_id IS NULL');

        Schema::table('service_jobs', function (Blueprint $t) {
            if (! Schema::hasColumn('service_jobs', 'work_hours')) {
                $t->decimal('work_hours', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('service_jobs', 'labor_cost')) {
                $t->decimal('labor_cost', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('service_jobs', 'asset_cost')) {
                $t->decimal('asset_cost', 12, 2)->default(0);
            }
        });
        if (! Schema::hasColumn('assets', 'hourly_cost')) {
            Schema::table('assets', fn (Blueprint $t) => $t->decimal('hourly_cost', 10, 2)->nullable());
        }
        DB::statement('UPDATE assets SET hourly_cost = ROUND(GREATEST(purchase_cost - COALESCE(salvage_value, 0), 0) / (useful_life_months * 160), 2) WHERE hourly_cost IS NULL AND useful_life_months > 0 AND purchase_cost > 0');
        if (! Schema::hasTable('service_job_assets')) {
            Schema::create('service_job_assets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('service_job_id')->index();
                $t->unsignedBigInteger('asset_id')->index();
                $t->decimal('hours', 8, 2)->default(0);
                $t->decimal('hourly_cost', 10, 2)->default(0);
                $t->decimal('cost', 12, 2)->default(0);
                $t->timestamps();
            });
        }

        // Rates. Owners' own employee records (linked to an admin login) stay off payroll.
        $adminUserIds = DB::table('users')->whereIn('type', ['admin', 'Admin', 'SuperAdmin'])->pluck('id');
        $set = function (int $companyId, array $values, ?array $names = null) use ($adminUserIds) {
            DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $adminUserIds))
                ->when($names, fn ($q) => $q->whereIn('firstname', $names))
                ->update($values);
        };
        if (DB::table('employees')->where('company_id', 2)->exists()) {
            $set(2, ['pay_type' => 'hourly', 'hourly_rate' => 25, 'pay_effective_from' => '2026-01-01'], ['ManagerRG']);
            $set(2, ['pay_type' => 'hourly', 'hourly_rate' => 20, 'pay_effective_from' => '2026-01-01'], ['EmployeeRG', 'EmployeeRG2']);
        }
        if (DB::table('employees')->where('company_id', 1)->exists()) {
            $set(1, ['pay_type' => 'hourly', 'hourly_rate' => 35, 'pay_effective_from' => '2026-01-01']);
        }
        if (DB::table('employees')->where('company_id', 3)->exists()) {
            $set(3, ['pay_type' => 'commission', 'commission_rate' => 40, 'pay_effective_from' => '2026-01-01']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_job_assets');
        foreach (['assets' => ['hourly_cost'], 'service_jobs' => ['work_hours', 'labor_cost', 'asset_cost'],
            'payrolls' => ['company_id', 'period_start', 'period_end', 'pay_type', 'hours', 'rate', 'commission_base', 'commission_rate', 'notes'],
            'employees' => ['pay_type', 'commission_rate', 'pay_effective_from']] as $table => $cols) {
            $cols = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if ($cols) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($cols));
            }
        }
    }
};
