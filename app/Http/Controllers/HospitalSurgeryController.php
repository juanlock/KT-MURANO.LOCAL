<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\HmsOperatingRoom;
use App\Models\HmsSurgery;
use App\Models\HmsSurgeryEvent;
use App\Models\HmsTreatment;
use App\Models\Patient;
use App\Support\HospitalShifts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Operating room (2026-10-21): operating rooms and surgeries. A surgery is
 * scheduled (patient, surgeon, anesthetist, team, room, date), carries the
 * pre-op (diagnosis, planned procedure, risks, consent), the surgery itself
 * (procedure performed, anesthesia, start / end, findings, complications) and
 * the post-op (final diagnosis, instructions). The patient's moves are logged
 * step by step: pre-op -> in surgery (room in use) -> recovery (room to
 * cleaning) -> back to the bed (completed: the post-op opens / updates the
 * patient's Treatment, the surgeon joins its doctors). It can be billed (a
 * sale, through the hospital invoice). Permission hms_surgery.
 */
class HospitalSurgeryController extends Controller
{
    /** status => the next statuses a "move" may go to */
    private const FLOW = [
        'scheduled' => ['pre_op', 'cancelled'],
        'pre_op' => ['in_surgery', 'scheduled', 'cancelled'],
        'in_surgery' => ['recovery'],
        'recovery' => ['completed'],
        'completed' => [],
        'cancelled' => ['scheduled'],
    ];

