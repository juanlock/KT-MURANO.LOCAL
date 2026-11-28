<?php

namespace App\Services;

use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll run (HRM -> Payroll -> Generate payroll), weekly or monthly (settings.payroll_period, 2026-11-28: Turismo
 * Cautivo pays by month; in a monthly run a monthly salary is the whole month, prorated on 30 days when the person
 * joined / left inside it). One payroll per employee per period (Monday-Sunday or the month), from the pay setup:
 *  - hourly:     hours on the service jobs they COMPLETED that week (work_hours,
 *                the operator = service_technicians.employee_id) x hourly_rate;
 *  - commission: their sales that week (the stylist on the sale's booking --
 *                bookings.sales_person_id = their user; else the sale's sales
 *                agent linked to their user) x commission_rate %;
 *  - per_trip:   the transport trips they completed x their rate;
 *  - monthly:    a week's share of basic_salary (x 12 / 52), plus any trips.
 * Only employees whose pay starts on / before the week's end; a week that
 * already has a payroll for the employee is skipped. Paid payrolls post to
 * accounting (PayrollExpenseSync: Salaries and wages 7010 / Commissions 7040).
 */
class PayrollRun
{
    /** Monday..Sunday of the week containing $date. */
    public static function week(string $date): array
    {
        $d = Carbon::parse($date);

        return [$d->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $d->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()];
    }

    /** First..last day of the month containing $date (monthly payroll, 2026-11-28). */
    public static function month(string $date): array
    {
        $d = Carbon::parse($date);

        return [$d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString()];
    }

    /** The company's payroll period: weekly (default) | monthly (settings.payroll_period; Turismo Cautivo: monthly). */
    public static function companyPeriod(int $companyId): string
    {
        $p = \Illuminate\Support\Facades\Schema::hasColumn('settings', 'payroll_period')
            ? DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('payroll_period') : null;

        return $p === 'monthly' ? 'monthly' : 'weekly';
    }

    /**
     * Days of the month the employee is on the payroll, on a 30-day month (Peru's commercial month): a whole month = 30;
     * joined / left / pay started inside it = the calendar days in between (31 days in the month still count 30).
     */
    public static function monthDays(object $e, string $from, string $to): int
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        foreach ([$e->joining_date ?? null, $e->pay_effective_from ?? null] as $d) {
            if ($d && substr((string) $d, 0, 10) > $start->toDateString()) {
                $start = Carbon::parse(substr((string) $d, 0, 10));
            }
        }
        if (! empty($e->leaving_date) && substr((string) $e->leaving_date, 0, 10) < $end->toDateString()) {
            $end = Carbon::parse(substr((string) $e->leaving_date, 0, 10));
        }
        if ($start->gt($end)) {
            return 0;
        }
        if ($start->toDateString() === $from && $end->toDateString() === $to) {
            return 30;
        }

