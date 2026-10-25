<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ErCase;
use App\Models\HmsFloor;
use App\Models\HmsRoom;
use App\Models\HmsShiftType;
use App\Models\HospitalBed;
use App\Models\HospitalWard;
use App\Support\HospitalLayout;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Hospital -> Configuration (user's analysis, 2026-10-25): everything set up
 * once -- the shifts with their hours, the floors, their rooms / offices / ER
 * and beds, and a map per floor (a grid where rooms are rectangles and beds
 * small squares) used by the admission desk and the bed board. Permission
 * hms_setup; the read-only map (bedMap) also for the desk / wards / ER /
 * admissions; marking a bed clean for wards / desk.
 *
 *  GET  hospital/setup                         shifts, floors (+ rooms, beds), wards, unplaced beds
 *  PUT  hospital/setup/shifts {shifts: [...]}  replace the company's shifts (used codes are deactivated, never deleted)
 *  POST/PUT/DELETE hospital/setup/floors[/{id}]
 *  POST/PUT/DELETE hospital/setup/rooms[/{id}]
 *  POST hospital/setup/rooms/{id}/beds {bed_number: "1-4"}   beds in that room (its ward)
 *  PUT  hospital/setup/floors/{id}/map {rooms: [{id,x,y,w,h}], beds: [{id,x,y,room_id}]}
 *  GET  hospital/bed-map?floor_id=             floors with rooms and beds + status + patient (read only)
 *  POST hospital/beds/{id}/clean               cleaning -> available
 *  PUT  hospital/setup/visit-hours; POST/PUT/DELETE hospital/setup/care-videos[/{id}]   patient portal (phase 4)
 */
class HospitalSetupController extends Controller
{
    private function canSetup(Request $request): void
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_setup'), 403, 'You do not have permission for the hospital configuration.');
    }

    public function index(Request $request)
    {
        $this->canSetup($request);

        return response()->json([
            'shifts' => HospitalShifts::types()->values(),
            'floors' => $this->floors(false),
            'wards' => HospitalWard::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'type', 'floor_id']),
            'unplaced_beds' => HospitalBed::with('ward')->whereNull('deleted_at')->whereNull('room_id')->orderBy('ward_id')->orderBy('bed_number')->get()
                ->map(fn ($b) => ['id' => $b->id, 'bed_number' => $b->bed_number, 'ward_id' => $b->ward_id, 'ward_name' => optional($b->ward)->name, 'status' => $b->status])->values(),
            'kinds' => HmsRoom::KINDS,
            'visit_hours' => DB::table('settings')->where('company_id', HmsShiftType::actingCompanyId())->whereNull('deleted_at')->value('hms_visit_hours'),
            'videos' => DB::table('hms_care_videos')->where('company_id', HmsShiftType::actingCompanyId())->orderBy('sort_order')->orderBy('title')->get(),
        ]);
    }

    // ------------------------------------------------------------------ patient portal (phase 4)

    /** Visit hours shown in the patient portal and on the visitor pass desk. */
    public function saveVisitHours(Request $request)
    {
        $this->canSetup($request);
        $request->validate(['visit_hours' => 'nullable|string|max:500']);
        $cid = (int) HmsShiftType::actingCompanyId();
        abort_unless($cid, 422, 'Pick the company first.');
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['hms_visit_hours' => strip_tags((string) $request->visit_hours) ?: null]);

        return response()->json(['success' => true]);
    }

    /** Home-care videos (a link to YouTube / Vimeo / any page), picked at discharge. */
    public function saveVideo(Request $request, $id = null)
    {
        $this->canSetup($request);
        $v = $request->validate(['title' => 'required|string|max:150', 'url' => ['required', 'url:http,https', 'max:500'],
            'topic' => 'nullable|string|max:80', 'is_active' => 'boolean']);
        $cid = (int) HmsShiftType::actingCompanyId();
        abort_unless($cid, 422, 'Pick the company first.');
        $row = ['title' => strip_tags($v['title']), 'url' => $v['url'], 'topic' => isset($v['topic']) ? strip_tags($v['topic']) : null,
            'is_active' => (bool) ($v['is_active'] ?? true), 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('hms_care_videos')->where('id', $id)->where('company_id', $cid)->exists(), 404);
            DB::table('hms_care_videos')->where('id', $id)->update($row);
        } else {
            DB::table('hms_care_videos')->insert($row + ['company_id' => $cid, 'sort_order' => (int) DB::table('hms_care_videos')->where('company_id', $cid)->max('sort_order') + 1, 'created_at' => now()]);
        }

        return response()->json(['videos' => DB::table('hms_care_videos')->where('company_id', $cid)->orderBy('sort_order')->orderBy('title')->get()]);
    }

    public function destroyVideo(Request $request, $id)
    {
        $this->canSetup($request);
        $cid = (int) HmsShiftType::actingCompanyId();
        abort_unless(DB::table('hms_care_videos')->where('id', $id)->where('company_id', $cid)->delete(), 404);

        return response()->json(['videos' => DB::table('hms_care_videos')->where('company_id', $cid)->orderBy('sort_order')->orderBy('title')->get()]);
    }

    // ------------------------------------------------------------------ shifts

    public function saveShifts(Request $request)
    {
        $this->canSetup($request);
        $request->validate([
            'shifts' => 'required|array|min:1|max:12',
            'shifts.*.name' => 'required|string|max:80',
            'shifts.*.start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'shifts.*.hours' => 'required|integer|min:1|max:24',
            'shifts.*.color' => 'nullable|string|max:16',
            'shifts.*.active' => 'boolean',
            'shifts.*.code' => 'nullable|string|max:32',
        ]);
        $cid = (int) HmsShiftType::actingCompanyId();
        abort_unless($cid, 422, 'Pick the company first.');
        HospitalShifts::types($cid); // the standard ones exist

        DB::transaction(function () use ($request, $cid) {
            $keep = [];
            foreach (array_values($request->input('shifts')) as $i => $s) {
                $code = preg_match('/^[a-z0-9_]{1,32}$/', (string) ($s['code'] ?? '')) ? $s['code']
                    : Str::limit(Str::slug($s['name'], '_'), 24, '').'_'.Str::lower(Str::random(4));
                HmsShiftType::withoutGlobalScope('company')->updateOrCreate(['company_id' => $cid, 'code' => $code], [
                    'name' => strip_tags($s['name']), 'starts_at' => $s['start'], 'hours' => (int) $s['hours'],
                    'color' => preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($s['color'] ?? '')) ? $s['color'] : null,
                    'sort_order' => $i + 1, 'is_active' => (bool) ($s['active'] ?? true),
                ]);
                $keep[] = $code;
            }
            // A shift left out: deleted when nobody was ever rostered on it, else just deactivated (history keeps its name).
            foreach (HmsShiftType::withoutGlobalScope('company')->where('company_id', $cid)->whereNotIn('code', $keep)->get() as $old) {
                $used = DB::table('hms_staff_shifts')->where('company_id', $cid)->where('shift', $old->code)->exists();
                $used ? $old->update(['is_active' => false]) : $old->delete();
            }
        });
        HospitalShifts::forget();

        return response()->json(['shifts' => HospitalShifts::types($cid)->values()]);
    }

    // ------------------------------------------------------------------ floors

    public function storeFloor(Request $request)
    {
        $this->canSetup($request);
        $v = $this->floorRules($request);
        $f = HmsFloor::create($v + ['sort_order' => (int) ($v['level'] ?? 0) + 10]);

        return response()->json(['id' => $f->id, 'floors' => $this->floors(false)]);
    }

    public function updateFloor(Request $request, $id)
    {
        $this->canSetup($request);
        $f = HmsFloor::whereNull('deleted_at')->findOrFail($id);
        $v = $this->floorRules($request);
        $f->update($v + ['sort_order' => (int) ($v['level'] ?? 0) + 10]);

        return response()->json(['floors' => $this->floors(false)]);
    }

    public function destroyFloor(Request $request, $id)
    {
        $this->canSetup($request);
        $f = HmsFloor::whereNull('deleted_at')->findOrFail($id);
        $roomIds = HmsRoom::where('floor_id', $f->id)->pluck('id');
        if (HospitalBed::whereIn('room_id', $roomIds)->where('status', 'occupied')->exists()) {
            return response()->json(['message' => 'A bed on this floor has a patient. Discharge or transfer first.'], 422);
        }
        DB::transaction(function () use ($f, $roomIds) {
            HospitalBed::whereIn('room_id', $roomIds)->update(['room_id' => null, 'map_x' => null, 'map_y' => null]);
            HmsRoom::whereIn('id', $roomIds)->delete();
            HospitalWard::where('floor_id', $f->id)->update(['floor_id' => null]);
            $f->delete();
        });

        return response()->json(['floors' => $this->floors(false)]);
    }

    private function floorRules(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'level' => 'nullable|integer|min:-10|max:200',
            'map_cols' => 'nullable|integer|min:6|max:80',
            'map_rows' => 'nullable|integer|min:4|max:60',
            'notes' => 'nullable|string|max:255',
        ]) + ['map_cols' => 24, 'map_rows' => 14];
    }

    // ------------------------------------------------------------------ rooms

    public function storeRoom(Request $request)
    {
        $this->canSetup($request);
        $v = $this->roomRules($request);
        // A new room is dropped at a free spot of the map (top-left first), 4 x 3 cells.
        $floor = HmsFloor::whereNull('deleted_at')->findOrFail($v['floor_id']);
        [$x, $y] = HospitalLayout::freeSpot($floor, (int) ($v['map_w'] ?? 4), (int) ($v['map_h'] ?? 3));
        $r = HmsRoom::create($v + ['map_x' => $x, 'map_y' => $y, 'map_w' => $v['map_w'] ?? 4, 'map_h' => $v['map_h'] ?? 3]);

        return response()->json(['id' => $r->id, 'floors' => $this->floors(false)]);
    }

    public function updateRoom(Request $request, $id)
    {
        $this->canSetup($request);
        $r = HmsRoom::whereNull('deleted_at')->findOrFail($id);
        $r->update($this->roomRules($request));
        // The room's beds follow its ward.
        if ($r->ward_id) {
            HospitalBed::where('room_id', $r->id)->where('status', '!=', 'occupied')->update(['ward_id' => $r->ward_id]);
        }

        return response()->json(['floors' => $this->floors(false)]);
    }

    public function destroyRoom(Request $request, $id)
    {
        $this->canSetup($request);
        $r = HmsRoom::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(function () use ($r) {
            HospitalBed::where('room_id', $r->id)->update(['room_id' => null, 'map_x' => null, 'map_y' => null]);
            $r->delete();
        });

        return response()->json(['floors' => $this->floors(false)]);
    }

    /** Beds in a patient room (its ward): "4", "1-4" or "A,B". */
    public function storeBeds(Request $request, $id)
    {
        $this->canSetup($request);
        $r = HmsRoom::whereNull('deleted_at')->findOrFail($id);
        $request->validate(['bed_number' => 'required|string|max:120']);
        if (! $r->ward_id) {
            return response()->json(['message' => 'Pick the ward this room belongs to first (beds are billed by the ward rate).'], 422);
        }
        $numbers = $this->expand($request->bed_number);
        $made = 0;
        foreach ($numbers as $i => $n) {
            if (HospitalBed::whereNull('deleted_at')->where('ward_id', $r->ward_id)->where('bed_number', $n)->exists()) {
                continue;
            }
            // Beds line up inside the room, left to right, top to bottom.
            $slot = HospitalBed::where('room_id', $r->id)->count();
            $perRow = max(1, (int) $r->map_w);
            HospitalBed::create(['ward_id' => $r->ward_id, 'room_id' => $r->id, 'bed_number' => $n, 'status' => 'available',
                'map_x' => $r->map_x !== null ? $r->map_x + ($slot % $perRow) : null,
                'map_y' => $r->map_y !== null ? $r->map_y + intdiv($slot, $perRow) : null]);
            $made++;
        }
        if (! $made) {
            return response()->json(['message' => 'Those bed numbers already exist in this ward.'], 422);
        }

        return response()->json(['count' => $made, 'floors' => $this->floors(false)]);
    }

    private function roomRules(Request $request): array
    {
        return $request->validate([
            'floor_id' => ['required', \App\Support\CompanyExists::rule('hms_floors')],
            'ward_id' => ['nullable', \App\Support\CompanyExists::rule('hospital_wards')],
            'name' => 'required|string|max:80',
            'kind' => ['required', Rule::in(HmsRoom::KINDS)],
            'color' => 'nullable|string|max:16',
            'notes' => 'nullable|string|max:255',
            'map_w' => 'nullable|integer|min:1|max:40',
            'map_h' => 'nullable|integer|min:1|max:40',
        ]);
    }

    /**
     * Start a floor's map from its wards: one room per ward of this floor (ER
     * wards as "er"), sized to its beds that are not on a map yet, laid out
     * left to right, the beds lined up inside; on the ground floor also a
     * reception and a nursing station. Existing rooms are kept.
     */
    public function autoLayout(Request $request, $id)
    {
        $this->canSetup($request);
        $floor = HmsFloor::whereNull('deleted_at')->findOrFail($id);
        $wards = HospitalWard::whereNull('deleted_at')->where('floor_id', $floor->id)->orderBy('name')->get();
        if ($wards->isEmpty()) {
            return response()->json(['message' => 'No ward is on this floor. Set the floor of the wards first (Wards & beds -> edit ward).'], 422);
        }
        $es = DB::table('settings')->where('company_id', $floor->company_id)->whereNull('deleted_at')->value('default_language') === 'es';
        $made = HospitalLayout::build($floor, $es);

        return response()->json(['rooms' => $made, 'floors' => $this->floors(false)]);
    }

    // ------------------------------------------------------------------ map

    /** Save a floor's layout in one go (what the map editor shows). */
    public function saveMap(Request $request, $id)
    {
        $this->canSetup($request);
        $floor = HmsFloor::whereNull('deleted_at')->findOrFail($id);
        $request->validate([
            'map_cols' => 'nullable|integer|min:6|max:80', 'map_rows' => 'nullable|integer|min:4|max:60',
            'rooms' => 'array', 'rooms.*.id' => 'required|integer',
            'rooms.*.x' => 'required|integer|min:0', 'rooms.*.y' => 'required|integer|min:0',
            'rooms.*.w' => 'required|integer|min:1', 'rooms.*.h' => 'required|integer|min:1',
            'beds' => 'array', 'beds.*.id' => 'required|integer',
            'beds.*.x' => 'nullable|integer|min:0', 'beds.*.y' => 'nullable|integer|min:0', 'beds.*.room_id' => 'nullable|integer',
        ]);
        $cols = (int) ($request->map_cols ?: $floor->map_cols);
        $rows = (int) ($request->map_rows ?: $floor->map_rows);
        $roomIds = HmsRoom::where('floor_id', $floor->id)->pluck('ward_id', 'id');

        DB::transaction(function () use ($request, $floor, $cols, $rows, $roomIds) {
            $floor->update(['map_cols' => $cols, 'map_rows' => $rows]);
            foreach ($request->input('rooms', []) as $r) {
                if (! $roomIds->has($r['id'])) {
                    continue;
                }
                $w = min((int) $r['w'], $cols);
                $h = min((int) $r['h'], $rows);
                HmsRoom::where('id', $r['id'])->update(['map_x' => min((int) $r['x'], $cols - $w), 'map_y' => min((int) $r['y'], $rows - $h), 'map_w' => $w, 'map_h' => $h]);
            }
            foreach ($request->input('beds', []) as $b) {
                $bed = HospitalBed::whereNull('deleted_at')->find($b['id']);
                if (! $bed) {
                    continue;
                }
                $roomId = isset($b['room_id']) && $roomIds->has($b['room_id']) ? (int) $b['room_id'] : null;
                $set = ['room_id' => $roomId,
                    'map_x' => $roomId !== null && isset($b['x']) ? min((int) $b['x'], $cols - 1) : null,
                    'map_y' => $roomId !== null && isset($b['y']) ? min((int) $b['y'], $rows - 1) : null];
                // A bed placed in a ward's room joins that ward (unless a patient is in it).
                if ($roomId && $roomIds[$roomId] && $bed->status !== 'occupied') {
                    $set['ward_id'] = $roomIds[$roomId];
                }
                $bed->update($set);
            }
        });

        return response()->json(['floors' => $this->floors(false)]);
    }

    /**
     * The bed map (read only): floors with rooms and beds -- status, how long in
     * that status, the patient in it. For the admission desk, the bed board, ER.
     */
    public function bedMap(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_setup', 'hms_wards', 'hms_admission_desk', 'hms_admissions', 'hms_er'), 403);

        return response()->json(['floors' => $this->floors(true, $request->filled('floor_id') ? (int) $request->floor_id : null)]);
    }

    /** One click: the cleaning staff finished -> the bed is free again. */
    public function cleanBed(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_wards', 'hms_admission_desk', 'hms_setup'), 403);
        $bed = HospitalBed::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($bed->status, ['cleaning', 'reserved'], true)) {
            return response()->json(['message' => 'Only a bed waiting for cleaning (or reserved) can be set free here.'], 422);
        }
        $bed->update(['status' => 'available']);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ helpers

    /** The floor maps (rooms + beds + who is in them) for other screens (My round). */
    public function mapFloors(bool $withPatients = true): array
    {
        return $this->floors($withPatients);
    }

    private function floors(bool $withPatients, ?int $onlyFloor = null): array
    {
        $floors = HmsFloor::whereNull('deleted_at')->when($onlyFloor, fn ($q) => $q->where('id', $onlyFloor))->orderBy('sort_order')->orderBy('level')->orderBy('id')->get();
        $rooms = HmsRoom::with('ward')->whereNull('deleted_at')->whereIn('floor_id', $floors->pluck('id'))->orderBy('name')->get();
        $beds = HospitalBed::with('ward')->whereNull('deleted_at')->whereIn('room_id', $rooms->pluck('id'))->orderBy('bed_number')->get();
        $occupants = collect();
        $er = collect();
        if ($withPatients) {
            $occupants = Admission::with('patient')->whereNull('deleted_at')->where('status', 'admitted')->whereIn('bed_id', $beds->pluck('id'))->get()->keyBy('bed_id');
            $er = ErCase::with('patient')->whereNull('deleted_at')->where('status', '!=', 'closed')->whereIn('bed_id', $beds->pluck('id'))->get()->keyBy('bed_id');
        }

        return $floors->map(fn ($f) => [
            'id' => $f->id, 'name' => $f->name, 'level' => $f->level, 'map_cols' => $f->map_cols, 'map_rows' => $f->map_rows, 'notes' => $f->notes,
            'rooms' => $rooms->where('floor_id', $f->id)->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'kind' => $r->kind, 'ward_id' => $r->ward_id, 'ward_name' => optional($r->ward)->name,
                'ward_type' => optional($r->ward)->type, 'x' => $r->map_x, 'y' => $r->map_y, 'w' => $r->map_w, 'h' => $r->map_h,
                'color' => $r->color, 'notes' => $r->notes,
            ])->values(),
            'beds' => $beds->filter(fn ($b) => optional($rooms->firstWhere('id', $b->room_id))->floor_id === $f->id)->map(function ($b) use ($occupants, $er, $withPatients) {
                $a = $occupants->get($b->id);
                $c = $er->get($b->id);
                $p = optional($a)->patient ?? optional($c)->patient;

                return [
                    'id' => $b->id, 'bed_number' => $b->bed_number, 'room_id' => $b->room_id, 'ward_id' => $b->ward_id,
                    'ward_name' => optional($b->ward)->name, 'ward_type' => optional($b->ward)->type,
                    'x' => $b->map_x, 'y' => $b->map_y, 'status' => $b->status, 'rate' => $b->effective_rate,
                    'status_minutes' => $b->status_changed_at ? (int) $b->status_changed_at->diffInMinutes(now()) : null,
                ] + ($withPatients ? ['patient_id' => optional($p)->id, 'patient_name' => optional($p)->name, 'admission_id' => optional($a)->id, 'er_case_id' => optional($c)->id] : []);
            })->values(),
        ])->values()->all();
    }

    /** "12" -> [12]; "1-4" -> [1..4]; "A,B" -> [A,B]. */
    private function expand(string $input): array
    {
        $input = trim($input);
        if (str_contains($input, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $input)), 'strlen'));
        }
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $input, $m) && $m[2] >= $m[1] && $m[2] - $m[1] <= 60) {
            return array_map('strval', range((int) $m[1], (int) $m[2]));
        }

        return $input === '' ? [] : [$input];
    }
}
