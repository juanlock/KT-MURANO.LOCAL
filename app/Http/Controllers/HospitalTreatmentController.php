<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\HmsDispense;
use App\Models\HmsMedicationDose;
use App\Models\HmsMedicationOrder;
use App\Models\HmsShiftPatient;
use App\Models\HmsStaffShift;
use App\Models\HmsTreatment;
use App\Models\Patient;
use App\Models\Product;
use App\Support\HospitalShifts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Treatments (2026-10-21): what is being done for a patient in the hospital
 * -- diagnosis and plan, the doctors on it (one lead), the nurses who look
 * after the patient each shift (from the staff roster), the medication
 * orders (medicine, dose, route, every N hours from a start, for N days) and
 * the resulting dose schedule, where the nurse records each dose as given /
 * omitted / refused. Pharmacy dispatches the doses (stock goes down).
 *   hms_treatments            open / edit treatments and orders (doctors)
 *   hms_treatments_administer see them and record doses (nurses)
 *   hms_pharmacy_dispense     the dispatch queue (pharmacy)
 */
class HospitalTreatmentController extends Controller
{
    public const ROUTES = ['oral', 'iv', 'im', 'sc', 'topical', 'inhaled', 'sublingual', 'rectal', 'other'];

    public function index(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments', 'hms_treatments_administer'), 403);
        HmsMedicationDose::markMissed();
        $q = HmsTreatment::whereNull('deleted_at');
        $q->where('status', $request->get('status', 'active') ?: 'active');
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('reference', 'LIKE', "%$s%")->orWhere('diagnosis', 'LIKE', "%$s%")
                ->orWhereIn('patient_id', Patient::where('name', 'LIKE', "%$s%")->orWhere('mrn', 'LIKE', "%$s%")->select('id')));
        }
        $rows = $q->orderByDesc('started_at')->limit(300)->get();
        $census = HospitalShifts::census()->keyBy('patient_id');
        $patients = Patient::whereIn('id', $rows->pluck('patient_id'))->get(['id', 'name', 'mrn'])->keyBy('id');
        $doctors = Doctor::whereIn('id', $rows->pluck('lead_doctor_id')->filter())->pluck('name', 'id');
        $now = now();
        $due = HmsMedicationDose::whereIn('treatment_id', $rows->pluck('id'))->where('status', 'pending')
            ->where('scheduled_at', '<=', $now->copy()->addHour())->select('treatment_id', DB::raw('COUNT(*) n'), DB::raw('SUM(scheduled_at < NOW()) late'))
            ->groupBy('treatment_id')->get()->keyBy('treatment_id');

        return response()->json(['treatments' => $rows->map(fn ($t) => [
            'id' => $t->id, 'reference' => $t->reference, 'patient_id' => $t->patient_id,
            'patient_name' => optional($patients[$t->patient_id] ?? null)->name, 'patient_mrn' => optional($patients[$t->patient_id] ?? null)->mrn,
            'where' => optional($census[$t->patient_id] ?? null)['where'] ?? null, 'area' => optional($census[$t->patient_id] ?? null)['area'] ?? null,
            'diagnosis' => $t->diagnosis, 'lead_doctor' => $doctors[$t->lead_doctor_id] ?? null, 'status' => $t->status,
            'started_at' => optional($t->started_at)->toIso8601String(), 'surgery_id' => $t->surgery_id,
            'doses_due' => (int) optional($due[$t->id] ?? null)->n, 'doses_late' => (int) optional($due[$t->id] ?? null)->late,
        ])->values()]);
    }

    public function show(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments', 'hms_treatments_administer', 'hms_pharmacy_dispense'), 403);
        $t = HmsTreatment::whereNull('deleted_at')->findOrFail($id);
        HmsMedicationDose::markMissed();
        $patient = Patient::find($t->patient_id);
        $census = HospitalShifts::census()->firstWhere('patient_id', $t->patient_id);
        $docRows = DB::table('hms_treatment_doctors as td')->join('doctors as d', 'd.id', '=', 'td.doctor_id')
            ->where('td.treatment_id', $t->id)->get(['d.id', 'd.name', 'd.specialty', 'td.role']);

        // Care team by shift (today + current night): the roster rows that have this patient.
        $dates = [now()->subDay()->toDateString(), now()->toDateString()];
        $shiftRows = HmsStaffShift::whereNull('deleted_at')->whereIn(DB::raw('DATE(shift_date)'), $dates)
            ->whereIn('id', HmsShiftPatient::where('patient_id', $t->patient_id)->select('staff_shift_id'))->orderBy('shift_date')->get();
        $team = $shiftRows->map(fn ($r) => [
            'date' => optional($r->shift_date)->toDateString(), 'shift' => $r->shift, 'position' => $r->position,
            'name' => $r->doctor_id ? DB::table('doctors')->where('id', $r->doctor_id)->value('name')
                : trim((string) DB::table('employees')->where('id', $r->employee_id)->selectRaw("CONCAT(firstname,' ',lastname) n")->value('n')),
        ])->values();

        $orders = HmsMedicationOrder::whereNull('deleted_at')->where('treatment_id', $t->id)->orderBy('id')->get();
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->subHours(12);
        $doses = HmsMedicationDose::where('treatment_id', $t->id)->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<=', $from->copy()->addHours(48))->orderBy('scheduled_at')->get();
        $givers = DB::table('users')->whereIn('id', $doses->pluck('given_by')->filter())->pluck('username', 'id');

        return response()->json(['treatment' => [
            'id' => $t->id, 'reference' => $t->reference, 'status' => $t->status, 'diagnosis' => $t->diagnosis, 'plan' => $t->plan,
            'patient_id' => $t->patient_id, 'patient_name' => optional($patient)->name, 'patient_mrn' => optional($patient)->mrn,
            'allergies' => optional($patient)->allergies, 'blood_group' => optional($patient)->blood_group, 'age' => optional($patient)->age,
            'where' => $census['where'] ?? null, 'area' => $census['area'] ?? null,
            'admission_id' => $t->admission_id, 'er_case_id' => $t->er_case_id, 'surgery_id' => $t->surgery_id,
            'lead_doctor_id' => $t->lead_doctor_id, 'started_at' => optional($t->started_at)->toIso8601String(),
            'ended_at' => optional($t->ended_at)->toIso8601String(),
            'doctors' => $docRows, 'team' => $team,
            'orders' => $orders->map(fn ($o) => $this->presentOrder($o))->values(),
            'doses' => $doses->map(fn ($d) => [
                'id' => $d->id, 'order_id' => $d->order_id, 'scheduled_at' => $d->scheduled_at->toIso8601String(), 'status' => $d->status,
                'given_at' => optional($d->given_at)->toIso8601String(), 'given_by' => $givers[$d->given_by] ?? null,
                'dispensed' => (bool) $d->dispensed, 'notes' => $d->notes, 'late' => $d->status === 'pending' && $d->scheduled_at < now(),
                'today' => $d->scheduled_at->isSameDay(now()),
            ])->values(),
        ],
            'can_edit' => HospitalShifts::can($request->user('api'), 'hms_treatments'),
            'can_give' => HospitalShifts::can($request->user('api'), 'hms_treatments_administer', 'hms_treatments'),
        ]);
    }

    /** Doctors, medicines, routes and the patients in the hospital (for the forms). */
    public function meta(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments', 'hms_treatments_administer'), 403);
        $cid = HmsTreatment::actingCompanyId('hms_treatments');

        return response()->json([
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'specialty']),
            'census' => HospitalShifts::census(),
            'routes' => self::ROUTES,
            'medicines' => Product::whereNull('deleted_at')->where('is_active', 1)->where('type', '!=', 'is_service')
                ->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->orderBy('name')
                ->get(['id', 'name', 'generic_name', 'strength', 'dosage_form']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments'), 403);
        $request->validate($this->rules() + ['patient_id' => ['required', \App\Support\CompanyExists::rule('patients')]]);
        if (HmsTreatment::whereNull('deleted_at')->where('patient_id', $request->patient_id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'This patient already has an active treatment.'], 422);
        }
        $where = HospitalShifts::census()->firstWhere('patient_id', (int) $request->patient_id);
        $t = DB::transaction(function () use ($request, $where) {
            $t = HmsTreatment::create([
                'reference' => HmsTreatment::nextReference('TRT'), 'patient_id' => $request->patient_id,
                'admission_id' => $where['admission_id'] ?? null, 'er_case_id' => $where['er_case_id'] ?? null,
                'surgery_id' => $request->surgery_id ?: null,
                'lead_doctor_id' => $request->lead_doctor_id ?: null, 'diagnosis' => $request->diagnosis, 'plan' => $request->plan,
                'status' => 'active', 'started_at' => now(), 'created_by' => optional($request->user('api'))->id,
                'company_id' => Patient::withoutGlobalScope('company')->whereKey($request->patient_id)->value('company_id'),
            ]);
            $this->syncDoctors($t, $request);

            return $t;
        });

        return response()->json(['success' => true, 'id' => $t->id, 'reference' => $t->reference]);
    }

    public function update(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments'), 403);
        $request->validate($this->rules() + ['status' => 'nullable|in:active,completed,cancelled']);
        $t = HmsTreatment::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(function () use ($request, $t) {
            $data = ['diagnosis' => $request->diagnosis, 'plan' => $request->plan, 'lead_doctor_id' => $request->lead_doctor_id ?: null];
            if ($request->filled('status') && $request->status !== $t->status) {
                $data['status'] = $request->status;
                $data['ended_at'] = $request->status === 'active' ? null : now();
                if ($request->status !== 'active') {
                    // Ended: orders stop and future doses are no longer due.
                    HmsMedicationOrder::where('treatment_id', $t->id)->where('status', 'active')->update(['status' => 'completed']);
                    HmsMedicationDose::where('treatment_id', $t->id)->where('status', 'pending')->where('scheduled_at', '>', now())->delete();
                }
            }
            $t->update($data);
            $this->syncDoctors($t, $request);
        });

        return response()->json(['success' => true]);
    }

    /** A medication order -> its dose schedule (every N hours from the start, for N days). */
    public function storeOrder(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments'), 403);
        $request->validate([
            'product_id' => ['nullable', 'integer'],
            'medicine' => 'required_without:product_id|nullable|string|max:191',
            'dose' => 'required|string|max:64',
            'route' => ['nullable', Rule::in(self::ROUTES)],
            'every_hours' => 'required|integer|in:1,2,3,4,6,8,12,24',
            'starts_at' => 'required|date',
            'days' => 'required|integer|min:1|max:60',
            'units_per_dose' => 'nullable|numeric|min:0|max:100',
            'instructions' => 'nullable|string|max:255',
            'doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
        ]);
        $t = HmsTreatment::whereNull('deleted_at')->where('status', 'active')->findOrFail($id);
        $product = $request->product_id ? Product::whereNull('deleted_at')->where('company_id', $t->company_id)->find($request->product_id) : null;
        $order = DB::transaction(function () use ($request, $t, $product) {
            $o = HmsMedicationOrder::create([
                'treatment_id' => $t->id, 'product_id' => optional($product)->id,
                'medicine' => $product ? $product->name : $request->medicine, 'dose' => $request->dose, 'route' => $request->route ?: 'oral',
                'every_hours' => (int) $request->every_hours, 'starts_at' => Carbon::parse($request->starts_at), 'days' => (int) $request->days,
                'units_per_dose' => $request->filled('units_per_dose') ? $request->units_per_dose : 1, 'instructions' => $request->instructions,
                'status' => 'active', 'doctor_id' => $request->doctor_id ?: $t->lead_doctor_id,
                'created_by' => optional($request->user('api'))->id, 'company_id' => $t->company_id,
            ]);
            $this->schedule($o, $t);

            return $o;
        });

        return response()->json(['success' => true, 'id' => $order->id]);
    }

    public function stopOrder(Request $request, $orderId)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments'), 403);
        $o = HmsMedicationOrder::whereNull('deleted_at')->findOrFail($orderId);
        DB::transaction(function () use ($o) {
            $o->update(['status' => 'stopped']);
            HmsMedicationDose::where('order_id', $o->id)->where('status', 'pending')->where('scheduled_at', '>', now())->delete();
        });

        return response()->json(['success' => true]);
    }

    /** The nurse records a dose of TODAY: given / omitted / refused (with a note). */
    public function recordDose(Request $request, $doseId)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_treatments_administer', 'hms_treatments'), 403);
        $request->validate(['status' => 'required|in:given,omitted,refused,pending', 'notes' => 'nullable|string|max:255', 'scan' => 'nullable|string|max:64']);
        $d = HmsMedicationDose::findOrFail($doseId);
        abort_unless(HmsTreatment::whereKey($d->treatment_id)->exists(), 404); // acting company
        // Only today's doses (user's rule, 2026-10-25): tomorrow's can't be recorded
        // ahead, past days' unrecorded ones are "missed".
        if (! $d->recordableToday()) {
            return response()->json(['message' => $d->scheduled_at->isFuture() && ! $d->scheduled_at->isSameDay(now())
                ? 'Only today\'s doses can be recorded. This one is for another day.'
                : 'This dose was not recorded on its day: it stays as not given.'], 422);
        }
        // The kardex confirms a given dose by scanning the patient's wristband (2026-10-25): the barcode is the record number.
        if ($request->filled('scan') && $request->status === 'given') {
            $mrn = DB::table('patients')->where('id', $d->patient_id)->value('mrn');
            if (strcasecmp(trim($request->scan), (string) $mrn) !== 0) {
                return response()->json(['message' => 'The wristband scanned is not this patient\'s. Check the patient.'], 422);
            }
        }
        $d->update([
            'status' => $request->status, 'notes' => $request->notes,
            'given_at' => $request->status === 'pending' ? null : now(),
            'given_by' => $request->status === 'pending' ? null : optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------- pharmacy

    /** Pharmacy dispatch queue: active orders with doses due in the next 24 h not yet dispensed. */
    public function dispenseQueue(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_pharmacy_dispense'), 403);
        $until = now()->addHours(24);
        $pending = HmsMedicationDose::where('status', 'pending')->where('dispensed', false)->where('scheduled_at', '<=', $until)
            ->select('order_id', DB::raw('COUNT(*) n'), DB::raw('MIN(scheduled_at) first_at'))->groupBy('order_id')->get()->keyBy('order_id');
        $orders = HmsMedicationOrder::whereNull('deleted_at')->where('status', 'active')->whereIn('id', $pending->keys())->get();
        $patients = Patient::whereIn('id', HmsTreatment::whereIn('id', $orders->pluck('treatment_id'))->pluck('patient_id'))->pluck('name', 'id');
        $treatments = HmsTreatment::whereIn('id', $orders->pluck('treatment_id'))->get(['id', 'reference', 'patient_id'])->keyBy('id');
        $census = HospitalShifts::census()->keyBy('patient_id');
        $stock = $this->stockOf($orders->pluck('product_id')->filter()->all());

        return response()->json(['queue' => $orders->map(function ($o) use ($pending, $patients, $treatments, $census, $stock) {
            $t = $treatments[$o->treatment_id] ?? null;
            $p = $pending[$o->id];

            return $this->presentOrder($o) + [
                'treatment_reference' => optional($t)->reference, 'patient_name' => $t ? ($patients[$t->patient_id] ?? '') : '',
                'where' => $t ? (optional($census[$t->patient_id] ?? null)['where'] ?? null) : null,
                'doses_pending' => (int) $p->n, 'first_at' => Carbon::parse($p->first_at)->toIso8601String(),
                'stock' => $o->product_id ? ($stock[$o->product_id] ?? 0) : null,
            ];
        })->sortBy('first_at')->values()]);
    }

    /** Dispense N doses of an order: stock goes down (units per dose x N), those doses are marked dispensed. */
    public function dispense(Request $request, $orderId)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_pharmacy_dispense'), 403);
        $request->validate(['doses' => 'required|integer|min:1|max:200', 'notes' => 'nullable|string|max:255']);
        $o = HmsMedicationOrder::whereNull('deleted_at')->findOrFail($orderId);
        try {
        $res = DB::transaction(function () use ($request, $o) {
            $doses = HmsMedicationDose::where('order_id', $o->id)->where('status', 'pending')->where('dispensed', false)
                ->orderBy('scheduled_at')->limit((int) $request->doses)->lockForUpdate()->get();
            if ($doses->isEmpty()) {
                throw new \RuntimeException('Nothing left to dispense for this order.');
            }
            $qty = round($doses->count() * (float) $o->units_per_dose, 2);
            $warehouseId = null;
            if ($o->product_id) {
                $warehouseId = DB::table('settings')->where('company_id', $o->company_id)->whereNull('deleted_at')->value('warehouse_id')
                    ?: DB::table('warehouses')->where('company_id', $o->company_id)->whereNull('deleted_at')->orderBy('id')->value('id');
                $pw = \App\Models\product_warehouse::whereNull('deleted_at')->where('product_id', $o->product_id)
                    ->where('warehouse_id', $warehouseId)->whereNull('product_variant_id')->lockForUpdate()->first();
                if (! $pw || (float) $pw->qte < $qty) {
                    throw new \RuntimeException('Not enough stock in the pharmacy for '.$qty.' unit(s).');
                }
                $pw->qte = (float) $pw->qte - $qty;
                $pw->save();
            }
            HmsMedicationDose::whereIn('id', $doses->pluck('id'))->update(['dispensed' => true]);
            HmsDispense::create([
                'order_id' => $o->id, 'product_id' => $o->product_id, 'warehouse_id' => $warehouseId, 'quantity' => $qty,
                'doses' => $doses->count(), 'dispensed_at' => now(), 'dispensed_by' => optional($request->user('api'))->id,
                'notes' => $request->notes, 'company_id' => $o->company_id,
            ]);

            return ['doses' => $doses->count(), 'quantity' => $qty];
        });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true] + $res);
    }

    // ------------------------------------------------------------------

    private function schedule(HmsMedicationOrder $o, HmsTreatment $t): void
    {
        $at = $o->starts_at->copy();
        $end = $o->starts_at->copy()->addDays($o->days);
        $rows = [];
        while ($at < $end && count($rows) < 1000) {
            $rows[] = ['company_id' => $o->company_id, 'order_id' => $o->id, 'treatment_id' => $t->id, 'patient_id' => $t->patient_id,
                'scheduled_at' => $at->copy(), 'status' => 'pending', 'dispensed' => false, 'created_at' => now(), 'updated_at' => now()];
            $at->addHours($o->every_hours);
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            HmsMedicationDose::insert($chunk);
        }
    }

    private function syncDoctors(HmsTreatment $t, Request $request): void
    {
        if (! $request->has('doctor_ids') && ! $request->filled('lead_doctor_id')) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge((array) $request->input('doctor_ids', []), [$request->lead_doctor_id])))));
        $ids = Doctor::whereIn('id', $ids)->pluck('id')->all(); // acting company only
        DB::table('hms_treatment_doctors')->where('treatment_id', $t->id)->delete();
        foreach ($ids as $did) {
            DB::table('hms_treatment_doctors')->insert(['treatment_id' => $t->id, 'doctor_id' => $did,
                'role' => (int) $did === (int) $request->lead_doctor_id ? 'lead' : 'consultant', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function presentOrder(HmsMedicationOrder $o): array
    {
        $counts = HmsMedicationDose::where('order_id', $o->id)->select('status', DB::raw('COUNT(*) n'))->groupBy('status')->pluck('n', 'status');

        return [
            'id' => $o->id, 'treatment_id' => $o->treatment_id, 'product_id' => $o->product_id, 'medicine' => $o->medicine, 'dose' => $o->dose,
            'route' => $o->route, 'every_hours' => $o->every_hours, 'starts_at' => optional($o->starts_at)->toIso8601String(),
            'days' => $o->days, 'units_per_dose' => (float) $o->units_per_dose, 'instructions' => $o->instructions, 'status' => $o->status,
            'given' => (int) ($counts['given'] ?? 0), 'pending' => (int) ($counts['pending'] ?? 0),
            'omitted' => (int) (($counts['omitted'] ?? 0) + ($counts['refused'] ?? 0)),
            'missed' => (int) ($counts['missed'] ?? 0),
        ];
    }

    /** Stock of products in the acting company's warehouses. */
    private function stockOf(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }
        $cid = HmsTreatment::actingCompanyId('hms_treatments');

        return DB::table('product_warehouse as pw')->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->whereNull('pw.deleted_at')->whereNull('w.deleted_at')->whereIn('pw.product_id', $productIds)
            ->when($cid !== null, fn ($q) => $q->where('w.company_id', $cid))
            ->groupBy('pw.product_id')->selectRaw('pw.product_id, SUM(pw.qte) q')->pluck('q', 'product_id')->map(fn ($v) => (float) $v)->all();
    }

    private function rules(): array
    {
        return [
            'diagnosis' => 'nullable|string', 'plan' => 'nullable|string',
            'lead_doctor_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'doctor_ids' => 'nullable|array',
        ];
    }
}