    public function index(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $q = HmsSurgery::whereNull('deleted_at');
        if ($request->filled('start_date')) {
            $q->whereDate('scheduled_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $q->whereDate('scheduled_at', '<=', $request->end_date);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        $rows = $q->orderBy('scheduled_at')->limit(300)->get();
        $patients = Patient::whereIn('id', $rows->pluck('patient_id'))->get(['id', 'name', 'mrn'])->keyBy('id');
        $doctors = Doctor::whereIn('id', $rows->pluck('surgeon_id')->merge($rows->pluck('anesthetist_id'))->filter())->pluck('name', 'id');
        $rooms = HmsOperatingRoom::whereNull('deleted_at')->orderBy('name')->get();

        return response()->json([
            'surgeries' => $rows->map(fn ($s) => $this->present($s, $patients, $doctors, $rooms->keyBy('id')))->values(),
            'rooms' => $rooms->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status, 'notes' => $r->notes])->values(),
            'doctors' => Doctor::whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'specialty']),
            'census' => HospitalShifts::census(),
        ]);
    }

    public function show(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $s = HmsSurgery::whereNull('deleted_at')->findOrFail($id);
        $patients = Patient::whereKey($s->patient_id)->get(['id', 'name', 'mrn', 'allergies', 'blood_group'])->keyBy('id');
        $doctors = Doctor::whereIn('id', array_filter([$s->surgeon_id, $s->anesthetist_id]))->pluck('name', 'id');
        $rooms = HmsOperatingRoom::whereKey($s->operating_room_id)->get()->keyBy('id');
        $users = DB::table('users')->pluck('username', 'id');

        return response()->json(['surgery' => $this->present($s, $patients, $doctors, $rooms) + [
            'allergies' => optional($patients[$s->patient_id] ?? null)->allergies,
            'blood_group' => optional($patients[$s->patient_id] ?? null)->blood_group,
            'events' => HmsSurgeryEvent::where('surgery_id', $s->id)->orderBy('at')->get()->map(fn ($e) => [
                'event' => $e->event, 'at' => $e->at->toIso8601String(), 'by' => $users[$e->user_id] ?? null, 'notes' => $e->notes,
            ])->values(),
            'treatment_id' => HmsTreatment::where('surgery_id', $s->id)->value('id'),
            'invoice_id' => DB::table('hospital_invoices')->whereNull('deleted_at')->where('notes', 'like', '%'.$s->reference.'%')->value('id'),
        ]]);
    }

    public function store(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $request->validate($this->rules() + ['patient_id' => ['required', \App\Support\CompanyExists::rule('patients')]]);
        if ($clash = $this->roomClash($request)) {
            return response()->json(['message' => 'That room is booked then ('.$clash.').'], 422);
        }
        $where = HospitalShifts::census()->firstWhere('patient_id', (int) $request->patient_id);
        $s = HmsSurgery::create($this->payload($request) + [
            'reference' => HmsSurgery::nextReference('SUR'), 'patient_id' => $request->patient_id,
            'admission_id' => $where['admission_id'] ?? null, 'status' => 'scheduled',
            'created_by' => optional($request->user('api'))->id,
            'company_id' => Patient::withoutGlobalScope('company')->whereKey($request->patient_id)->value('company_id'),
        ]);
        $this->event($s, 'scheduled', $request->user('api'));

        return response()->json(['success' => true, 'id' => $s->id, 'reference' => $s->reference]);
    }

    public function update(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $request->validate($this->rules());
        $s = HmsSurgery::whereNull('deleted_at')->findOrFail($id);
        if ($clash = $this->roomClash($request, $s->id)) {
            return response()->json(['message' => 'That room is booked then ('.$clash.').'], 422);
        }
        $s->update($this->payload($request) + $request->only('procedure_performed', 'anesthesia', 'findings', 'complications', 'post_op_diagnosis', 'post_op_instructions'));

        return response()->json(['success' => true]);
    }

    /**
     * Move the patient one step: pre_op / in_surgery / recovery / completed /
     * cancelled / scheduled. Room status follows; "completed" opens or updates
     * the patient's treatment with the post-op diagnosis and instructions.
     */
    public function move(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $request->validate(['status' => ['required', Rule::in(array_keys(self::FLOW))], 'notes' => 'nullable|string|max:255']);
        $s = HmsSurgery::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($request->status, self::FLOW[$s->status] ?? [], true)) {
            return response()->json(['message' => 'That step does not follow the current one.'], 422);
        }
        if ($request->status === 'in_surgery' && ! $s->consent_signed) {
            return response()->json(['message' => 'The consent is not signed yet.'], 422);
        }
        if ($request->status === 'in_surgery' && ! $s->operating_room_id) {
            return response()->json(['message' => 'Pick the operating room first.'], 422);
        }
        $user = $request->user('api');
        try {
        $treatmentId = DB::transaction(function () use ($request, $s, $user) {
            $data = ['status' => $request->status];
            $room = $s->operating_room_id ? HmsOperatingRoom::lockForUpdate()->find($s->operating_room_id) : null;
            if ($request->status === 'in_surgery') {
                if ($room && $room->status === 'in_use') {
                    throw new \RuntimeException('That operating room is in use.');
                }
                $data['started_at'] = now();
                optional($room)->update(['status' => 'in_use']);
            } elseif ($request->status === 'recovery') {
                $data['ended_at'] = now();
                optional($room)->update(['status' => 'cleaning']);
            } elseif ($request->status === 'cancelled' && $room && $room->status === 'in_use') {
                $room->update(['status' => 'available']);
            }
            $s->update($data);
            $this->event($s, $request->status, $user, $request->notes);

            return $request->status === 'completed' ? $this->postOpTreatment($s->fresh(), $user) : null;
        });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'treatment_id' => $treatmentId]);
    }

    /** Bill the surgery: a hospital invoice with the procedure line -> its sale. */
    public function bill(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery', 'hms_billing'), 403);
        $s = HmsSurgery::whereNull('deleted_at')->findOrFail($id);
        if ((float) $s->fee <= 0) {
            return response()->json(['message' => 'Set the surgery fee first.'], 422);
        }
        if (DB::table('hospital_invoices')->whereNull('deleted_at')->where('notes', 'like', '%'.$s->reference.'%')->exists()) {
            return response()->json(['message' => 'This surgery is already billed.'], 422);
        }
        $invoice = DB::transaction(function () use ($s, $request) {
            $inv = \App\Models\HospitalInvoice::create([
                'company_id' => $s->company_id, 'reference' => \App\Models\HospitalInvoice::nextReference('INV'),
                'patient_id' => $s->patient_id, 'admission_id' => $s->admission_id, 'invoice_date' => now()->toDateString(),
                'discount' => 0, 'tax' => 0, 'subtotal' => $s->fee, 'total' => $s->fee, 'paid' => 0, 'status' => 'unpaid',
                'notes' => 'Surgery '.$s->reference, 'created_by' => optional($request->user('api'))->id,
            ]);
            \App\Models\HospitalInvoiceItem::create([
                'invoice_id' => $inv->id, 'type' => 'procedure',
                'description' => trim(($s->procedure_performed ?: $s->planned_procedure ?: 'Surgery').' — '.$s->reference),
                'quantity' => 1, 'unit_price' => $s->fee, 'total' => $s->fee,
            ]);
            \App\Services\HospitalSale::sync($inv);

            return $inv;
        });

        return response()->json(['success' => true, 'invoice_id' => $invoice->id, 'reference' => $invoice->reference]);
    }

    public function destroy(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $s = HmsSurgery::whereNull('deleted_at')->findOrFail($id);
        if (in_array($s->status, ['in_surgery', 'recovery', 'completed'], true)) {
            return response()->json(['message' => 'A surgery that has started cannot be deleted.'], 422);
        }
        $s->delete();

        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------------- rooms

    public function saveRoom(Request $request, $id = null)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $request->validate(['name' => 'required|string|max:100', 'status' => 'nullable|in:available,in_use,cleaning,maintenance', 'notes' => 'nullable|string|max:255']);
        $room = $id ? HmsOperatingRoom::whereNull('deleted_at')->findOrFail($id) : new HmsOperatingRoom;
        $room->fill(['name' => $request->name, 'status' => $request->status ?: ($room->status ?: 'available'), 'notes' => $request->notes]);
        if (! $room->exists && ! $room->company_id) {
            $room->company_id = $this->resolveCompany($request);
        }
        $room->save();

        return response()->json(['success' => true, 'id' => $room->id]);
    }

    public function destroyRoom(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_surgery'), 403);
        $room = HmsOperatingRoom::whereNull('deleted_at')->findOrFail($id);
        if (HmsSurgery::whereNull('deleted_at')->where('operating_room_id', $room->id)->whereIn('status', ['scheduled', 'pre_op', 'in_surgery'])->exists()) {
            return response()->json(['message' => 'This room has surgeries booked.'], 422);
        }
        $room->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function postOpTreatment(HmsSurgery $s, $user): int
    {
        $t = HmsTreatment::whereNull('deleted_at')->where('patient_id', $s->patient_id)->where('status', 'active')->first();
        $note = trim(($s->post_op_instructions ?? ''));
        if ($t) {
            $t->update([
                'surgery_id' => $s->id,
                'diagnosis' => $s->post_op_diagnosis ?: $t->diagnosis,
                'plan' => trim(($t->plan ? $t->plan."\n\n" : '').'Post-op '.$s->reference.': '.$note),
            ]);
        } else {
            $where = HospitalShifts::census()->firstWhere('patient_id', $s->patient_id);
            $t = HmsTreatment::create([
                'reference' => HmsTreatment::nextReference('TRT'), 'patient_id' => $s->patient_id, 'surgery_id' => $s->id,
                'admission_id' => $where['admission_id'] ?? $s->admission_id, 'er_case_id' => $where['er_case_id'] ?? null,
                'lead_doctor_id' => $s->surgeon_id, 'diagnosis' => $s->post_op_diagnosis ?: $s->pre_op_diagnosis,
                'plan' => 'Post-op '.$s->reference.': '.$note, 'status' => 'active', 'started_at' => now(),
                'created_by' => optional($user)->id, 'company_id' => $s->company_id,
            ]);
        }
        if ($s->surgeon_id && ! DB::table('hms_treatment_doctors')->where('treatment_id', $t->id)->where('doctor_id', $s->surgeon_id)->exists()) {
            DB::table('hms_treatment_doctors')->insert(['treatment_id' => $t->id, 'doctor_id' => $s->surgeon_id, 'role' => 'surgeon', 'created_at' => now(), 'updated_at' => now()]);
        }

        return (int) $t->id;
    }

    private function event(HmsSurgery $s, string $event, $user, ?string $notes = null): void
    {
        HmsSurgeryEvent::create(['surgery_id' => $s->id, 'event' => $event, 'at' => now(), 'user_id' => optional($user)->id, 'notes' => $notes]);
    }

    /** Another live surgery in the same room overlapping this time (its reference), or null. */
    private function roomClash(Request $request, ?int $ignoreId = null): ?string
    {
        if (! $request->operating_room_id || ! $request->scheduled_at) {
            return null;
        }
        $start = Carbon::parse($request->scheduled_at);
        $end = $start->copy()->addMinutes((int) ($request->estimated_minutes ?: 60));
        foreach (HmsSurgery::whereNull('deleted_at')->where('operating_room_id', $request->operating_room_id)
            ->whereIn('status', ['scheduled', 'pre_op', 'in_surgery'])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereBetween('scheduled_at', [$start->copy()->subHours(24), $end])->get() as $o) {
            $oEnd = $o->scheduled_at->copy()->addMinutes($o->estimated_minutes ?: 60);
            if ($o->scheduled_at < $end && $oEnd > $start) {
                return $o->reference;
            }
        }

        return null;
    }

    private function resolveCompany(Request $request): ?int
    {
        return HmsOperatingRoom::actingCompanyId('hms_operating_rooms') ?? (int) optional($request->user('api'))->company_id;
    }

    private function payload(Request $request): array
    {
        return [
            'operating_room_id' => $request->operating_room_id ?: null, 'surgeon_id' => $request->surgeon_id ?: null,
            'anesthetist_id' => $request->anesthetist_id ?: null, 'team' => $request->team,
            'scheduled_at' => Carbon::parse($request->scheduled_at), 'estimated_minutes' => (int) ($request->estimated_minutes ?: 60),
            'priority' => $request->priority ?: 'elective', 'pre_op_diagnosis' => $request->pre_op_diagnosis,
            'planned_procedure' => $request->planned_procedure, 'risks' => $request->risks,
            'consent_signed' => $request->boolean('consent_signed'), 'fee' => (float) ($request->fee ?: 0),
        ];
    }

    private function rules(): array
    {
        return [
            'operating_room_id' => ['nullable', \App\Support\CompanyExists::rule('hms_operating_rooms')],
            'surgeon_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'anesthetist_id' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'scheduled_at' => 'required|date', 'estimated_minutes' => 'nullable|integer|min:5|max:1440',
            'priority' => 'nullable|in:elective,urgent,emergency', 'fee' => 'nullable|numeric|min:0',
            'team' => 'nullable|string', 'pre_op_diagnosis' => 'nullable|string', 'planned_procedure' => 'nullable|string', 'risks' => 'nullable|string',
        ];
    }

    private function present(HmsSurgery $s, $patients, $doctors, $rooms): array
    {
        $p = $patients[$s->patient_id] ?? null;

        return [
            'id' => $s->id, 'reference' => $s->reference, 'patient_id' => $s->patient_id,
            'patient_name' => optional($p)->name, 'patient_mrn' => optional($p)->mrn,
            'operating_room_id' => $s->operating_room_id, 'room' => optional($rooms[$s->operating_room_id] ?? null)->name,
            'surgeon_id' => $s->surgeon_id, 'surgeon' => $doctors[$s->surgeon_id] ?? null,
            'anesthetist_id' => $s->anesthetist_id, 'anesthetist' => $doctors[$s->anesthetist_id] ?? null, 'team' => $s->team,
            'scheduled_at' => optional($s->scheduled_at)->toIso8601String(), 'estimated_minutes' => $s->estimated_minutes,
            'status' => $s->status, 'priority' => $s->priority, 'pre_op_diagnosis' => $s->pre_op_diagnosis,
            'planned_procedure' => $s->planned_procedure, 'risks' => $s->risks, 'consent_signed' => (bool) $s->consent_signed,
            'procedure_performed' => $s->procedure_performed, 'anesthesia' => $s->anesthesia,
            'started_at' => optional($s->started_at)->toIso8601String(), 'ended_at' => optional($s->ended_at)->toIso8601String(),
            'findings' => $s->findings, 'complications' => $s->complications, 'post_op_diagnosis' => $s->post_op_diagnosis,
            'post_op_instructions' => $s->post_op_instructions, 'fee' => (float) $s->fee,
            'next' => self::FLOW[$s->status] ?? [],
        ];
    }
}
