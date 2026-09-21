<?php

namespace App\Http\Controllers;

use App\Models\ClassSection;
use App\Models\Teacher;
use App\Models\TimetableSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Teaching staff. A teacher may point at an `employees` row so school staff are
 * the same people HR manages; the link is optional so the module stands alone.
 */
class SchoolTeacherController extends Controller
{
    private const IMAGE_DIR = 'images/teachers';

    private const SORTABLE = ['id', 'name', 'employee_code', 'joining_date', 'is_active', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Teacher::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'name';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Teacher::whereNull('deleted_at');

        if ($request->filled('active')) {
            $query->where('is_active', $request->active === '1' ? 1 : 0);
        }
        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('employee_code', 'LIKE', "%{$search}%")
                    ->orWhere('specialization', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        // Workload in two queries rather than two per row.
        $formClasses = ClassSection::whereNull('deleted_at')
            ->whereIn('teacher_id', $rows->pluck('id'))
            ->select('teacher_id', DB::raw('count(*) as aggregate'))
            ->groupBy('teacher_id')->pluck('aggregate', 'teacher_id')->toArray();
        $periods = TimetableSlot::whereNull('deleted_at')
            ->whereIn('teacher_id', $rows->pluck('id'))
            ->select('teacher_id', DB::raw('count(*) as aggregate'))
            ->groupBy('teacher_id')->pluck('aggregate', 'teacher_id')->toArray();

        return response()->json([
            'totalRows' => $totalRows,
            'teachers' => $rows->map(fn ($t) => $this->present($t) + [
                'form_classes' => (int) ($formClasses[$t->id] ?? 0),
                'weekly_periods' => (int) ($periods[$t->id] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Teacher::class);

        $request->validate($this->rules());

        $data = $this->payload($request);
        $data['image'] = $this->storeImage($request);
        Teacher::create($data);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Teacher::class);

        $teacher = Teacher::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules());

        $data = $this->payload($request);
        $image = $this->storeImage($request);
        if ($image) {
            $this->deleteImage($teacher->image);
            $data['image'] = $image;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($teacher->image);
            $data['image'] = null;
        }

        $teacher->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Teacher::class);

        $teacher = Teacher::whereNull('deleted_at')->findOrFail($id);

        // Detach rather than cascade: a class must not lose its section because
        // its form teacher left.
        ClassSection::where('teacher_id', $teacher->id)->update(['teacher_id' => null]);
        TimetableSlot::where('teacher_id', $teacher->id)->update(['teacher_id' => null]);
        $teacher->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Teacher::class);

        $ids = (array) $request->selectedIds;
        ClassSection::whereIn('teacher_id', $ids)->update(['teacher_id' => null]);
        TimetableSlot::whereIn('teacher_id', $ids)->update(['teacher_id' => null]);
        Teacher::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** One teacher's week, for the workload drawer. */
    public function timetable(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Teacher::class);

        $teacher = Teacher::whereNull('deleted_at')->findOrFail($id);

        $slots = TimetableSlot::with('subject', 'schoolClass', 'section')
            ->whereNull('deleted_at')
            ->where('teacher_id', $teacher->id)
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            ->orderBy('start_time')->get();

        $byDay = [];
        foreach (TimetableSlot::DAYS as $day) {
            $byDay[$day] = $slots->where('day_of_week', $day)->map(fn ($s) => [
                'id' => $s->id,
                'subject_name' => $s->subject ? $s->subject->name : '',
                'class_name' => $s->schoolClass ? $s->schoolClass->name : '',
                'section_name' => $s->section ? $s->section->name : null,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'room' => $s->room,
            ])->values();
        }

        return response()->json(['teacher_name' => $teacher->name, 'days' => $byDay, 'total' => $slots->count()]);
    }

    private function present(Teacher $t)
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'employee_code' => $t->employee_code,
            'employee_id' => $t->employee_id,
            'gender' => $t->gender,
            'phone' => $t->phone,
            'email' => $t->email,
            'qualification' => $t->qualification,
            'specialization' => $t->specialization,
            'joining_date' => optional($t->joining_date)->toDateString(),
            'salary' => $t->salary === null ? null : (float) $t->salary,
            'address' => $t->address,
            'notes' => $t->notes,
            'is_active' => (bool) $t->is_active,
            'image' => $t->image,
            'image_url' => $t->image ? asset(self::IMAGE_DIR . '/' . $t->image) : null,
        ];
    }

    private function rules()
    {
        return [
            'name' => 'required|string|max:191',
            'gender' => 'required|in:male,female,other',
            'email' => 'nullable|email|max:191',
            'employee_id' => 'nullable|exists:employees,id',
            'joining_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|max:5120',
        ];
    }

    private function payload(Request $request)
    {
        return [
            'name' => $request->name,
            'employee_code' => $request->employee_code,
            'employee_id' => $request->employee_id ?: null,
            'gender' => $request->gender,
            'phone' => $request->phone,
            'email' => $request->email,
            'qualification' => $request->qualification,
            'specialization' => $request->specialization,
            'joining_date' => $request->joining_date ?: null,
            'salary' => $request->salary ?: null,
            'address' => $request->address,
            'notes' => $request->notes,
            'is_active' => $request->boolean('is_active', true),
        ];
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
