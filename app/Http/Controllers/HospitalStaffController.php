<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\HmsMedicationDose;
use App\Models\HmsShiftPatient;
use App\Models\HmsStaffShift;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Hospital staff shifts (2026-10-21): the roster of a day -- the 24-hour
 * emergency doctor and, for each 8-hour shift, 3 specialists, 1 nurse
 * supervisor and 4 nurses -- and the patients each of them sees in the shift.
 * "My patients" is the logged-in doctor's / nurse's list for the shift now.
 * hms_shifts = manage the roster (head nurse, admin); hms_my_patients = see
 * one's own list.
 */
class HospitalStaffController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts', 'hms_my_patients'), 403);
        $date = $request->filled('date') ? \Carbon\Carbon::parse($request->date)->toDateString() : now()->toDateString();

        $rows = HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', $date)->orderBy('id')->get();
        $patients = HmsShiftPatient::whereIn('staff_shift_id', $rows->pluck('id'))->get()->groupBy('staff_shift_id');
        $patientNames = DB::table('patients')->whereIn('id', $patients->flatten()->pluck('patient_id'))->pluck('name', 'id');

        return response()->json([
            'date' => $date,
            // The company's shifts with their hours (Configuration -> Shifts) and the positions working each.
            'shifts' => HospitalShifts::types()->where('active', true)->values(),
            'positions' => HospitalShifts::positions(),
            'roster' => $rows->map(fn ($r) => [
                'id' => $r->id, 'shift' => $r->shift, 'position' => $r->position,
                'doctor_id' => $r->doctor_id, 'employee_id' => $r->employee_id, 'ward_id' => $r->ward_id,
                'name' => $this->personName($r), 'notes' => $r->notes,
                'patients' => ($patients[$r->id] ?? collect())->map(fn ($p) => [
                    'id' => $p->id, 'patient_id' => $p->patient_id, 'name' => $patientNames[$p->patient_id] ?? '',
                    'visited_at' => optional($p->visited_at)->toIso8601String(),
                ])->values(),
            ])->values(),
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'specialty']),
            'nurses' => $this->nurses(),
            'wards' => \App\Models\HospitalWard::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'type']),
            'census' => HospitalShifts::census(),
            'can_manage' => HospitalShifts::can($request->user('api'), 'hms_shifts'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $request->validate([
            'date' => 'required|date',
            'shift' => ['required', Rule::in(HospitalShifts::types()->where('active', true)->keys()->all())],
            'position' => ['required', Rule::in(array_keys(HospitalShifts::POSITIONS))],
            'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'employee_id' => ['nullable', 'integer'],
            'ward_id' => ['nullable', \App\Support\CompanyExists::rule('hospital_wards')],
        ]);
        [$shifts, $quota, $kind] = HospitalShifts::positions()[$request->position];
        if (! in_array($request->shift, $shifts, true)) {
            return response()->json(['message' => 'That position does not work this shift.'], 422);
        }
        if ($kind === 'doctor' && ! $request->doctor_id) {
            return response()->json(['message' => 'Pick the doctor.'], 422);
        }
        if ($kind === 'nurse' && ! $request->employee_id) {
            return response()->json(['message' => 'Pick the nurse.'], 422);
        }
        $date = \Carbon\Carbon::parse($request->date)->toDateString();
        $same = HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', $date)->where('shift', $request->shift);
        if ((clone $same)->where('position', $request->position)->count() >= $quota) {
            return response()->json(['message' => 'This shift already has its '.$quota.' place(s) for that position.'], 422);
        }
        $who = $kind === 'doctor' ? ['doctor_id', $request->doctor_id] : ['employee_id', $request->employee_id];
        if ((clone $same)->where($who[0], $who[1])->exists()) {
            return response()->json(['message' => 'That person is already on this shift.'], 422);
        }
        $row = HmsStaffShift::create([
            'shift_date' => $date, 'shift' => $request->shift, 'position' => $request->position,
            'doctor_id' => $kind === 'doctor' ? $request->doctor_id : null,
            'employee_id' => $kind === 'nurse' ? $request->employee_id : null,
            'ward_id' => $request->ward_id ?: null, 'notes' => $request->notes,
            'created_by' => optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true, 'id' => $row->id]);
    }

    public function destroy(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $row = HmsStaffShift::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(function () use ($row) {
            HmsShiftPatient::where('staff_shift_id', $row->id)->delete();
            $row->delete();
        });

        return response()->json(['success' => true]);
    }

    /** The patients this person sees in the shift (replaces the list). */
    public function setPatients(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $request->validate(['patient_ids' => 'array', 'patient_ids.*' => [\App\Support\CompanyExists::rule('patients')]]);
        $row = HmsStaffShift::whereNull('deleted_at')->findOrFail($id);
        $ids = array_values(array_unique(array_map('intval', $request->input('patient_ids', []))));
        DB::transaction(function () use ($row, $ids) {
            HmsShiftPatient::where('staff_shift_id', $row->id)->whereNotIn('patient_id', $ids ?: [0])->delete();
            $have = HmsShiftPatient::where('staff_shift_id', $row->id)->pluck('patient_id')->all();
            foreach (array_diff($ids, $have) as $pid) {
                HmsShiftPatient::create(['staff_shift_id' => $row->id, 'patient_id' => $pid, 'company_id' => $row->company_id]);
            }
        });

        return response()->json(['success' => true]);
    }

    /** Copy a day's roster (people and their patients) onto another day; places already taken are skipped. */
    public function copyDay(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $request->validate(['from' => 'required|date', 'to' => 'required|date|different:from']);
        $from = \Carbon\Carbon::parse($request->from)->toDateString();
        $to = \Carbon\Carbon::parse($request->to)->toDateString();
        $copied = 0;
        DB::transaction(function () use ($from, $to, $request, &$copied) {
            foreach (HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', $from)->get() as $r) {
                $exists = HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', $to)->where('shift', $r->shift)
                    ->where(fn ($q) => $r->doctor_id ? $q->where('doctor_id', $r->doctor_id) : $q->where('employee_id', $r->employee_id))->exists();
                if ($exists) {
                    continue;
                }
                $n = HmsStaffShift::create(['shift_date' => $to, 'shift' => $r->shift, 'position' => $r->position, 'doctor_id' => $r->doctor_id,
                    'employee_id' => $r->employee_id, 'ward_id' => $r->ward_id, 'notes' => $r->notes, 'company_id' => $r->company_id,
                    'created_by' => optional($request->user('api'))->id]);
                $census = HospitalShifts::census()->pluck('patient_id')->all();
                foreach (HmsShiftPatient::where('staff_shift_id', $r->id)->whereIn('patient_id', $census ?: [0])->pluck('patient_id') as $pid) {
                    HmsShiftPatient::create(['staff_shift_id' => $n->id, 'patient_id' => $pid, 'company_id' => $n->company_id]);
                }
                $copied++;
            }
        });

        return response()->json(['success' => true, 'copied' => $copied]);
    }

    // ------------------------------------------------------------------
    // Planning by month / semester (phase 4, 2026-10-25)
    // ------------------------------------------------------------------

    /** People per position and shift against the quota, day by day. */
    public function coverage(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = \Carbon\Carbon::parse($request->from)->toDateString();
        $to = \Carbon\Carbon::parse($request->to)->toDateString();
        abort_if(\Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) > 200, 422, 'Pick at most a semester.');

        return response()->json(HospitalShifts::coverage($from, $to) + [
            'shifts' => HospitalShifts::types()->where('active', true)->values(),
            'positions' => HospitalShifts::positions(),
            'plans' => DB::table('hms_shift_plans')->when(HmsStaffShift::actingCompanyId('hms_staff_shifts'), fn ($q, $cid) => $q->where('company_id', $cid))
                ->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    /**
     * Repeat a model week over a period (a month, a semester): each day takes
     * the people of the same weekday of the model week. With rotation every N
     * weeks, each person moves to the next shift of their position (morning ->
     * afternoon -> night -> morning). Places already taken, a person already
     * working that day and approved leaves are skipped. preview=1 counts only.
     */
    public function plan(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $request->validate([
            'from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'model_from' => 'required|date',
            'rotate_weeks' => 'nullable|integer|min:0|max:12', 'name' => 'nullable|string|max:120',
        ]);
        $from = \Carbon\Carbon::parse($request->from)->startOfDay();
        $to = \Carbon\Carbon::parse($request->to)->startOfDay();
        $modelFrom = \Carbon\Carbon::parse($request->model_from)->startOfDay();
        abort_if($from->diffInDays($to) > 200, 422, 'Pick at most a semester.');
        $modelTo = $modelFrom->copy()->addDays(6);
        abort_if($from <= $modelTo && $to >= $modelFrom, 422, 'The period cannot include the model week.');
        $rotate = (int) $request->input('rotate_weeks', 0);

        $model = HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', '>=', $modelFrom->toDateString())
            ->whereDate('shift_date', '<=', $modelTo->toDateString())->orderBy('id')->get()
            ->groupBy(fn ($r) => $r->shift_date->dayOfWeek);
        abort_if($model->isEmpty(), 422, 'The model week has no shifts. Fill in a week first.');

        $positions = HospitalShifts::positions();
        $existing = HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', '>=', $from->toDateString())
            ->whereDate('shift_date', '<=', $to->toDateString())->get(['shift_date', 'shift', 'position', 'doctor_id', 'employee_id']);
        $taken = [];   // date|shift|position => count
        $busy = [];    // date|d:id or date|e:id => true
        foreach ($existing as $e) {
            $date = $e->shift_date->toDateString();
            $k = $date.'|'.$e->shift.'|'.$e->position;
            $taken[$k] = ($taken[$k] ?? 0) + 1;
            $busy[$date.'|'.($e->doctor_id ? 'd:'.$e->doctor_id : 'e:'.$e->employee_id)] = true;
        }
        $leaves = \Illuminate\Support\Facades\Schema::hasTable('leaves')
            ? DB::table('leaves')->whereNull('deleted_at')->where('status', 'approved')
                ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())->get(['employee_id', 'start_date', 'end_date'])
            : collect();
        $doctorEmployee = DB::table('doctors')->whereNull('deleted_at')->whereNotNull('employee_id')->pluck('employee_id', 'id');
        $onLeave = function ($date, $r) use ($leaves, $doctorEmployee) {
            $emp = $r->employee_id ?: ($doctorEmployee[$r->doctor_id] ?? null);

            return $emp && $leaves->contains(fn ($l) => (int) $l->employee_id === (int) $emp && $l->start_date <= $date && substr($l->end_date, 0, 10) >= $date);
        };

        $insert = [];
        $skipped = 0;
        $leaveSkips = 0;
        $userId = optional($request->user('api'))->id;
        for ($d = $from->copy(); $d <= $to; $d->addDay()) {
            $date = $d->toDateString();
            $step = $rotate > 0 ? intdiv(intdiv((int) $from->diffInDays($d), 7), $rotate) : 0;
            foreach ($model->get($d->dayOfWeek, collect()) as $r) {
                $works = $positions[$r->position][0] ?? [$r->shift];
                $i = array_search($r->shift, $works, true);
                $shift = ($i === false || ! $step) ? $r->shift : $works[($i + $step) % count($works)];
                $who = $r->doctor_id ? 'd:'.$r->doctor_id : 'e:'.$r->employee_id;
                $k = $date.'|'.$shift.'|'.$r->position;
                if (isset($busy[$date.'|'.$who]) || ($taken[$k] ?? 0) >= ($positions[$r->position][1] ?? 1)) {
                    $skipped++;
                    continue;
                }
                if ($onLeave($date, $r)) {
                    $leaveSkips++;
                    continue;
                }
                $busy[$date.'|'.$who] = true;
                $taken[$k] = ($taken[$k] ?? 0) + 1;
                $insert[] = ['company_id' => $r->company_id, 'shift_date' => $date, 'shift' => $shift, 'position' => $r->position,
                    'doctor_id' => $r->doctor_id, 'employee_id' => $r->employee_id, 'ward_id' => $r->ward_id, 'notes' => $r->notes,
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()];
            }
        }

        $result = ['to_create' => count($insert), 'skipped' => $skipped, 'on_leave' => $leaveSkips];
        if ($request->boolean('preview')) {
            return response()->json($result);
        }
        abort_unless($insert, 422, 'Nothing to create: every place of the period is already taken.');
        $companyId = $insert[0]['company_id'];
        $planId = DB::transaction(function () use ($request, $from, $to, $modelFrom, $rotate, $insert, $userId, $companyId) {
            $planId = DB::table('hms_shift_plans')->insertGetId([
                'company_id' => $companyId, 'name' => $request->input('name') ?: $from->format('d/m/Y').' - '.$to->format('d/m/Y'),
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'model_from' => $modelFrom->toDateString(),
                'rotate_weeks' => $rotate, 'rows_created' => count($insert), 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('hms_staff_shifts')->insert(array_map(fn ($row) => $row + ['plan_id' => $planId], $chunk));
            }

            return $planId;
        });

        return response()->json($result + ['success' => true, 'plan_id' => $planId]);
    }

    /** Undo a plan: its shifts from today on (the days already worked stay). */
    public function destroyPlan(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_shifts'), 403);
        $plan = DB::table('hms_shift_plans')->where('id', $id)
            ->when(HmsStaffShift::actingCompanyId('hms_staff_shifts'), fn ($q, $cid) => $q->where('company_id', $cid))->first();
        abort_unless($plan, 404);
        $removed = 0;
        DB::transaction(function () use ($plan, &$removed) {
            $ids = HmsStaffShift::where('plan_id', $plan->id)->whereDate('shift_date', '>=', now()->toDateString())->pluck('id');
            HmsShiftPatient::whereIn('staff_shift_id', $ids)->delete();
            $removed = HmsStaffShift::whereIn('id', $ids)->delete();
            if (! HmsStaffShift::where('plan_id', $plan->id)->exists()) {
                DB::table('hms_shift_plans')->where('id', $plan->id)->delete();
            } else {
                DB::table('hms_shift_plans')->where('id', $plan->id)->update(['to_date' => now()->subDay()->toDateString(), 'updated_at' => now()]);
            }
        });

        return response()->json(['success' => true, 'removed' => $removed]);
    }

    /**
     * The logged-in doctor's / nurse's patients for the shift(s) running now,
     * with where each one is, the active treatment and the doses due (next 2 h
     * and overdue). A supervisor / admin with hms_shifts may pass ?all=1 to see
     * every patient in the hospital.
     */
    public function myPatients(Request $request)
    {
        $user = $request->user('api');
        abort_unless(HospitalShifts::can($user, 'hms_my_patients', 'hms_shifts'), 403);
        [$employeeId, $doctorIds] = HospitalShifts::staffOf($user);

        $mine = collect();
        foreach (HospitalShifts::current() as [$date, $shift]) {
            $q = HmsStaffShift::whereNull('deleted_at')->whereDate('shift_date', $date)->where('shift', $shift)
                ->where(fn ($w) => $w->when($employeeId, fn ($x) => $x->orWhere('employee_id', $employeeId))
                    ->when($doctorIds, fn ($x) => $x->orWhereIn('doctor_id', $doctorIds))
                    ->when(! $employeeId && ! $doctorIds, fn ($x) => $x->whereRaw('1 = 0')));
            $mine = $mine->merge($q->get());
        }
        $all = $request->boolean('all') && HospitalShifts::can($user, 'hms_shifts');
        $census = HospitalShifts::census()->keyBy('patient_id');
        $assigned = HmsShiftPatient::whereIn('staff_shift_id', $mine->pluck('id'))->get()->keyBy('patient_id');
        $ids = $all ? $census->keys()->all() : $assigned->keys()->all();

        $now = now();
        $doses = HmsMedicationDose::whereIn('patient_id', $ids ?: [0])->where('status', 'pending')
            ->where('scheduled_at', '<=', $now->copy()->addHours(2))->orderBy('scheduled_at')->get()->groupBy('patient_id');
        $orders = \App\Models\HmsMedicationOrder::whereIn('id', $doses->flatten()->pluck('order_id'))->get()->keyBy('id');

        return response()->json([
            'shifts' => $mine->map(fn ($r) => ['id' => $r->id, 'date' => optional($r->shift_date)->toDateString(), 'shift' => $r->shift, 'position' => $r->position])->values(),
            'can_all' => HospitalShifts::can($user, 'hms_shifts'),
            'patients' => collect($ids)->map(function ($pid) use ($census, $assigned, $doses, $orders, $now) {
                $c = $census[$pid] ?? ['patient_id' => $pid, 'name' => DB::table('patients')->where('id', $pid)->value('name'), 'area' => 'gone', 'where' => null, 'treatment_id' => null];
                $a = $assigned[$pid] ?? null;

                return $c + [
                    'assignment_id' => optional($a)->id,
                    'visited_at' => $a && $a->visited_at ? $a->visited_at->toIso8601String() : null,
                    'doses' => ($doses[$pid] ?? collect())->map(fn ($d) => [
                        'id' => $d->id, 'scheduled_at' => $d->scheduled_at->toIso8601String(), 'overdue' => $d->scheduled_at < $now,
                        'medicine' => optional($orders[$d->order_id] ?? null)->medicine, 'dose' => optional($orders[$d->order_id] ?? null)->dose,
                        'route' => optional($orders[$d->order_id] ?? null)->route, 'dispensed' => (bool) $d->dispensed,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    public function markVisited(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_my_patients', 'hms_shifts'), 403);
        $row = HmsShiftPatient::findOrFail($id);
        abort_unless(HmsStaffShift::whereKey($row->staff_shift_id)->exists(), 404); // acting company (global scope)
        $row->update(['visited_at' => $row->visited_at ? null : now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function personName(HmsStaffShift $r): ?string
    {
        if ($r->doctor_id) {
            return DB::table('doctors')->where('id', $r->doctor_id)->value('name');
        }
        $e = DB::table('employees')->where('id', $r->employee_id)->first(['firstname', 'lastname']);

        return $e ? trim($e->firstname.' '.$e->lastname) : null;
    }

    /** Nurses = the company's employees whose login has the Nurse / Head Nurse role (else every employee). */
    private function nurses()
    {
        $cid = \App\Models\HmsStaffShift::actingCompanyId('hms_staff_shifts');
        $q = DB::table('employees as e')->whereNull('e.deleted_at')->whereNull('e.leaving_date')
            ->when($cid !== null, fn ($w) => $w->where('e.company_id', $cid));
        $roleIds = DB::table('roles')->whereIn('name', ['Nurse', 'Head Nurse'])->whereNull('deleted_at')
            ->when($cid !== null, fn ($w) => $w->where('company_id', $cid))->pluck('id');
        $withRole = (clone $q)->join('role_user as ru', 'ru.user_id', '=', 'e.user_id')->whereIn('ru.role_id', $roleIds)
            ->leftJoin('roles as r', 'r.id', '=', 'ru.role_id')
            ->orderBy('e.firstname')->get(['e.id', 'e.firstname', 'e.lastname', 'r.name as role']);
        $rows = $withRole->isNotEmpty() ? $withRole : $q->orderBy('e.firstname')->get(['e.id', 'e.firstname', 'e.lastname']);

        return $rows->map(fn ($e) => ['id' => $e->id, 'name' => trim($e->firstname.' '.$e->lastname), 'head' => ($e->role ?? null) === 'Head Nurse'])->values();
    }
}
