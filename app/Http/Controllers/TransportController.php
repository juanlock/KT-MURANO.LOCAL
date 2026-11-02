<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\CompanyNames;
use App\Support\ModuleFlags;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transport module (2026-11-01, Turismo Cautivo): locations (terminals / agencies),
 * routes between them, trips and their crew -- a trip = one vehicle, 2 pilots and 1
 * or 2 hostesses; neither the vehicle nor a crew member can be on two trips at the
 * same time -- and the active staff by type (administrative, maintenance, pilots,
 * hostesses) with their trips and pay per trip.
 * Permissions: transport_view (see), transport_manage (change). Module "transport".
 * Multicompany: lists follow listCompanyId() (AdminSystem = every company); writes the acting company.
 *
 *  GET  transport/meta                 locations, routes, vehicles, crew of the acting company
 *  GET|POST transport/locations, PUT|DELETE transport/locations/{id}
 *  GET|POST transport/routes,    PUT|DELETE transport/routes/{id}
 *  GET|POST transport/trips,     PUT|DELETE transport/trips/{id}
 *  GET  transport/staff?from&to        active staff by type, trips and pay per trip
 */
class TransportController extends Controller
{
    use ScopesToCompany;

    public const STAFF_TYPES = ['administrative', 'maintenance', 'pilot', 'hostess'];

    private const TRIP_STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    private function can(Request $request, string $perm): void
    {
        $user = $request->user('api');
        $ok = HelpdeskAccess::can($user, $perm) || ($perm === 'transport_view' && HelpdeskAccess::can($user, 'transport_manage'));
        abort_unless($ok, 403, 'You do not have permission for Transport.');
    }

