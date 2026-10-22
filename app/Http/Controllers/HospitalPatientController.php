<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\HospitalInvoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\Prescription;
use App\Support\CompanyFiles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Patient register — the module's central entity.
 *
 * Photos go to public/images/patients (the convention the rest of the app's
 * uploads follow); the column stores the file name only and rows carry a ready
 * `image_url`.
 *
 * List endpoints follow the admin's usual contract
 * (page/SortField/SortType/search/limit -> { patients, totalRows }) so the Vue
 * `useCrudTable` composable drives them unchanged.
 */
class HospitalPatientController extends Controller
{
    use ScopesToCompany;

    private const SORTABLE = ['id', 'mrn', 'name', 'gender', 'date_of_birth', 'phone', 'status', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Patient::class);

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $companyId = $this->listCompanyId($request);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Patient::whereNull('deleted_at')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }
        if ($request->filled('blood_group')) {
            $query->where('blood_group', $request->blood_group);
        }
        // Patients currently occupying a bed — the ward round's worklist.
        if ($request->boolean('admitted')) {
            $query->whereIn('id', Admission::whereNull('deleted_at')->where('status', 'admitted')->pluck('patient_id'));
        }
        // In the hospital now (2026-10-21) = an open admission or an open ER
        // case; "out" = everyone else. The two big buttons on Patients.
        $insideIds = $this->insideIds();
        if ($request->get('presence') === 'in') {
            $query->whereIn('id', $insideIds);
        } elseif ($request->get('presence') === 'out') {
            $query->whereNotIn('id', $insideIds);
        }
        $base = Patient::whereNull('deleted_at')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));
        $presenceCounts = [
            'in' => (clone $base)->whereIn('id', $insideIds)->count(),
            'out' => (clone $base)->whereNotIn('id', $insideIds)->count(),
        ];
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('mrn', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('national_id', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $patients = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        // One query for "who is currently admitted", instead of one per row.
        $admittedIds = Admission::whereNull('deleted_at')
            ->where('status', 'admitted')
            ->whereIn('patient_id', $patients->pluck('id'))
            ->pluck('patient_id')
            ->all();

        // superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is.
        $companyNames = \App\Models\Company::whereIn('id', $patients->pluck('company_id')->unique())->pluck('name', 'id');

        $erIds = \Illuminate\Support\Facades\Schema::hasTable('er_cases')
            ? \App\Models\ErCase::whereNull('deleted_at')->where('status', '!=', 'closed')->whereIn('patient_id', $patients->pluck('id'))->pluck('patient_id')->all()
            : [];

        return response()->json([
            'totalRows' => $totalRows,
            'presence_counts' => $presenceCounts,
            'patients' => $patients->map(fn ($p) => $this->present($p, in_array($p->id, $admittedIds)) + [
                'in_er' => in_array($p->id, $erIds),
                'company_name' => $p->company_id == \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem' : ($companyNames[$p->company_id] ?? null),
            ])->values(),
        ]);
    }

    /** Patients in the hospital now: an open admission or an open Emergency Room case. */
    private function insideIds(): array
    {
        $ids = Admission::whereNull('deleted_at')->where('status', 'admitted')->pluck('patient_id')->all();
        if (\Illuminate\Support\Facades\Schema::hasTable('er_cases')) {
            $ids = array_merge($ids, \App\Models\ErCase::whereNull('deleted_at')->where('status', '!=', 'closed')->pluck('patient_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids))) ?: [0];
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Patient::class);

        $companyId = $this->resolveActingCompanyId($request);
        $request->validate($this->rules());

        $data = $this->payload($request);
        $data['company_id'] = $companyId;
        $data['mrn'] = $request->filled('mrn') ? $request->mrn : Patient::generateMrn();
        $data['image'] = $this->storeImage($request, $companyId);

        $patient = Patient::create($data);

        return response()->json(['success' => true, 'id' => $patient->id, 'mrn' => $patient->mrn]);
    }

    /** The clinical summary behind a patient's detail page. */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Patient::class);

        $patient = Patient::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $patient->company_id === $this->resolveActingCompanyId($request), 404);

        $data = $this->present($patient);
        $data += [
            'address' => $patient->address,
            'city' => $patient->city,
            'national_id' => $patient->national_id,
            'chronic_conditions' => $patient->chronic_conditions,
            'emergency_contact_name' => $patient->emergency_contact_name,
            'emergency_contact_phone' => $patient->emergency_contact_phone,
            'emergency_contact_relation' => $patient->emergency_contact_relation,
            'insurance_provider' => $patient->insurance_provider,
            'insurance_number' => $patient->insurance_number,
            'insurance_expiry' => optional($patient->insurance_expiry)->toDateString(),
            'notes' => $patient->notes,
        ];

        $invoiceTotals = HospitalInvoice::whereNull('deleted_at')
            ->where('patient_id', $patient->id)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(total),0) as billed, COALESCE(SUM(paid),0) as paid')
            ->first();

        $current = Admission::with('ward', 'bed', 'doctor')->whereNull('deleted_at')
            ->where('patient_id', $patient->id)->where('status', 'admitted')
            ->orderBy('admitted_at', 'desc')->first();

        $data['stats'] = [
            'visits' => PatientVisit::whereNull('deleted_at')->where('patient_id', $patient->id)->count(),
            'appointments' => Appointment::whereNull('deleted_at')->where('patient_id', $patient->id)->count(),
            'admissions' => Admission::whereNull('deleted_at')->where('patient_id', $patient->id)->count(),
            'lab_orders' => LabOrder::whereNull('deleted_at')->where('patient_id', $patient->id)->count(),
            'billed' => round((float) ($invoiceTotals->billed ?? 0), 2),
            'paid' => round((float) ($invoiceTotals->paid ?? 0), 2),
            'due' => round((float) ($invoiceTotals->billed ?? 0) - (float) ($invoiceTotals->paid ?? 0), 2),
            'last_visit' => optional(PatientVisit::whereNull('deleted_at')->where('patient_id', $patient->id)
                ->orderBy('visit_date', 'desc')->first())->visit_date?->toIso8601String(),
        ];

        $data['current_admission'] = $current ? [
            'id' => $current->id,
            'reference' => $current->reference,
            'ward_name' => $current->ward ? $current->ward->name : null,
            'bed_number' => $current->bed ? $current->bed->bed_number : null,
            'doctor_name' => $current->doctor ? $current->doctor->name : null,
            'admitted_at' => optional($current->admitted_at)->toIso8601String(),
            'nights' => $current->nights,
        ] : null;

        return response()->json(['patient' => $data]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Patient::class);

        $patient = Patient::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $patient->company_id === $this->resolveActingCompanyId($request), 404);

        return response()->json(['patient' => array_merge($patient->toArray(), [
            'image_url' => $patient->image ? asset($patient->image) : null,
            'date_of_birth' => optional($patient->date_of_birth)->toDateString(),
            'insurance_expiry' => optional($patient->insurance_expiry)->toDateString(),
        ])]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Patient::class);

        $companyId = $this->resolveActingCompanyId($request);
        $patient = Patient::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $patient->company_id === $companyId, 404);

        $request->validate($this->rules($patient->id));

        $data = $this->payload($request);
        if ($request->filled('mrn')) {
            $data['mrn'] = $request->mrn;
        }

        $image = $this->storeImage($request, $companyId);
        if ($image) {
            $this->deleteImage($patient->image);
            $data['image'] = $image;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($patient->image);
            $data['image'] = null;
        }

        $patient->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Patient::class);

        $patient = Patient::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $patient->company_id === $this->resolveActingCompanyId($request), 404);
        $patient->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Patient::class);

        $ids = (array) $request->selectedIds;
        Patient::whereIn('id', $ids)->where('company_id', $this->resolveActingCompanyId($request))->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** Everything filed under one patient, for the detail page's tabs. */
    public function timeline(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Patient::class);

        $patient = Patient::whereNull('deleted_at')->findOrFail($id);
        abort_unless((int) $patient->company_id === $this->resolveActingCompanyId($request), 404);
        $limit = (int) ($request->limit ?? 50);

        return response()->json([
            'visits' => PatientVisit::with('doctor')->whereNull('deleted_at')
                ->where('patient_id', $patient->id)->orderBy('visit_date', 'desc')->limit($limit)->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'reference' => $v->reference,
                    'visit_date' => optional($v->visit_date)->toIso8601String(),
                    'type' => $v->type,
                    'doctor_name' => $v->doctor ? $v->doctor->name : null,
                    'diagnosis' => $v->diagnosis,
                    'blood_pressure' => $v->blood_pressure,
                    'status' => $v->status,
                    'fee' => (float) $v->fee,
                ])->values(),
            'appointments' => Appointment::with('doctor')->whereNull('deleted_at')
                ->where('patient_id', $patient->id)->orderBy('scheduled_at', 'desc')->limit($limit)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'reference' => $a->reference,
                    'scheduled_at' => optional($a->scheduled_at)->toIso8601String(),
                    'doctor_name' => $a->doctor ? $a->doctor->name : null,
                    'type' => $a->type,
                    'status' => $a->status,
                ])->values(),
            'admissions' => Admission::with('ward', 'bed', 'doctor')->whereNull('deleted_at')
                ->where('patient_id', $patient->id)->orderBy('admitted_at', 'desc')->limit($limit)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'reference' => $a->reference,
                    'admitted_at' => optional($a->admitted_at)->toIso8601String(),
                    'discharged_at' => optional($a->discharged_at)->toIso8601String(),
                    'ward_name' => $a->ward ? $a->ward->name : null,
                    'bed_number' => $a->bed ? $a->bed->bed_number : null,
                    'doctor_name' => $a->doctor ? $a->doctor->name : null,
                    'nights' => $a->nights,
                    'status' => $a->status,
                ])->values(),
            'lab_orders' => LabOrder::with('items')->whereNull('deleted_at')
                ->where('patient_id', $patient->id)->orderBy('ordered_at', 'desc')->limit($limit)->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'reference' => $o->reference,
                    'ordered_at' => optional($o->ordered_at)->toIso8601String(),
                    'status' => $o->status,
                    'priority' => $o->priority,
                    'tests' => $o->items->pluck('test_name')->all(),
                    'total' => (float) $o->total,
                ])->values(),
            'prescriptions' => Prescription::with('items', 'doctor')->whereNull('deleted_at')
                ->where('patient_id', $patient->id)->orderBy('prescribed_on', 'desc')->limit($limit)->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'reference' => $p->reference,
                    'prescribed_on' => optional($p->prescribed_on)->toDateString(),
                    'doctor_name' => $p->doctor ? $p->doctor->name : null,
                    'items' => $p->items->map(fn ($i) => [
                        'medicine' => $i->medicine,
                        'dosage' => $i->dosage,
                        'frequency' => $i->frequency,
                        'duration' => $i->duration,
                        'instructions' => $i->instructions,
                    ])->values(),
                ])->values(),
            'invoices' => HospitalInvoice::whereNull('deleted_at')
                ->where('patient_id', $patient->id)->orderBy('invoice_date', 'desc')->limit($limit)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'reference' => $i->reference,
                    'invoice_date' => optional($i->invoice_date)->toDateString(),
                    'total' => (float) $i->total,
                    'paid' => (float) $i->paid,
                    'due' => $i->due,
                    'status' => $i->status,
                ])->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function present(Patient $patient, $isAdmitted = null)
    {
        return [
            'id' => $patient->id,
            'mrn' => $patient->mrn,
            'name' => $patient->name,
            'gender' => $patient->gender,
            'date_of_birth' => optional($patient->date_of_birth)->toDateString(),
            'age' => $patient->age,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'blood_group' => $patient->blood_group,
            'allergies' => $patient->allergies,
            'status' => $patient->status,
            'client_id' => $patient->client_id,
            'image' => $patient->image,
            'image_url' => $patient->image ? asset($patient->image) : null,
            'is_admitted' => $isAdmitted === null ? null : (bool) $isAdmitted,
            'created_at' => optional($patient->created_at)->toIso8601String(),
        ];
    }

    private function rules($ignoreId = null)
    {
        return [
            'name' => 'required|string|max:191',
            'gender' => 'required|in:male,female,other',
            'status' => 'required|in:active,inactive,deceased',
            'mrn' => 'nullable|string|max:32|unique:patients,mrn' . ($ignoreId ? ',' . $ignoreId : ''),
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:191',
            'blood_group' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'client_id' => 'nullable|exists:clients,id',
            'insurance_expiry' => 'nullable|date',
            'image' => 'nullable|image|max:5120',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'name' => $request->name,
            'gender' => $request->gender,
            'date_of_birth' => $request->date_of_birth ?: null,
            'phone' => $request->phone,
            'email' => $request->email,
            'address' => $request->address,
            'city' => $request->city,
            'national_id' => $request->national_id,
            'blood_group' => $request->blood_group ?: null,
            'allergies' => $request->allergies,
            'chronic_conditions' => $request->chronic_conditions,
            'emergency_contact_name' => $request->emergency_contact_name,
            'emergency_contact_phone' => $request->emergency_contact_phone,
            'emergency_contact_relation' => $request->emergency_contact_relation,
            'insurance_provider' => $request->insurance_provider,
            'insurance_number' => $request->insurance_number,
            'insurance_expiry' => $request->insurance_expiry ?: null,
            'client_id' => $request->client_id ?: null,
            'notes' => $request->notes,
            'status' => $request->status,
        ];
    }

    /** Returns the stored image's public-relative path, or null when no photo was sent. */
    private function storeImage(Request $request, int $companyId)
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');
        $rel = CompanyFiles::dir($companyId, 'patients');
        $path = public_path($rel);

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $filename = time() . '_' . Str::random(10) . '.' . $extension;
        $file->move($path, $filename);

        return $rel . '/' . $filename;
    }

    /** $relPath is the public-relative path stored in the `image` column. */
    private function deleteImage(?string $relPath)
    {
        if (! $relPath) {
            return;
        }
        $path = public_path($relPath);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
