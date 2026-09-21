<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\PatientVisit;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OPD consultations, and the prescription written at each one.
 *
 * A visit saves as one transaction with its prescription lines: a consultation
 * recorded without the drugs the doctor just prescribed is a clinical hazard,
 * not a partial save worth keeping.
 *
 * Completing a visit that came from an appointment marks that appointment
 * completed, so the diary reflects what happened without a second click.
 */
class HospitalVisitController extends Controller
{
    private const SORTABLE = ['id', 'reference', 'visit_date', 'type', 'status', 'fee', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PatientVisit::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'visit_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = PatientVisit::with('patient', 'doctor', 'department')->whereNull('deleted_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('visit_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('visit_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('diagnosis', 'LIKE', "%{$search}%")
                    ->orWhere('complaint', 'LIKE', "%{$search}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('mrn', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'visits' => $rows->map(fn ($v) => $this->present($v))->values(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', PatientVisit::class);

        $visit = PatientVisit::with('patient', 'doctor', 'department', 'prescriptions.items')
            ->whereNull('deleted_at')->findOrFail($id);

        $data = $this->present($visit);
        $data += [
            'complaint' => $visit->complaint,
            'examination' => $visit->examination,
            'treatment_plan' => $visit->treatment_plan,
            'follow_up_date' => optional($visit->follow_up_date)->toDateString(),
            'appointment_id' => $visit->appointment_id,
            'vitals' => $this->vitals($visit),
            'prescription' => $this->presentPrescription($visit->prescriptions->first()),
        ];

        return response()->json(['visit' => $data]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PatientVisit::class);

        $request->validate($this->rules());

        $userId = optional($request->user('api'))->id;

        $visit = DB::transaction(function () use ($request, $userId) {
            $data = $this->payload($request);
            $data['reference'] = PatientVisit::nextReference('VIS');
            $data['created_by'] = $userId;

            if (! $request->filled('fee') && $request->filled('doctor_id')) {
                $doctor = Doctor::find($request->doctor_id);
                $data['fee'] = $doctor ? (float) $doctor->consultation_fee : 0;
            }

            $visit = PatientVisit::create($data);
            $this->syncPrescription($request, $visit, $userId);

            // The diary should reflect that the patient was actually seen.
            if ($visit->appointment_id && $visit->status === 'completed') {
                Appointment::where('id', $visit->appointment_id)
                    ->whereIn('status', Appointment::blockingStatuses())
                    ->update(['status' => 'completed']);
            }

            return $visit;
        });

        return response()->json(['success' => true, 'id' => $visit->id, 'reference' => $visit->reference]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PatientVisit::class);

        $visit = PatientVisit::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules());

        $userId = optional($request->user('api'))->id;

        DB::transaction(function () use ($request, $visit, $userId) {
            $visit->update($this->payload($request));
            $this->syncPrescription($request, $visit, $userId);

            if ($visit->appointment_id && $visit->status === 'completed') {
                Appointment::where('id', $visit->appointment_id)
                    ->whereIn('status', Appointment::blockingStatuses())
                    ->update(['status' => 'completed']);
            }
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PatientVisit::class);

        PatientVisit::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PatientVisit::class);

        $ids = (array) $request->selectedIds;
        PatientVisit::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** Prefill a consultation from a booked appointment. */
    public function fromAppointment(Request $request, $appointmentId)
    {
        $this->authorizeForUser($request->user('api'), 'create', PatientVisit::class);

        $appointment = Appointment::with('patient', 'doctor')->whereNull('deleted_at')->findOrFail($appointmentId);

        return response()->json(['prefill' => [
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'patient_name' => $appointment->patient ? $appointment->patient->name : '',
            'doctor_id' => $appointment->doctor_id,
            'department_id' => $appointment->department_id,
            'visit_date' => optional($appointment->scheduled_at)->toDateTimeString(),
            'complaint' => $appointment->reason,
            'fee' => (float) $appointment->fee,
            'type' => $appointment->type === 'follow_up' ? 'follow_up' : 'opd',
        ]]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Replace the visit's prescription with what was submitted. Rewriting the
     * lines wholesale (rather than diffing) is right here: a doctor amending a
     * prescription means "this is now the prescription", not "add one drug".
     */
    private function syncPrescription(Request $request, PatientVisit $visit, $userId)
    {
        $items = $request->input('prescription_items');
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        $items = is_array($items) ? array_values(array_filter($items, fn ($i) => ! empty($i['medicine']))) : [];

        $prescription = Prescription::where('visit_id', $visit->id)->whereNull('deleted_at')->first();

        if (! $items) {
            if ($prescription) {
                PrescriptionItem::where('prescription_id', $prescription->id)->delete();
                $prescription->delete();
            }

            return;
        }

        if (! $prescription) {
            $prescription = Prescription::create([
                'reference' => Prescription::nextReference('RX'),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'doctor_id' => $visit->doctor_id,
                'prescribed_on' => $visit->visit_date ? $visit->visit_date->toDateString() : now()->toDateString(),
                'notes' => $request->prescription_notes,
                'created_by' => $userId,
            ]);
        } else {
            $prescription->update([
                'doctor_id' => $visit->doctor_id,
                'notes' => $request->prescription_notes,
            ]);
            PrescriptionItem::where('prescription_id', $prescription->id)->delete();
        }

        foreach ($items as $item) {
            PrescriptionItem::create([
                'prescription_id' => $prescription->id,
                'product_id' => $item['product_id'] ?? null,
                'medicine' => $item['medicine'],
                'dosage' => $item['dosage'] ?? null,
                'frequency' => $item['frequency'] ?? null,
                'duration' => $item['duration'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'instructions' => $item['instructions'] ?? null,
            ]);
        }
    }

    private function vitals(PatientVisit $visit)
    {
        return [
            'temperature' => $visit->temperature === null ? null : (float) $visit->temperature,
            'pulse' => $visit->pulse,
            'bp_systolic' => $visit->bp_systolic,
            'bp_diastolic' => $visit->bp_diastolic,
            'blood_pressure' => $visit->blood_pressure,
            'respiratory_rate' => $visit->respiratory_rate,
            'spo2' => $visit->spo2,
            'weight' => $visit->weight === null ? null : (float) $visit->weight,
            'height' => $visit->height === null ? null : (float) $visit->height,
            'bmi' => $visit->bmi,
        ];
    }

    private function presentPrescription($prescription)
    {
        if (! $prescription) {
            return null;
        }

        return [
            'id' => $prescription->id,
            'reference' => $prescription->reference,
            'prescribed_on' => optional($prescription->prescribed_on)->toDateString(),
            'notes' => $prescription->notes,
            'items' => $prescription->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'medicine' => $i->medicine,
                'dosage' => $i->dosage,
                'frequency' => $i->frequency,
                'duration' => $i->duration,
                'quantity' => (float) $i->quantity,
                'instructions' => $i->instructions,
            ])->values(),
        ];
    }

    private function present(PatientVisit $v)
    {
        return [
            'id' => $v->id,
            'reference' => $v->reference,
            'patient_id' => $v->patient_id,
            'patient_name' => $v->patient ? $v->patient->name : '',
            'patient_mrn' => $v->patient ? $v->patient->mrn : '',
            'doctor_id' => $v->doctor_id,
            'doctor_name' => $v->doctor ? $v->doctor->name : null,
            'department_id' => $v->department_id,
            'department_name' => $v->department ? $v->department->name : null,
            'visit_date' => optional($v->visit_date)->toIso8601String(),
            'type' => $v->type,
            'status' => $v->status,
            'diagnosis' => $v->diagnosis,
            'blood_pressure' => $v->blood_pressure,
            'bmi' => $v->bmi,
            'follow_up_date' => optional($v->follow_up_date)->toDateString(),
            'fee' => (float) $v->fee,
        ];
    }

    private function rules()
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'department_id' => 'nullable|exists:hospital_departments,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'visit_date' => 'required|date',
            'type' => 'required|in:opd,emergency,follow_up,teleconsult',
            'status' => 'required|in:open,completed',
            'temperature' => 'nullable|numeric|between:20,50',
            'pulse' => 'nullable|integer|between:0,300',
            'bp_systolic' => 'nullable|integer|between:0,300',
            'bp_diastolic' => 'nullable|integer|between:0,200',
            'respiratory_rate' => 'nullable|integer|between:0,120',
            'spo2' => 'nullable|integer|between:0,100',
            'weight' => 'nullable|numeric|between:0,600',
            'height' => 'nullable|numeric|between:0,300',
            'fee' => 'nullable|numeric|min:0',
            'follow_up_date' => 'nullable|date',
        ];
    }

    private function payload(Request $request)
    {
        $nullable = fn ($value) => ($value === '' || $value === null) ? null : $value;

        return [
            'patient_id' => $request->patient_id,
            'doctor_id' => $request->doctor_id ?: null,
            'department_id' => $request->department_id ?: null,
            'appointment_id' => $request->appointment_id ?: null,
            'visit_date' => $request->visit_date,
            'type' => $request->type,
            'temperature' => $nullable($request->temperature),
            'pulse' => $nullable($request->pulse),
            'bp_systolic' => $nullable($request->bp_systolic),
            'bp_diastolic' => $nullable($request->bp_diastolic),
            'respiratory_rate' => $nullable($request->respiratory_rate),
            'spo2' => $nullable($request->spo2),
            'weight' => $nullable($request->weight),
            'height' => $nullable($request->height),
            'complaint' => $request->complaint,
            'examination' => $request->examination,
            'diagnosis' => $request->diagnosis,
            'treatment_plan' => $request->treatment_plan,
            'follow_up_date' => $request->follow_up_date ?: null,
            'status' => $request->status,
            'fee' => $request->filled('fee') ? $request->fee : 0,
        ];
    }
}
