<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\StandardUsernames;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RRHH -> Daily attendance and Absence calendar (user's request 2026-11-28, from HTD-IT's attendance sheet and calendar).
 *
 * Daily attendance: one screen per day with every employee of the company, grouped by location (agency) or department
 * (settings.attendance_sheet.group_by). Everyone starts present; a tap marks someone absent with the leave type (or an
 * unjustified absence), half day and reason. Saving writes one `attendances` row per employee (source sheet / leave)
 * and marks the day taken (attendance_days), then the screen goes on to the next workday still to take. Someone who
 * clocked in that day (the header's clock) is present and locked; an approved leave shows absent with its type.
 * Left out of the sheet: disabled logins, employees who left, the standard {slug}-role logins and the staff types of
 * exclude_staff_types (Turismo: pilots -- their attendance is their trips).
 *
 * Absence calendar: a month with every day's holidays, approved and pending leaves (planned ahead) and the sheet's
 * absences, per employee, with the pending requests aside.
 *
 *  GET  hrm/attendance-sheet?date&end          the day (end = the last day of the 10-workday strip)
 *  POST hrm/attendance-sheet {date, rows[]}    save the day        POST hrm/attendance-sheet/present {date, employee_id}
 *  GET  hrm/attendance-sheet/pdf?date          the day as a PDF    GET  hrm/absence-calendar?month=YYYY-MM
 */
class AttendanceSheetController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    private const DEFAULTS = ['group_by' => 'department', 'workdays' => [1, 2, 3, 4, 5], 'exclude_staff_types' => []];

    private function allow(Request $request, string $perm): void
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), $perm), 403, __('You do not have permission for this.'));
    }

    private function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if(! $cid || $cid === 999, 422, __('Pick a company in the header first.'));

        return $cid;
    }

    private function config(int $cid): array
    {
        $raw = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('attendance_sheet');
        $cfg = array_merge(self::DEFAULTS, json_decode((string) $raw, true) ?: []);
        $cfg['workdays'] = array_map('intval', (array) $cfg['workdays']) ?: self::DEFAULTS['workdays'];

        return $cfg;
    }

    private function isWorkday(Carbon $d, array $cfg): bool
    {
        return in_array($d->dayOfWeek, $cfg['workdays'], true);
    }

    /** Holidays of the company between two dates: [Y-m-d => title]. */
    private function holidays(int $cid, string $from, string $to): array
    {
        $out = [];
        $rows = DB::table('holidays')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get(['title', 'start_date', 'end_date']);
        foreach ($rows as $h) {
            for ($d = Carbon::parse(max($from, substr($h->start_date, 0, 10))); $d->toDateString() <= min($to, substr($h->end_date, 0, 10)); $d->addDay()) {
                $out[$d->toDateString()] = $h->title;
            }
        }

        return $out;
    }

    /** Days whose sheet was saved: [Y-m-d => true]. */
    private function takenDays(int $cid, string $from, string $to): array
    {
        return DB::table('attendance_days')->where('company_id', $cid)->whereBetween('date', [$from, $to])
            ->pluck('date')->mapWithKeys(fn ($d) => [substr($d, 0, 10) => true])->all();
    }

    /**
     * The employees of the sheet, each with its group: [id => [id, name, photo, group_key, group_label, group_sort]].
     * A user who sees only some locations gets the employees of those locations only.
     */
    private function employees(int $cid, array $cfg, string $date, ?string $until = null): array
    {
        $until ??= $date;                                                      // a period: still there on $date, joined by $until
        $rows = DB::table('employees as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.company_id', $cid)->whereNull('e.deleted_at')
            ->where(fn ($q) => $q->whereNull('e.leaving_date')->orWhere('e.leaving_date', '')->orWhere('e.leaving_date', '>=', $date))
            ->where(fn ($q) => $q->whereNull('e.joining_date')->orWhere('e.joining_date', '')->orWhere('e.joining_date', '<=', $until))
            ->where(fn ($q) => $q->whereNull('e.user_id')->orWhere('u.statut', 1))
            ->when($cfg['exclude_staff_types'], fn ($q, $types) => $q->where(fn ($q) => $q->whereNull('e.staff_type')->orWhereNotIn('e.staff_type', $types)))
            ->get(['e.id', 'e.firstname', 'e.lastname', 'e.avatar', 'e.user_id', 'e.department_id', 'd.department', 'u.email', 'u.username', 'u.is_all_warehouses']);
        // The standard access / demo logins ({slug}-role, still with that username) are not staff; a person who only
        // kept the standard email (Mayte, Julio) is.
        $rows = $rows->filter(function ($r) {
            $std = StandardUsernames::forEmail($r->email);

            return $std === null || strcasecmp((string) $r->username, $std) !== 0;
        });

        $byLocation = $cfg['group_by'] === 'location';
        $whOf = [];
        if ($byLocation) {
            foreach (DB::table('user_warehouse')->whereIn('user_id', $rows->pluck('user_id')->filter())->get(['user_id', 'warehouse_id']) as $uw) {
                $whOf[$uw->user_id][] = (int) $uw->warehouse_id;
            }
        }
        $whNames = $byLocation ? DB::table('warehouses')->where('company_id', $cid)->pluck('name', 'id')->all() : [];
        $line = $byLocation && \Illuminate\Support\Facades\Schema::hasTable('transport_locations')
            ? DB::table('transport_locations')->where('company_id', $cid)->whereNotNull('warehouse_id')->pluck('line_order', 'warehouse_id')->all() : [];
        // A user with every location (or the SuperAdmin) sees everyone; else only the employees of their locations.
        $me = auth()->user();
        $scope = $me && ($me->type === 'SuperAdmin' || $me->is_all_warehouses) ? null : $this->warehouseScopeIds();

        $out = [];
        foreach ($rows as $r) {
            if ($byLocation) {
                $whs = $r->is_all_warehouses ? [] : array_values(array_unique($whOf[$r->user_id] ?? []));
                $wh = count($whs) === 1 ? $whs[0] : null;
                if ($scope !== null && ($wh === null || ! in_array($wh, $scope, true))) {
                    continue;
                }
                $key = $wh ? 'wh'.$wh : 'central';
                $label = $wh ? (string) ($whNames[$wh] ?? '') : null;          // null = head office (the screen names it)
                $sort = $wh ? sprintf('%04d-%s', $line[$wh] ?? 900, $label) : '9999';
            } else {
                $key = $r->department_id ? 'dep'.$r->department_id : 'none';
                $label = $r->department_id ? (string) $r->department : null;   // null = no department
                $sort = $r->department_id ? '0-'.mb_strtolower($label) : '9999';
            }
            $out[(int) $r->id] = [
                'id' => (int) $r->id, 'name' => trim($r->firstname.' '.$r->lastname),
                'photo' => $r->avatar && ! str_contains((string) $r->avatar, 'no_avatar') ? '/'.ltrim((string) $r->avatar, '/') : null,
                'group_key' => $key, 'group_label' => $label, 'group_sort' => $sort,
            ];
        }
        uasort($out, fn ($a, $b) => [$a['group_sort'], $a['name']] <=> [$b['group_sort'], $b['name']]);

        return $out;
    }

    /** Approved leaves covering the date: [employee_id => leave row]. */
    private function approvedLeaves(int $cid, string $date, array $ids): array
    {
        return DB::table('leaves')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'approved')
            ->whereIn('employee_id', $ids)->where('start_date', '<=', $date)->where('end_date', '>=', $date)
            ->get(['employee_id', 'leave_type_id', 'half_day', 'reason'])->keyBy('employee_id')->all();
    }

    private function leaveTypes(): array
    {
        return DB::table('leave_types')->whereNull('deleted_at')->orderBy('title')->get(['id', 'title'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'title' => $t->title])->all();
    }

    /** The next workday still to take after $date (up to today), else the oldest one skipped before it (60 days). */
    private function nextPending(int $cid, array $cfg, string $date): ?string
    {
        $today = Carbon::today();
        $from = $today->copy()->subDays(60);
        $taken = $this->takenDays($cid, $from->toDateString(), $today->toDateString());
        $hol = $this->holidays($cid, $from->toDateString(), $today->toDateString());
        $missing = fn (Carbon $d) => $this->isWorkday($d, $cfg) && ! isset($taken[$d->toDateString()]) && ! isset($hol[$d->toDateString()]);
        for ($d = Carbon::parse($date)->addDay(); $d->lte($today); $d->addDay()) {
            if ($missing($d)) {
                return $d->toDateString();
            }
        }
        for ($d = $from->copy(); $d->lt(Carbon::parse($date)); $d->addDay()) {
            if ($missing($d)) {
                return $d->toDateString();
            }
        }

        return null;
    }

    /** The 10 workdays ending on $end, each with its state: taken | gap (a later day was taken) | pending | holiday. */
    private function strip(int $cid, array $cfg, string $end, string $current): array
    {
        $days = [];
        for ($d = Carbon::parse($end); count($days) < 10; $d->subDay()) {
            if ($this->isWorkday($d, $cfg)) {
                $days[] = $d->copy();
            }
        }
        $days = array_reverse($days);
        $from = $days[0]->toDateString();
        $to = end($days)->toDateString();
        $taken = $this->takenDays($cid, $from, $to);
        $hol = $this->holidays($cid, $from, $to);
        $last = DB::table('attendance_days')->where('company_id', $cid)->where('date', '<=', Carbon::today()->toDateString())->max('date');
        $last = $last ? substr($last, 0, 10) : null;

        return array_map(function (Carbon $d) use ($taken, $hol, $last, $current) {
            $s = $d->toDateString();
            $state = isset($hol[$s]) ? 'holiday' : (isset($taken[$s]) ? 'taken' : ($last && $s < $last ? 'gap' : 'pending'));

            return ['date' => $s, 'dow' => $d->dayOfWeek, 'state' => $state, 'holiday' => $hol[$s] ?? null, 'current' => $s === $current];
        }, $days);
    }

    private function validDate(?string $date): string
    {
        $date = $date ?: Carbon::today()->toDateString();
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date), 422, __('Invalid date.'));

        return $date;
    }

    public function show(Request $request)
    {
        $this->allow($request, 'attendance_sheet');
        $cid = $this->company($request);
        $cfg = $this->config($cid);
        $date = $this->validDate($request->query('date'));
        $end = $request->query('end') ? $this->validDate($request->query('end')) : $date;
        $today = Carbon::today()->toDateString();
        if ($end > $today) {
            $end = $today;
        }

        $emps = $this->employees($cid, $cfg, $date);
        $ids = array_keys($emps);
        $rows = DB::table('attendances')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('date', $date)
            ->whereIn('employee_id', $ids)->orderBy('id')->get()->groupBy('employee_id');
        $leaves = $this->approvedLeaves($cid, $date, $ids);
        $lastType = DB::table('attendances')->where('company_id', $cid)->where('status', 'absent')->whereNotNull('leave_type_id')
            ->whereIn('employee_id', $ids)->orderByDesc('date')->get(['employee_id', 'leave_type_id'])->unique('employee_id')
            ->pluck('leave_type_id', 'employee_id')->all();

        $groups = [];
        $present = $absent = 0;
        foreach ($emps as $id => $e) {
            $mine = $rows->get($id, collect());
            $clock = $mine->first(fn ($r) => $r->source === null && (string) $r->clock_in !== '');
            $sheet = $mine->first(fn ($r) => in_array($r->source, ['sheet', 'leave'], true));
            $leave = $leaves[$id] ?? null;
            $item = $e + ['status' => 'present', 'leave_type_id' => null, 'half_day' => false, 'reason' => '', 'locked' => null,
                'clock_in' => null, 'default_leave_type_id' => isset($lastType[$id]) ? (int) $lastType[$id] : null];
            if ($clock) {
                $item['locked'] = 'clock';
                $item['clock_in'] = $clock->clock_in;
            } elseif ($sheet) {
                $item['status'] = $sheet->status === 'absent' ? 'absent' : 'present';
                $item['leave_type_id'] = $sheet->leave_type_id ? (int) $sheet->leave_type_id : null;
                $item['half_day'] = (bool) $sheet->half_day;
                $item['reason'] = (string) $sheet->reason;
                $item['leave'] = $sheet->source === 'leave';
            } elseif ($leave) {
                $item['status'] = 'absent';
                $item['leave_type_id'] = $leave->leave_type_id ? (int) $leave->leave_type_id : null;
                $item['half_day'] = (bool) $leave->half_day;
                $item['reason'] = (string) $leave->reason;
                $item['leave'] = true;
            }
            $item['status'] === 'absent' ? $absent++ : $present++;
            unset($item['group_sort']);
            $groups[$e['group_key']] ??= ['key' => $e['group_key'], 'label' => $e['group_label'], 'employees' => []];
            $groups[$e['group_key']]['employees'][] = $item;
        }

        $taken = DB::table('attendance_days as a')->leftJoin('users as u', 'u.id', '=', 'a.saved_by')
            ->where('a.company_id', $cid)->whereDate('a.date', $date)->first(['a.saved_at', 'u.username']);
        $hol = $this->holidays($cid, $date, $date);

        return response()->json([
            'date' => $date, 'today' => $today, 'group_by' => $cfg['group_by'],
            'is_workday' => $this->isWorkday(Carbon::parse($date), $cfg), 'future' => $date > $today,
            'holiday' => $hol[$date] ?? null,
            'taken' => $taken ? ['at' => $taken->saved_at, 'by' => $taken->username] : null,
            'groups' => array_values($groups),
            'stats' => ['total' => count($emps), 'present' => $present, 'absent' => $absent],
            'leave_types' => $this->leaveTypes(),
            'strip' => $this->strip($cid, $cfg, $end, $date),
            'next_pending' => $this->nextPending($cid, $cfg, $date),
        ]);
    }

    public function save(Request $request)
    {
        $this->allow($request, 'attendance_sheet');
        $cid = $this->company($request);
        $cfg = $this->config($cid);
        $date = $this->validDate($request->input('date'));
        abort_if($date > Carbon::today()->toDateString(), 422, __('Attendance cannot be taken for a day still to come.'));
        $request->validate(['rows' => 'array', 'rows.*.employee_id' => 'required|integer', 'rows.*.present' => 'boolean',
            'rows.*.leave_type_id' => 'nullable|integer', 'rows.*.half_day' => 'nullable|boolean', 'rows.*.reason' => 'nullable|string|max:255']);

        $emps = $this->employees($cid, $cfg, $date);
        $leaves = $this->approvedLeaves($cid, $date, array_keys($emps));
        $types = array_column($this->leaveTypes(), 'id');
        $uid = (int) $request->user('api')->id;
        $now = now();

        DB::transaction(function () use ($request, $emps, $leaves, $types, $cid, $date, $uid, $now) {
            foreach ((array) $request->input('rows', []) as $r) {
                $eid = (int) $r['employee_id'];
                if (! isset($emps[$eid])) {
                    continue;                                                  // not of this company / not in the user's locations
                }
                $mine = DB::table('attendances')->where('company_id', $cid)->where('employee_id', $eid)->whereNull('deleted_at')->whereDate('date', $date);
                if ((clone $mine)->whereNull('source')->where('clock_in', '!=', '')->exists()) {
                    continue;                                                  // clocked in: present, the clock's row stays
                }
                $present = (bool) ($r['present'] ?? true);
                $type = ! $present && in_array((int) ($r['leave_type_id'] ?? 0), $types, true) ? (int) $r['leave_type_id'] : null;
                $leave = $leaves[$eid] ?? null;
                $vals = [
                    'status' => $present ? 'present' : 'absent', 'leave_type_id' => $type,
                    'half_day' => ! $present && ! empty($r['half_day']), 'reason' => $present ? null : (trim((string) ($r['reason'] ?? '')) ?: null),
                    'source' => ! $present && $leave && (int) $leave->leave_type_id === (int) $type ? 'leave' : 'sheet',
                    'user_id' => $uid, 'updated_at' => $now,
                ];
                $row = (clone $mine)->whereIn('source', ['sheet', 'leave'])->orderBy('id')->first();
                if ($row) {
                    DB::table('attendances')->where('id', $row->id)->update($vals);
                } else {
                    DB::table('attendances')->insert($vals + [
                        'company_id' => $cid, 'employee_id' => $eid, 'date' => $date, 'clock_in' => '', 'clock_in_ip' => '', 'clock_out' => '',
                        'clock_out_ip' => '', 'clock_in_out' => 0, 'depart_early' => '00:00', 'late_time' => '00:00', 'overtime' => '00:00',
                        'total_work' => '00:00', 'total_rest' => '00:00', 'created_at' => $now,
                    ]);
                }
            }
            DB::table('attendance_days')->updateOrInsert(['company_id' => $cid, 'date' => $date],
                ['saved_by' => $uid, 'saved_at' => $now, 'updated_at' => $now, 'created_at' => $now]);
        });

        return response()->json(['success' => true, 'next' => $this->nextPending($cid, $cfg, $date)]);
    }

    /** Correct one absence to present (the absent list's "Presente"), without saving the whole day. */
    public function present(Request $request)
    {
        $this->allow($request, 'attendance_sheet');
        $cid = $this->company($request);
        $date = $this->validDate($request->input('date'));
        $eid = (int) $request->input('employee_id');
        abort_unless(isset($this->employees($cid, $this->config($cid), $date)[$eid]), 404);
        DB::table('attendances')->where('company_id', $cid)->where('employee_id', $eid)->whereNull('deleted_at')->whereDate('date', $date)
            ->whereIn('source', ['sheet', 'leave'])->update(['status' => 'present', 'leave_type_id' => null, 'half_day' => 0, 'reason' => null,
                'source' => 'sheet', 'user_id' => (int) $request->user('api')->id, 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function pdf(Request $request)
    {
        $this->allow($request, 'attendance_sheet');
        $cid = $this->company($request);
        $data = $this->show($request)->getData(true);
        $es = (string) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('default_language') !== 'en';
        $types = array_column($data['leave_types'], 'title', 'id');
        $html = view('pdf.attendance_sheet', [
            'es' => $es, 'company' => (string) DB::table('companies')->where('id', $cid)->value('name'),
            'day' => Carbon::parse($data['date'])->locale($es ? 'es' : 'en'), 'data' => $data,
            'typeLabel' => fn ($id) => $id ? self::typeLabel($types[$id] ?? '', $es) : ($es ? 'Falta' : 'Absent'),
        ])->render();
        $bytes = \PDF::loadHTML($html)->setPaper('a4', 'landscape')->output();

        return response($bytes, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="asistencia-'.$data['date'].'.pdf"']);
    }

    /** The standard leave types in Spanish (they are install-wide rows in English). */
    public static function typeLabel(string $title, bool $es): string
    {
        $map = ['annual leave' => 'Vacaciones', 'sick leave' => 'Enfermedad', 'personal leave' => 'Asunto personal',
            'maternity leave' => 'Maternidad', 'paternity leave' => 'Paternidad', 'unpaid leave' => 'Sin goce de haber'];

        return $es ? ($map[mb_strtolower(trim($title))] ?? $title) : $title;
    }

    public function calendar(Request $request)
    {
        $this->allow($request, 'absence_calendar');
        $cid = $this->company($request);
        $cfg = $this->config($cid);
        $month = (string) $request->query('month', Carbon::today()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month), 422, __('Invalid date.'));
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth();
        $from = $start->toDateString();
        $to = $end->toDateString();

        // Everyone who may appear (also pilots and the head office): the calendar is for planning, not the sheet.
        $emps = $this->employees($cid, ['exclude_staff_types' => []] + $cfg, $from, $to);
        $ids = array_keys($emps);
        $groupOf = fn ($id) => $emps[$id]['group_key'] ?? null;
        $days = [];
        $add = function (string $d, array $item) use (&$days) {
            $days[$d]['items'][] = $item;
        };

        $leaves = DB::table('leaves')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])
            ->whereIn('employee_id', $ids)->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->get(['id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'half_day', 'reason', 'status', 'days']);
        foreach ($leaves as $l) {
            for ($d = Carbon::parse(max($from, substr($l->start_date, 0, 10))); $d->toDateString() <= min($to, substr($l->end_date, 0, 10)); $d->addDay()) {
                $add($d->toDateString(), ['employee_id' => (int) $l->employee_id, 'name' => $emps[$l->employee_id]['name'] ?? '', 'group' => $groupOf($l->employee_id),
                    'leave_type_id' => $l->leave_type_id ? (int) $l->leave_type_id : null, 'half_day' => (bool) $l->half_day,
                    'reason' => (string) $l->reason, 'kind' => $l->status === 'approved' ? 'leave' : 'pending']);
            }
        }
        // The sheet's absences that no leave explains (a leave already shows its days).
        $abs = DB::table('attendances')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'absent')->where('source', 'sheet')
            ->whereIn('employee_id', $ids)->whereBetween('date', [$from, $to])->get(['employee_id', 'date', 'leave_type_id', 'half_day', 'reason']);
        foreach ($abs as $a) {
            $add(substr($a->date, 0, 10), ['employee_id' => (int) $a->employee_id, 'name' => $emps[$a->employee_id]['name'] ?? '', 'group' => $groupOf($a->employee_id),
                'leave_type_id' => $a->leave_type_id ? (int) $a->leave_type_id : null, 'half_day' => (bool) $a->half_day,
                'reason' => (string) $a->reason, 'kind' => 'absent']);
        }
        foreach ($this->holidays($cid, $from, $to) as $d => $title) {
            $days[$d]['holiday'] = $title;
        }
        foreach ($this->takenDays($cid, $from, $to) as $d => $_) {
            $days[$d]['taken'] = true;
        }
        foreach ($days as &$day) {
            usort($day['items'], fn ($a, $b) => $a['name'] <=> $b['name']);
            $day += ['items' => [], 'holiday' => null, 'taken' => false];
        }
        unset($day);

        $groups = [];
        foreach ($emps as $e) {
            $groups[$e['group_key']] ??= ['key' => $e['group_key'], 'label' => $e['group_label'], 'sort' => $e['group_sort']];
        }
        usort($groups, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $pending = DB::table('leaves')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'pending')->whereIn('employee_id', $ids)
            ->orderBy('start_date')->get(['id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'reason'])
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $emps[$l->employee_id]['name'] ?? '', 'leave_type_id' => $l->leave_type_id ? (int) $l->leave_type_id : null,
                'start' => substr($l->start_date, 0, 10), 'end' => substr($l->end_date, 0, 10), 'days' => $l->days, 'reason' => (string) $l->reason])->all();

        return response()->json([
            'month' => $month, 'today' => Carbon::today()->toDateString(), 'group_by' => $cfg['group_by'], 'workdays' => $cfg['workdays'],
            'days' => $days, 'groups' => array_map(fn ($g) => ['key' => $g['key'], 'label' => $g['label']], $groups),
            'leave_types' => $this->leaveTypes(), 'pending' => $pending,
        ]);
    }
}
