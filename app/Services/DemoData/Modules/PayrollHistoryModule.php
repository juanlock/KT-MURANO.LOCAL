<?php

namespace App\Services\DemoData\Modules;

use App\Models\Payroll;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\PayrollExpenseSync;
use App\Services\PayrollRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll history: the weekly payrolls of the company's staff from each
 * employee's pay start (Employee -> Paid by / Pay from; at most since Jan 1 of
 * this year) up to last week, as DEMO rows -- removable with "Remove demo data".
 *  - hourly staff: a standard 40 h week (less in a first, partial week) x rate;
 *  - commission staff: their real sales that week x % (PayrollRun::salesOf).
 * Each payroll is paid and posted to accounting (Salaries and wages 7010 /
 * Commissions 7040); the linked expense and journal entries are tagged as demo too.
 */
class PayrollHistoryModule extends DemoModule
{
    public function key(): string
    {
        return 'payroll_history';
    }

    public function label(): string
    {
        return 'Payroll history (weekly, since each employee\'s pay start: hourly staff = their office shift hours, holidays off; commission on their sales; posted to accounting)';
    }

    public function dataTables(): array
    {
        return ['payrolls'];
    }

    public function requiredTables(): array
    {
        return ['payrolls', 'employees'];
    }

    public function generate(DemoContext $ctx): array
    {
        $yearStart = Carbon::now()->startOfYear()->toDateString();
        $lastWeekEnd = Carbon::now()->subWeek()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $employees = DB::table('employees')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->whereIn('pay_type', ['hourly', 'commission'])->get(['id', 'firstname', 'lastname', 'user_id', 'pay_type', 'hourly_rate', 'commission_rate', 'pay_effective_from', 'joining_date', 'leaving_date', 'office_shift_id']);

        $made = 0;
        $total = 0.0;
        foreach ($employees as $e) {
            $start = max($yearStart, (string) ($e->pay_effective_from ?: $e->joining_date ?: $yearStart));
            [$weekStart] = PayrollRun::week($start);
            for ($w = Carbon::parse($weekStart); $w->toDateString() <= $lastWeekEnd; $w->addWeek()) {
                $from = $w->toDateString();
                $to = $w->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
                if ($e->leaving_date && $e->leaving_date < $from) {
                    break;
                }
                if (DB::table('payrolls')->where('employee_id', $e->id)->whereNull('deleted_at')->where('period_start', $from)->exists()) {
                    continue;
                }
                if ($e->pay_type === 'hourly') {
                    // The employee's office shift hours on each working day from the pay
                    // start within this week (no shift: Mon-Fri 8 h); company holidays unpaid.
                    $hours = 0.0;
                    $days = 0;
                    for ($d = Carbon::parse(max($from, $start)); $d->toDateString() <= $to; $d->addDay()) {
                        $h = self::shiftHours($e->office_shift_id ?? null, $d);
                        if ($h > 0 && ! self::isHoliday($ctx->companyId, $d->toDateString())) {
                            $hours += $h;
                            $days++;
                        }
                    }
                    $amount = round($hours * (float) $e->hourly_rate, 2);
                    $row = ['pay_type' => 'hourly', 'hours' => $hours, 'rate' => (float) $e->hourly_rate,
                        'notes' => "{$hours} h ({$days} day(s) of the shift, demo) x {$e->hourly_rate}"];
                } else {
                    $base = PayrollRun::salesOf($ctx->companyId, $e, $from, $to);
                    $amount = round($base['total'] * (float) $e->commission_rate / 100, 2);
                    $row = ['pay_type' => 'commission', 'commission_base' => $base['total'], 'commission_rate' => (float) $e->commission_rate,
                        'notes' => "{$e->commission_rate}% of {$base['total']} ({$base['count']} sale(s), demo)"];
                }
                if ($amount <= 0) {
                    continue;
                }
                self::insertPaid($ctx, $e->id, $from, $to, $amount, $row);
                $made++;
                $total += $amount;
            }
        }

        return ['Payrolls' => $made, 'Total paid' => (int) round($total)];
    }

    /** Hours of the employee's office shift on that day (none set: Mon-Fri 8 h). */
    public static function shiftHours(?int $shiftId, Carbon $day): float
    {
        static $shifts = [];
        if (! $shiftId) {
            return $day->isWeekday() ? 8.0 : 0.0;
        }
        $shift = $shifts[$shiftId] ??= DB::table('office_shifts')->where('id', $shiftId)->first();
        if (! $shift) {
            return $day->isWeekday() ? 8.0 : 0.0;
        }
        $name = strtolower($day->englishDayOfWeek);
        $in = self::clock($shift->{$name.'_in'} ?? null);
        $out = self::clock($shift->{$name.'_out'} ?? null);

        return $in !== null && $out !== null && $out > $in ? round($out - $in, 2) : 0.0;
    }

    /** "07:00AM" / "05:00PM" / "17:00PM" (as the shift form saves) -> hours from midnight. */
    private static function clock(?string $t): ?float
    {
        if (! $t || ! preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)?/i', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $ap = strtoupper($m[3] ?? '');
        if ($ap === 'PM' && $h < 12) {
            $h += 12;
        } elseif ($ap === 'AM' && $h === 12) {
            $h = 0;
        }

        return $h + ((int) $m[2]) / 60;
    }

    private static function isHoliday(int $companyId, string $date): bool
    {
        return DB::table('holidays')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists();
    }

    /**
     * One paid demo payroll for the week + its accounting (expense + journal),
     * every row tagged as demo. Shared with the HRM demo.
     */
    public static function insertPaid(DemoContext $ctx, int $employeeId, string $from, string $to, float $amount, array $extra): int
    {
        static $methodId = null;
        $methodId ??= (int) (DB::table('payment_methods')->whereNull('deleted_at')->where('name', 'like', '%cash%')->orderBy('id')->value('id')
            ?: DB::table('payment_methods')->whereNull('deleted_at')->orderBy('id')->value('id'));

        $id = $ctx->insert('payrolls', $extra + [
            'user_id' => $ctx->userId,
            'Ref' => app(\App\Http\Controllers\hrm\PayrollController::class)->getNumberOrder(),
            'company_id' => $ctx->companyId,
            'date' => $to,
            'period_start' => $from,
            'period_end' => $to,
            'employee_id' => $employeeId,
            'account_id' => null,
            'amount' => $amount,
            'payment_method_id' => $methodId,
            'payment_status' => 'paid',
        ]);

        // Accounting: the linked expense + its journal entry, tagged as demo too.
        $beforeJe = (int) DB::table('acc_journal_entries')->max('id');
        $expense = PayrollExpenseSync::sync(Payroll::find($id));
        if ($expense) {
            $ctx->tag('expenses', (int) $expense->id);
        }
        foreach (DB::table('acc_journal_entries')->where('id', '>', $beforeJe)->pluck('id') as $je) {
            $ctx->tag('acc_journal_entries', (int) $je);
            foreach (DB::table('acc_journal_entry_lines')->where('journal_entry_id', $je)->pluck('id') as $line) {
                $ctx->tag('acc_journal_entry_lines', (int) $line);
            }
        }

        return $id;
    }
}
