<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * An admin / SuperAdmin login only manages the system's access (user's rule,
 * 2026-11-02): it is never an employee, customer, supplier or sales agent.
 * Every employee row pointing to such a login (the company admin's own record,
 * made by CompanyProvisioner until today) is unlinked and removed (soft delete:
 * its history -- positions, project tasks -- keeps pointing to it; a removed
 * employee is never given a new login by murano:link-missing-users). Customer /
 * supplier / sales agent rows are only unlinked. The login itself is untouched.
 * Every company (live ones too: it is the rule, not their data); one transaction;
 * safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admins = DB::table('users')->whereIn(DB::raw('LOWER(type)'), ['admin', 'superadmin'])->pluck('id');
        if ($admins->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($admins) {
            $now = now();
            $employees = DB::table('employees')->whereIn('user_id', $admins)->whereNull('deleted_at')->get(['id', 'company_id', 'user_id']);
            foreach ($employees as $e) {
                $set = ['user_id' => null, 'deleted_at' => $now, 'updated_at' => $now];
                if (Schema::hasColumn('employees', 'leaving_date')) {
                    $set['leaving_date'] = $now->toDateString();
                }
                DB::table('employees')->where('id', $e->id)->update($set);
            }
            if ($employees->isNotEmpty()) {
                Log::info('admins not linked: employee records removed', $employees->map(fn ($e) => "employee {$e->id} (company {$e->company_id}, user {$e->user_id})")->all());
            }
            foreach (['clients', 'providers', 'sales_agents'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                    DB::table($table)->whereIn('user_id', $admins)->update(['user_id' => null, 'updated_at' => $now]);
                }
            }
        });
    }

    public function down(): void
    {
        // Not undone automatically (the production backup taken before the deploy has them).
    }
};
