<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Practitioner register.
 *
 * `availability` is a weekly map ({"mon":["09:00","17:00"]}) used by the
 * appointment booker to warn when a slot falls outside a doctor's clinic hours.
 * It is advisory, never a hard block — hospitals run late and see emergencies.
 */
class HospitalDoctorController extends Controller
{
    private const IMAGE_DIR = 'images/doctors';

    private const SORTABLE = ['id', 'name', 'code', 'specialty', 'consultation_fee', 'is_active', 'created_at'];

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Doctor::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'name';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Doctor::with('department')->whereNull('deleted_at');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('active')) {
            $query->where('is_active', $request->active === '1' ? 1 : 0);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('specialty', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        // Today's booked appointments per doctor, in one query.
        $todayCounts = Appointment::whereNull('deleted_at')
            ->whereIn('doctor_id', $rows->pluck('id'))
            ->whereDate('scheduled_at', Carbon::today()->toDateString())
            ->whereIn('status', Appointment::blockingStatuses())
            ->select('doctor_id', DB::raw('count(*) as aggregate'))
            ->groupBy('doctor_id')->pluck('aggregate', 'doctor_id')->toArray();

        return response()->json([
            'totalRows' => $totalRows,
            'doctors' => $rows->map(fn ($d) => $this->present($d) + [
                'appointments_today' => (int) ($todayCounts[$d->id] ?? 0),
            ])->values(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Doctor::class);

        $doctor = Doctor::with('department')->whereNull('deleted_at')->findOrFail($id);

        return response()->json(['doctor' => $this->present($doctor)]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Doctor::class);

        $request->validate($this->rules());

        $data = $this->payload($request);
        $data['image'] = $this->storeImage($request);
        Doctor::create($data);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Doctor::class);

        $doctor = Doctor::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules());

        $data = $this->payload($request);
        $image = $this->storeImage($request);
        if ($image) {
            $this->deleteImage($doctor->image);
            $data['image'] = $image;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($doctor->image);
            $data['image'] = null;
        }

        $doctor->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Doctor::class);

        Doctor::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Doctor::class);

        $ids = (array) $request->selectedIds;
        Doctor::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** The doctor's diary for one day, used by the appointment booker. */
    public function schedule(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Doctor::class);

        $doctor = Doctor::whereNull('deleted_at')->findOrFail($id);
        $date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $day = strtolower($date->format('D'));
        $hours = $doctor->availability_list[$day] ?? null;

        $appointments = Appointment::with('patient')->whereNull('deleted_at')
            ->where('doctor_id', $doctor->id)
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('status', Appointment::blockingStatuses())
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'scheduled_at' => optional($a->scheduled_at)->toIso8601String(),
                'duration_minutes' => (int) $a->duration_minutes,
                'patient_name' => $a->patient ? $a->patient->name : '',
                'status' => $a->status,
            ])->values();

        return response()->json([
            'date' => $date->toDateString(),
            'working' => is_array($hours) && count($hours) === 2,
            'hours' => $hours,
            'appointments' => $appointments,
        ]);
    }

    private function present(Doctor $doctor)
    {
        return [
            'id' => $doctor->id,
            'name' => $doctor->name,
            'code' => $doctor->code,
            'department_id' => $doctor->department_id,
            'department_name' => $doctor->department ? $doctor->department->name : null,
            'employee_id' => $doctor->employee_id,
            'specialty' => $doctor->specialty,
            'qualification' => $doctor->qualification,
            'license_no' => $doctor->license_no,
            'phone' => $doctor->phone,
            'email' => $doctor->email,
            'consultation_fee' => (float) $doctor->consultation_fee,
            'availability' => $doctor->availability_list,
            'is_active' => (bool) $doctor->is_active,
            'notes' => $doctor->notes,
            'image' => $doctor->image,
            'image_url' => $doctor->image ? asset(self::IMAGE_DIR . '/' . $doctor->image) : null,
        ];
    }

    private function rules()
    {
        return [
            'name' => 'required|string|max:191',
            'department_id' => 'nullable|exists:hospital_departments,id',
            'employee_id' => 'nullable|exists:employees,id',
            'email' => 'nullable|email|max:191',
            'consultation_fee' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|max:5120',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'name' => $request->name,
            'code' => $request->code,
            'department_id' => $request->department_id ?: null,
            'employee_id' => $request->employee_id ?: null,
            'specialty' => $request->specialty,
            'qualification' => $request->qualification,
            'license_no' => $request->license_no,
            'phone' => $request->phone,
            'email' => $request->email,
            'consultation_fee' => $request->consultation_fee ?: 0,
            'availability' => $this->normaliseAvailability($request->input('availability')),
            'is_active' => $request->boolean('is_active', true),
            'notes' => $request->notes,
        ];
    }

    /** Accepts a JSON string or a real map; keeps only well-formed day ranges. */
    private function normaliseAvailability($raw)
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return null;
        }

        $clean = [];
        foreach (self::DAYS as $day) {
            $range = $raw[$day] ?? null;
            if (is_array($range) && count($range) === 2 && $range[0] && $range[1]) {
                $clean[$day] = [(string) $range[0], (string) $range[1]];
            }
        }

        return $clean ? json_encode($clean) : null;
    }

    private function storeImage(Request $request)
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');
        $path = public_path(self::IMAGE_DIR);
        if (! file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $filename = time() . '_' . Str::random(10) . '.' . $extension;
        $file->move($path, $filename);

        return $filename;
    }

    private function deleteImage($filename)
    {
        if (! $filename) {
            return;
        }
        $path = public_path(self::IMAGE_DIR . '/' . $filename);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
