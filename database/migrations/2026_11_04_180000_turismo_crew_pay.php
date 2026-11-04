<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What Turismo Cautivo's demo staff costs (user's data, 2026-11-04): S/ 50 per crew member per trip,
 * and 60 % of the crew on the payroll with a monthly salary (basic_salary): pilots S/ 2,060, the
 * middle of S/ 1,687 to S/ 2,428; hostesses S/ 1,210, the middle of S/ 1,025 to S/ 1,400. Everyone at
 * a ticket office S/ 1,300, on the payroll from the first day. TransportProfit shares the
 * salaries among each day's trips. Rows tagged as demo only. Safe to run twice.
 */
return new class extends Migration
{
    private const PER_TRIP = 50;

    private const SALARY = ['pilot' => 2060, 'hostess' => 1210];

    private const COUNTER_SALARY = 1300;

    private const ON_PAYROLL = 0.6;

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $demo = fn (string $type) => DB::table('demo_records')->where('company_id', $cid)->where('record_type', $type)->pluck('record_id')->all();
        DB::transaction(function () use ($demo) {
            DB::table('transport_trip_crew')->whereIn('trip_id', $demo('transport_trips'))->update(['trip_rate' => self::PER_TRIP]);
            foreach (self::SALARY as $type => $salary) {
                $crew = DB::table('employees')->whereIn('id', $demo('employees'))->whereNull('deleted_at')->where('staff_type', $type)->orderBy('id')->pluck('id');
                DB::table('employees')->whereIn('id', $crew)->update(['trip_rate' => self::PER_TRIP, 'basic_salary' => 0, 'updated_at' => now()]);
                DB::table('employees')->whereIn('id', $crew->take((int) round($crew->count() * self::ON_PAYROLL)))->update(['basic_salary' => $salary, 'updated_at' => now()]);
            }
            // The ticket offices: the demo's logins on the Terminal POS role.
            DB::table('employees as e')->join('users as u', 'u.id', '=', 'e.user_id')->join('roles as r', 'r.id', '=', 'u.role_id')
                ->whereIn('e.id', $demo('employees'))->whereNull('e.deleted_at')->where('r.name', \App\Support\TransportAgencies::POS_ROLE)
                ->update(['e.basic_salary' => self::COUNTER_SALARY, 'e.staff_type' => 'administrative', 'e.updated_at' => now()]);
        });
    }

    public function down(): void
    {
    }
};