        return min(30, $start->diffInDays($end) + 1);
    }

    /**
     * What the period would pay, per employee (nothing written). $period weekly | monthly: a monthly salary pays a
     * week's share (x 12 / 52) in a weekly run and the month (30-day basis, prorated) in a monthly one.
     *
     * @return array<int, array> rows {employee_id, name, pay_type, hours, rate, commission_base, commission_rate, amount, jobs, sales, existing, days, absences}
     */
    public static function preview(int $companyId, string $from, string $to, string $period = 'weekly'): array
    {
        $rows = [];
        $employees = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('pay_type', ['hourly', 'commission', 'per_trip', 'monthly'])
            ->where(fn ($q) => $q->whereNull('pay_effective_from')->orWhere('pay_effective_from', '<=', $to))
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', $from))
            ->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'user_id', 'pay_type', 'hourly_rate', 'commission_rate', 'trip_rate', 'basic_salary',
                'joining_date', 'leaving_date', 'pay_effective_from']);
        $hasSheet = \Illuminate\Support\Facades\Schema::hasColumn('attendances', 'source');

        foreach ($employees as $e) {
            $row = [
                'employee_id' => (int) $e->id,
                'name' => trim($e->firstname.' '.$e->lastname),
                'pay_type' => $e->pay_type,
                'hours' => null, 'rate' => null, 'commission_base' => null, 'commission_rate' => null,
                'jobs' => 0, 'sales' => 0, 'trips' => null, 'amount' => 0.0, 'days' => null,
                // Any payroll of the employee whose period overlaps this one (a weekly run is never paid again by a monthly one).
                'existing' => DB::table('payrolls')->where('employee_id', $e->id)->whereNull('deleted_at')
                    ->where('period_start', '<=', $to)->where('period_end', '>=', $from)->value('Ref'),
                // Unjustified absences of the daily attendance sheet in the period (shown, not deducted).
                'absences' => $hasSheet ? DB::table('attendances')->where('employee_id', $e->id)->whereNull('deleted_at')->where('status', 'absent')
                    ->where('source', 'sheet')->whereNull('leave_type_id')->whereBetween('date', [$from, $to])->count() : 0,
            ];
            if ($e->pay_type === 'monthly') {
                // On the payroll (2026-11-04): the period's share of the monthly salary, plus the trips they completed.
                $trips = DB::table('transport_trip_crew as c')->join('transport_trips as t', 't.id', '=', 'c.trip_id')
                    ->where('c.employee_id', $e->id)->where('t.company_id', $companyId)->whereNull('t.deleted_at')
                    ->where('t.status', 'completed')->whereBetween(DB::raw('DATE(t.departure_at)'), [$from, $to]);
                $row['trips'] = (clone $trips)->count();
                $row['rate'] = (float) $e->basic_salary;
                $tripPay = (float) (clone $trips)->sum(DB::raw('COALESCE(c.trip_rate, '.(float) $e->trip_rate.')'));
                if ($period === 'monthly') {
                    $row['days'] = self::monthDays($e, $from, $to);
                    $salary = $row['days'] >= 30 ? (float) $e->basic_salary : (float) $e->basic_salary / 30 * $row['days'];
                } else {
                    $salary = (float) $e->basic_salary * 12 / 52;
                }
                $row['amount'] = round($salary + $tripPay, 2);
            } elseif ($e->pay_type === 'per_trip') {
                // Transport crew (2026-11-01): the trips they completed that week, each at the
                // rate they had when put on the trip (else their current trip rate).
                $trips = DB::table('transport_trip_crew as c')->join('transport_trips as t', 't.id', '=', 'c.trip_id')
                    ->where('c.employee_id', $e->id)->where('t.company_id', $companyId)->whereNull('t.deleted_at')
                    ->where('t.status', 'completed')->whereBetween(DB::raw('DATE(t.departure_at)'), [$from, $to]);
                $row['trips'] = (clone $trips)->count();
                $row['rate'] = (float) $e->trip_rate;
                $row['amount'] = round((float) (clone $trips)->sum(DB::raw('COALESCE(c.trip_rate, '.(float) $e->trip_rate.')')), 2);
            } elseif ($e->pay_type === 'hourly') {
                $techIds = DB::table('service_technicians')->where('employee_id', $e->id)->pluck('id');
                // Their own jobs, plus the company's "All operators" crew jobs (they're an operator).
                $jobs = DB::table('service_jobs as j')->leftJoin('warehouses as w', 'w.id', '=', 'j.warehouse_id')->leftJoin('clients as c', 'c.id', '=', 'j.client_id')
                    ->whereNull('j.deleted_at')->whereNotNull('j.completed_at')->whereBetween(DB::raw('DATE(j.completed_at)'), [$from, $to])
                    ->where(fn ($q) => $q->whereIn('j.technician_id', $techIds->all() ?: [0])
                        ->when($techIds->isNotEmpty(), fn ($x) => $x->orWhere(fn ($y) => $y->where('j.assign_all', true)->whereRaw('COALESCE(w.company_id, c.company_id) = ?', [$companyId]))));
                $row['hours'] = round((float) (clone $jobs)->sum('j.work_hours'), 2);
                $row['jobs'] = (clone $jobs)->count();
                $row['rate'] = (float) $e->hourly_rate;
                $row['amount'] = round($row['hours'] * $row['rate'], 2);
            } else {
                $base = self::salesOf($companyId, $e, $from, $to);
                $row['commission_base'] = $base['total'];
                $row['sales'] = $base['count'];
                $row['commission_rate'] = (float) $e->commission_rate;
                $row['amount'] = round($base['total'] * $row['commission_rate'] / 100, 2);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** The employee's sales in the period (booking stylist, else sales agent linked to their user). */
    public static function salesOf(int $companyId, object $employee, string $from, string $to): array
    {
        if (! $employee->user_id) {
            return ['total' => 0.0, 'count' => 0];
        }
        $agentIds = DB::table('sales_agents')->where('user_id', $employee->user_id)->pluck('id');
        $q = DB::table('sales as s')->where('s.company_id', $companyId)->whereNull('s.deleted_at')
            ->whereBetween('s.date', [$from, $to])
            ->where(fn ($w) => $w->where('s.is_draft', false)->orWhereNull('s.is_draft'))
            ->where(function ($w) use ($employee, $agentIds) {
                // The stylist on the sale's booking...
                $w->whereExists(fn ($b) => $b->from('bookings as b')->whereColumn('b.sale_id', 's.id')->where('b.sales_person_id', $employee->user_id))
                    // ...or, with no stylist on a booking, the sale's sales agent.
                    ->orWhere(fn ($x) => $x->whereIn('s.sales_agent_id', $agentIds ?: [0])
                        ->whereNotExists(fn ($b) => $b->from('bookings as b')->whereColumn('b.sale_id', 's.id')->whereNotNull('b.sales_person_id')));
            });

        return ['total' => round((float) (clone $q)->sum('s.GrandTotal'), 2), 'count' => (clone $q)->count()];
    }

    /**
     * Create the week's payrolls (skips existing weeks and zero amounts).
     * $paid: paid now from $accountId / $paymentMethodId -> posted to accounting.
     *
     * @return Payroll[]
     */
    public static function create(int $companyId, string $from, string $to, int $userId, int $paymentMethodId, ?int $accountId, bool $paid = true, ?callable $afterEach = null, string $period = 'weekly'): array
    {
        $made = [];
        $es = (string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language') !== 'en';
        foreach (self::preview($companyId, $from, $to, $period) as $row) {
            if ($row['existing'] || $row['amount'] <= 0) {
                continue;
            }
            $made[] = DB::transaction(function () use ($row, $companyId, $from, $to, $userId, $paymentMethodId, $accountId, $paid, $afterEach, $period, $es) {
                $p = Payroll::create([
                    'user_id' => $userId,
                    'Ref' => app(\App\Http\Controllers\hrm\PayrollController::class)->getNumberOrder(),
                    'company_id' => $companyId,
                    'date' => $to,
                    'period_start' => $from,
                    'period_end' => $to,
                    'employee_id' => $row['employee_id'],
                    'pay_type' => $row['pay_type'],
                    'hours' => $row['hours'],
                    'trips' => $row['trips'],
                    'rate' => $row['rate'],
                    'commission_base' => $row['commission_base'],
                    'commission_rate' => $row['commission_rate'],
                    'account_id' => $accountId,
                    'amount' => $row['amount'],
                    'payment_method_id' => $paymentMethodId,
                    'payment_status' => $paid ? 'paid' : 'unpaid',
                    'notes' => match ($row['pay_type']) {
                        'hourly' => "{$row['hours']} h on {$row['jobs']} job(s) x {$row['rate']}",
                        'per_trip' => "{$row['trips']} trip(s) completed",
                        'monthly' => $period === 'monthly'
                            ? ($es ? "Sueldo mensual {$row['rate']} ({$row['days']} de 30 días) + {$row['trips']} viaje(s)"
                                : "Monthly salary {$row['rate']} ({$row['days']} of 30 days) + {$row['trips']} trip(s)")
                            : "Salary {$row['rate']} a month (a week's share) + {$row['trips']} trip(s)",
                        default => "{$row['commission_rate']}% of {$row['commission_base']} ({$row['sales']} sale(s))",
                    },
                ]);
                if ($paid) {
                    if ($accountId) {
                        DB::table('accounts')->where('id', $accountId)->decrement('balance', $row['amount']);
                    }
                    PayrollExpenseSync::sync($p);
                }
                if ($afterEach) {
                    $afterEach($p);
                }

                return $p;
            });
        }

        return $made;
    }
}