    /** The acting company for a write (never AdminSystem), module on. */
    private function writeCompany(Request $request): int
    {
        $this->can($request, 'transport_manage');
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    /** A row of $table that belongs to $cid (404 otherwise). */
    private function own(string $table, int $id, int $cid): object
    {
        $row = DB::table($table)->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->first();
        abort_unless($row, 404);

        return $row;
    }

    private function scoped(string $table, Request $request)
    {
        $q = DB::table($table)->whereNull($table.'.deleted_at');
        $list = $this->listCompanyId($request);
        if ($list !== null) {
            $q->where($table.'.company_id', $list);
        }

        return $q;
    }

    // ------------------------------------------------------------------ meta

    public function meta(Request $request)
    {
        $this->can($request, 'transport_view');
        $cid = (int) $this->resolveActingCompanyId($request);
        $crew = DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', now()->toDateString()))
            ->whereIn('staff_type', ['pilot', 'hostess'])->orderBy('firstname')
            ->get(['id', 'firstname', 'lastname', 'staff_type', 'trip_rate'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'name' => trim($e->firstname.' '.$e->lastname), 'staff_type' => $e->staff_type, 'trip_rate' => (float) $e->trip_rate]);

        return response()->json([
            'locations' => $this->locationRows($cid),
            'routes' => $this->routeRows($cid),
            'vehicles' => DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name', 'plate_number', 'status'])->map(fn ($v) => ['id' => (int) $v->id, 'label' => trim($v->name.' '.($v->plate_number ? '('.$v->plate_number.')' : '')), 'status' => $v->status]),
            'crew' => $crew,
        ]);
    }

    private function locationRows(?int $cid, ?Request $request = null)
    {
        $q = $request ? $this->scoped('transport_locations', $request) : DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at');

        return $q->orderBy('name')->get(['id', 'company_id', 'name', 'city', 'address', 'phone', 'description', 'kind', 'active']);
    }

    private function routeRows(?int $cid, ?Request $request = null)
    {
        $q = ($request ? $this->scoped('transport_routes', $request) : DB::table('transport_routes')->where('transport_routes.company_id', $cid)->whereNull('transport_routes.deleted_at'))
            ->join('transport_locations as o', 'o.id', '=', 'transport_routes.origin_id')
            ->join('transport_locations as d', 'd.id', '=', 'transport_routes.destination_id');

        return $q->orderBy('o.name')->orderBy('d.name')->get(['transport_routes.id', 'transport_routes.company_id', 'transport_routes.origin_id', 'transport_routes.destination_id',
            'transport_routes.distance_km', 'transport_routes.duration_min', 'transport_routes.active', 'o.name as origin', 'd.name as destination'])
            ->map(fn ($r) => (array) $r + ['label' => $r->origin.' → '.$r->destination]);
    }

    // ------------------------------------------------------------------ locations

    public function locations(Request $request)
    {
        $this->can($request, 'transport_view');

        return response()->json(['locations' => CompanyNames::attach($this->locationRows(null, $request)->map(fn ($r) => (array) $r)->all())]);
    }

    public function saveLocation(Request $request, ?int $id = null)
    {
        $cid = $this->writeCompany($request);
        $v = $request->validate([
            'name' => 'required|string|max:120', 'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:190', 'phone' => 'nullable|string|max:60', 'description' => 'nullable|string|max:2000',
            'kind' => 'required|in:terminal,agency,online', 'active' => 'boolean',
        ]);
        $exists = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('name', $v['name'])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'There is already a location with this name.']);
        }
        $set = ['name' => strip_tags($v['name']), 'city' => $v['city'] ?? null, 'kind' => $v['kind'],
            'address' => isset($v['address']) ? strip_tags($v['address']) : null, 'phone' => isset($v['phone']) ? strip_tags($v['phone']) : null,
            'description' => isset($v['description']) ? strip_tags($v['description']) : null, 'active' => (bool) ($v['active'] ?? true), 'updated_at' => now()];
        if ($id) {
            $this->own('transport_locations', $id, $cid);
            DB::table('transport_locations')->where('id', $id)->update($set);
        } else {
            $id = DB::table('transport_locations')->insertGetId($set + ['company_id' => $cid, 'created_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    public function deleteLocation(Request $request, int $id)
    {
        $cid = $this->writeCompany($request);
        $this->own('transport_locations', $id, $cid);
        abort_if(DB::table('transport_routes')->whereNull('deleted_at')->where(fn ($q) => $q->where('origin_id', $id)->orWhere('destination_id', $id))->exists(),
            422, 'This location is used by a route: remove the route first.');
        DB::table('transport_locations')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ routes

    public function routes(Request $request)
    {
        $this->can($request, 'transport_view');

        return response()->json(['routes' => CompanyNames::attach($this->routeRows(null, $request)->all())]);
    }

    public function saveRoute(Request $request, ?int $id = null)
    {
        $cid = $this->writeCompany($request);
        $v = $request->validate([
            'origin_id' => 'required|integer', 'destination_id' => 'required|integer|different:origin_id',
            'distance_km' => 'nullable|numeric|min:0|max:99999', 'duration_min' => 'nullable|integer|min:0|max:10000', 'active' => 'boolean',
        ]);
        $this->own('transport_locations', (int) $v['origin_id'], $cid);
        $this->own('transport_locations', (int) $v['destination_id'], $cid);
        $dup = DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('origin_id', $v['origin_id'])->where('destination_id', $v['destination_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['destination_id' => 'This route already exists.']);
        }
        $set = ['origin_id' => $v['origin_id'], 'destination_id' => $v['destination_id'], 'distance_km' => $v['distance_km'] ?? null,
            'duration_min' => $v['duration_min'] ?? null, 'active' => (bool) ($v['active'] ?? true), 'updated_at' => now()];
        if ($id) {
            $this->own('transport_routes', $id, $cid);
            DB::table('transport_routes')->where('id', $id)->update($set);
        } else {
            $id = DB::table('transport_routes')->insertGetId($set + ['company_id' => $cid, 'created_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    public function deleteRoute(Request $request, int $id)
    {
        $cid = $this->writeCompany($request);
        $this->own('transport_routes', $id, $cid);
        abort_if(DB::table('transport_trips')->whereNull('deleted_at')->where('route_id', $id)->exists(), 422, 'This route has trips: deactivate it instead.');
        DB::table('transport_routes')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ trips

    public function trips(Request $request)
    {
        $this->can($request, 'transport_view');
        $q = $this->scoped('transport_trips', $request)
            ->join('transport_routes as r', 'r.id', '=', 'transport_trips.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
            ->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'transport_trips.vehicle_id');
        foreach (['route_id', 'vehicle_id', 'status'] as $f) {
            if ($request->filled($f)) {
                $q->where('transport_trips.'.$f, $request->input($f));
            }
        }
        if ($request->filled('from')) {
            $q->whereDate('transport_trips.departure_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('transport_trips.departure_at', '<=', $request->input('to'));
        }
        if ($request->filled('employee_id')) {
            $q->whereExists(fn ($x) => $x->from('transport_trip_crew as c')->whereColumn('c.trip_id', 'transport_trips.id')->where('c.employee_id', $request->input('employee_id')));
        }
        $total = (clone $q)->count();
        $per = max(5, min(200, (int) $request->input('limit', 20)));
        $rows = $q->orderBy('transport_trips.departure_at', $request->input('SortType') === 'asc' ? 'asc' : 'desc')
            ->forPage(max(1, (int) $request->input('page', 1)), $per)
            ->get(['transport_trips.*', 'o.name as origin', 'd.name as destination', 'v.name as vehicle_name', 'v.plate_number']);
        $crew = DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->whereIn('c.trip_id', $rows->pluck('id'))->get(['c.trip_id', 'c.employee_id', 'c.role', 'c.trip_rate', 'e.firstname', 'e.lastname'])->groupBy('trip_id');
        $out = $rows->map(fn ($t) => [
            'id' => (int) $t->id, 'company_id' => (int) $t->company_id, 'route_id' => (int) $t->route_id, 'route' => $t->origin.' → '.$t->destination,
            'vehicle_id' => (int) $t->vehicle_id, 'vehicle' => trim(($t->vehicle_name ?? '').' '.($t->plate_number ? '('.$t->plate_number.')' : '')),
            'departure_at' => $t->departure_at, 'arrival_at' => $t->arrival_at, 'status' => $t->status, 'notes' => $t->notes,
            'crew' => collect($crew[$t->id] ?? [])->map(fn ($c) => ['employee_id' => (int) $c->employee_id, 'role' => $c->role, 'name' => trim($c->firstname.' '.$c->lastname), 'trip_rate' => (float) $c->trip_rate])->values(),
        ])->all();

        return response()->json(['trips' => CompanyNames::attach($out), 'totalRows' => $total]);
    }

    public function saveTrip(Request $request, ?int $id = null)
    {
        $cid = $this->writeCompany($request);
        $v = $request->validate([
            'route_id' => 'required|integer', 'vehicle_id' => 'required|integer',
            'departure_at' => 'required|date', 'arrival_at' => 'nullable|date|after:departure_at',
            'status' => 'required|in:'.implode(',', self::TRIP_STATUSES), 'notes' => 'nullable|string|max:2000',
            'pilots' => 'required|array|size:2', 'pilots.*' => 'integer|distinct',
            'hostesses' => 'required|array|min:1|max:2', 'hostesses.*' => 'integer|distinct',
        ], [
            'pilots.size' => 'A trip needs exactly 2 pilots.',
            'hostesses.min' => 'A trip needs 1 or 2 hostesses.', 'hostesses.max' => 'A trip needs 1 or 2 hostesses.',
        ]);
        $route = $this->own('transport_routes', (int) $v['route_id'], $cid);
        $this->own('vehicles', (int) $v['vehicle_id'], $cid);
        if (array_intersect($v['pilots'], $v['hostesses'])) {
            throw ValidationException::withMessages(['hostesses' => 'The same person can\'t be pilot and hostess.']);
        }
        $people = DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereIn('id', array_merge($v['pilots'], $v['hostesses']))->get(['id', 'firstname', 'lastname', 'staff_type', 'trip_rate'])->keyBy('id');
        foreach (['pilots' => 'pilot', 'hostesses' => 'hostess'] as $field => $type) {
            foreach ($v[$field] as $eid) {
                if (($people[$eid]->staff_type ?? null) !== $type) {
                    throw ValidationException::withMessages([$field => 'Choose '.($type === 'pilot' ? 'pilots' : 'hostesses').' of the company (HRM › Employees › Staff type).']);
                }
            }
        }

        // The trip's window: departure .. arrival (else + the route's duration, else 4 h).
        $from = Carbon::parse($v['departure_at']);
        $to = ! empty($v['arrival_at']) ? Carbon::parse($v['arrival_at']) : $from->copy()->addMinutes((int) ($route->duration_min ?: 240));
        if ($v['status'] !== 'cancelled') {
            $overlap = fn ($q) => $q->where('t.company_id', $cid)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')
                ->when($id, fn ($x) => $x->where('t.id', '!=', $id))
                ->where('t.departure_at', '<', $to)
                ->whereRaw('COALESCE(t.arrival_at, DATE_ADD(t.departure_at, INTERVAL COALESCE((select duration_min from transport_routes where id = t.route_id), 240) MINUTE)) > ?', [$from]);
            if (DB::table('transport_trips as t')->where($overlap)->where('t.vehicle_id', $v['vehicle_id'])->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => 'This vehicle is already on another trip at that time.']);
            }
            $busy = DB::table('transport_trips as t')->join('transport_trip_crew as c', 'c.trip_id', '=', 't.id')->where($overlap)
                ->whereIn('c.employee_id', array_merge($v['pilots'], $v['hostesses']))->pluck('c.employee_id')->unique();
            if ($busy->isNotEmpty()) {
                $names = $busy->map(fn ($e) => trim(($people[$e]->firstname ?? '').' '.($people[$e]->lastname ?? '')))->implode(', ');
                throw ValidationException::withMessages(['pilots' => "Already on another trip at that time: $names."]);
            }
        }

        $id = DB::transaction(function () use ($v, $cid, $id, $request, $people) {
            $set = ['route_id' => $v['route_id'], 'vehicle_id' => $v['vehicle_id'], 'departure_at' => $v['departure_at'],
                'arrival_at' => $v['arrival_at'] ?? null, 'status' => $v['status'], 'notes' => isset($v['notes']) ? strip_tags($v['notes']) : null, 'updated_at' => now()];
            if ($id) {
                $this->own('transport_trips', $id, $cid);
                DB::table('transport_trips')->where('id', $id)->update($set);
            } else {
                $id = DB::table('transport_trips')->insertGetId($set + ['company_id' => $cid, 'created_by' => $request->user('api')->id, 'created_at' => now()]);
            }
            // The crew, keeping the rate a member had when they were first put on this trip.
            $old = DB::table('transport_trip_crew')->where('trip_id', $id)->pluck('trip_rate', 'employee_id');
            DB::table('transport_trip_crew')->where('trip_id', $id)->delete();
            $rows = [];
            foreach (['pilots' => 'pilot', 'hostesses' => 'hostess'] as $field => $role) {
                foreach ($v[$field] as $eid) {
                    $rows[] = ['trip_id' => $id, 'employee_id' => $eid, 'role' => $role, 'trip_rate' => $old[$eid] ?? $people[$eid]->trip_rate];
                }
            }
            DB::table('transport_trip_crew')->insert($rows);

            return $id;
        });

        return response()->json(['id' => $id]);
    }

    public function deleteTrip(Request $request, int $id)
    {
        $cid = $this->writeCompany($request);
        $this->own('transport_trips', $id, $cid);
        DB::table('transport_trips')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ staff

    /**
     * Active staff by type: administrative and maintenance (normal schedule), pilots and
     * hostesses (paid per trip): trips completed in the period, pay, on a trip right now.
     */
    public function staff(Request $request)
    {
        $this->can($request, 'transport_view');
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        $q = $this->scoped('employees', $request)
            ->where(fn ($x) => $x->whereNull('employees.leaving_date')->orWhere('employees.leaving_date', '>=', now()->toDateString()))
            ->leftJoin('office_shifts as s', 's.id', '=', 'employees.office_shift_id')
            ->leftJoin('designations as g', 'g.id', '=', 'employees.designation_id');
        $people = $q->orderBy('employees.firstname')->get(['employees.id', 'employees.company_id', 'employees.firstname', 'employees.lastname', 'employees.staff_type',
            'employees.pay_type', 'employees.trip_rate', 'employees.phone', 's.name as shift', 'g.designation']);
        $ids = $people->pluck('id');
        $done = DB::table('transport_trip_crew as c')->join('transport_trips as t', 't.id', '=', 'c.trip_id')
            ->whereIn('c.employee_id', $ids)->whereNull('t.deleted_at')->where('t.status', 'completed')
            ->whereBetween(DB::raw('DATE(t.departure_at)'), [$from, $to])
            ->selectRaw('c.employee_id, count(*) n, sum(COALESCE(c.trip_rate, 0)) pay')->groupBy('c.employee_id')->get()->keyBy('employee_id');
        $now = now();
        $onTrip = DB::table('transport_trip_crew as c')->join('transport_trips as t', 't.id', '=', 'c.trip_id')
            ->join('transport_routes as r', 'r.id', '=', 't.route_id')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->whereIn('c.employee_id', $ids)->whereNull('t.deleted_at')->whereIn('t.status', ['scheduled', 'in_progress'])
            ->where('t.departure_at', '<=', $now)->where(fn ($x) => $x->whereNull('t.arrival_at')->orWhere('t.arrival_at', '>=', $now))
            ->get(['c.employee_id', 'o.name as origin', 'd.name as destination'])->keyBy('employee_id');
        $next = DB::table('transport_trip_crew as c')->join('transport_trips as t', 't.id', '=', 'c.trip_id')
            ->whereIn('c.employee_id', $ids)->whereNull('t.deleted_at')->where('t.status', 'scheduled')->where('t.departure_at', '>', $now)
            ->selectRaw('c.employee_id, min(t.departure_at) at')->groupBy('c.employee_id')->pluck('at', 'employee_id');
        $rows = $people->map(fn ($e) => [
            'id' => (int) $e->id, 'company_id' => (int) $e->company_id, 'name' => trim($e->firstname.' '.$e->lastname),
            'staff_type' => $e->staff_type ?: 'administrative', 'pay_type' => $e->pay_type, 'designation' => $e->designation, 'shift' => $e->shift, 'phone' => $e->phone,
            'trip_rate' => $e->trip_rate !== null ? (float) $e->trip_rate : null,
            'trips' => (int) ($done[$e->id]->n ?? 0), 'trip_pay' => round((float) ($done[$e->id]->pay ?? 0), 2),
            'on_trip' => isset($onTrip[$e->id]) ? $onTrip[$e->id]->origin.' → '.$onTrip[$e->id]->destination : null,
            'next_trip' => $next[$e->id] ?? null,
        ])->values()->all();

        return response()->json(['from' => $from, 'to' => $to, 'staff' => CompanyNames::attach($rows)]);
    }
}
