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
 * Transport module (2026-11-01, Turismo Cautivo): locations (agencies -- a city's hub --
 * and the terminals of each agency),
 * routes between them, trips and their crew -- a trip = one vehicle, 2 pilots and 1
 * or 2 hostesses; neither the vehicle nor a crew member can be on two trips at the
 * same time -- and the active staff by type (administrative, maintenance, pilots,
 * hostesses) with their trips and pay per trip.
 * Permissions: transport_view (see), transport_manage (change). Module "transport".
 * Multicompany: lists follow listCompanyId() (AdminSystem = every company); writes the acting company.
 *
 *  GET  transport/meta                 locations, routes, vehicles, crew of the acting company
 *  GET  transport/board?from&to        the dashboard's transport board (map, agencies, fleet now, pilot hours)
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

        return $q->orderBy('name')->get(['id', 'company_id', 'name', 'city', 'address', 'phone', 'description', 'image', 'kind', 'active', 'lat', 'lng', 'hub_id'])
            ->map(function ($l) {
                $l->image_url = $l->image ? asset($l->image) : null;

                return $l;
            });
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
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'hub_id' => 'nullable|integer',
        ]);
        // An agency = a city's hub; a terminal belongs to one agency (hub_id).
        $hub = null;
        if ($v['kind'] === 'terminal' && ! empty($v['hub_id'])) {
            $hub = $this->own('transport_locations', (int) $v['hub_id'], $cid);
            abort_unless($hub->kind === 'agency' && (int) $hub->id !== (int) $id, 422, 'A terminal belongs to an agency.');
        }
        // No position typed: the city's / station's one when we know it.
        $pos = isset($v['lat'], $v['lng']) ? [$v['lat'], $v['lng']] : ($v['kind'] === 'online' ? null : \App\Support\PlaceCoordinates::find($v['name'], $v['city'] ?? null));
        // A terminal with no agency picked: the nearest one.
        if ($v['kind'] === 'terminal' && ! $hub && $pos) {
            $hub = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->whereNotNull('lat')->get()
                ->sortBy(fn ($t) => \App\Support\PlaceCoordinates::km((float) $pos[0], (float) $pos[1], (float) $t->lat, (float) $t->lng))->first();
        }
        $exists = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('name', $v['name'])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'There is already a location with this name.']);
        }
        $set = ['name' => strip_tags($v['name']), 'city' => $v['city'] ?? null, 'kind' => $v['kind'],
            'address' => isset($v['address']) ? strip_tags($v['address']) : null, 'phone' => isset($v['phone']) ? strip_tags($v['phone']) : null,
            'description' => isset($v['description']) ? strip_tags($v['description']) : null, 'active' => (bool) ($v['active'] ?? true),
            'lat' => $pos[0] ?? null, 'lng' => $pos[1] ?? null, 'hub_id' => $hub ? $hub->id : null, 'updated_at' => now()];
        if ($id) {
            $this->own('transport_locations', $id, $cid);
            DB::table('transport_locations')->where('id', $id)->update($set);
        } else {
            $id = DB::table('transport_locations')->insertGetId($set + ['company_id' => $cid, 'created_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    /** POST transport/locations/{id}/image {image}: the location's picture (files/company_{id}/transport). */
    public function locationImage(Request $request, int $id)
    {
        $cid = $this->writeCompany($request);
        $this->own('transport_locations', $id, $cid);
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);
        $path = \App\Support\CompanyFiles::putUpload($cid, 'transport', $request->file('image'));
        DB::table('transport_locations')->where('id', $id)->update(['image' => $path, 'updated_at' => now()]);

        return response()->json(['image' => $path, 'image_url' => asset($path)]);
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

    // ------------------------------------------------------------------ POS tickets (2026-11-03)

    /** GET transport/pos-tickets: the acting company's ticket products (product id => route) -- the POS asks the passenger for them. */
    public function posTickets(Request $request)
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'Pos_view'), 403);
        $cid = (int) $this->resolveActingCompanyId($request);

        return response()->json(['tickets' => ModuleFlags::enabled($cid, 'transport') ? (object) \App\Support\TransportTickets::products($cid) : (object) []]);
    }

    /** GET transport/departures?route_id= : the route's scheduled departures (next 14 days) for the POS. */
    public function departures(Request $request)
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'Pos_view'), 403);

        return response()->json(['departures' => \App\Support\TransportTickets::departures((int) $this->resolveActingCompanyId($request), (int) $request->query('route_id'))]);
    }

    /** GET transport/passenger?dni= : the names of the last ticket with that DNI in the company (auto-fill). */
    public function passenger(Request $request)
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'Pos_view'), 403);
        $dni = preg_replace('/\D/', '', (string) $request->query('dni'));
        $p = strlen($dni) === 8 ? DB::table('transport_tickets')->where('company_id', (int) $this->resolveActingCompanyId($request))
            ->where('dni', $dni)->orderByDesc('id')->first(['first_name', 'last_name']) : null;

        return response()->json(['passenger' => $p]);
    }

    // ------------------------------------------------------------------ board

    /** Safe hours a pilot may drive in 30 days (8 h x 26 working days); the period's limit is in proportion. */
    public const PILOT_HOURS_30_DAYS = 208;

    /**
     * GET transport/board?from&to -- the dashboard's transport board (2026-11-01) for the
     * acting company: the map (agencies, routes with km / hours and the trips on the
     * road now), where each bus is, per agency its terminals, the buses and crew that left from
     * it and its departures, the totals, and the pilots' driving hours against the safe
     * limit (the top one is the "record", red when over 90 %).
     */
    public function board(Request $request)
    {
        $this->can($request, 'transport_view');
        $cid = (int) $this->resolveActingCompanyId($request);
        $from = $request->input('from') ?: now()->subDays(29)->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $locs = $this->locationRows($cid)->where('active', 1)->values();
        $byId = $locs->keyBy('id');
        $now = now();

        $trips = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')
            ->whereBetween('t.departure_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['t.id', 't.route_id', 't.vehicle_id', 't.departure_at', 't.arrival_at', 't.status', 'r.origin_id', 'r.distance_km']);
        $crew = DB::table('transport_trip_crew')->whereIn('trip_id', $trips->pluck('id'))->get(['trip_id', 'employee_id', 'role'])->groupBy('trip_id');

        // Routes: one line per pair of agencies, km (typed, else estimated from the map), trips both ways.
        $routes = [];
        foreach ($this->routeRows($cid) as $r) {
            if (! $r['active']) {
                continue;
            }
            $a = min($r['origin_id'], $r['destination_id']);
            $b = max($r['origin_id'], $r['destination_id']);
            $key = "$a-$b";
            $km = $r['distance_km'] !== null ? (float) $r['distance_km'] : null;
            $estimated = false;
            if ($km === null && isset($byId[$a], $byId[$b]) && $byId[$a]->lat !== null && $byId[$b]->lat !== null) {
                // Roads are about 25 % longer than the straight line.
                $km = round(\App\Support\PlaceCoordinates::km((float) $byId[$a]->lat, (float) $byId[$a]->lng, (float) $byId[$b]->lat, (float) $byId[$b]->lng) * 1.25);
                $estimated = true;
            }
            $routes[$key] ??= ['a' => $a, 'b' => $b, 'km' => $km, 'estimated' => $estimated, 'minutes' => $r['duration_min'] ? (int) $r['duration_min'] : null, 'route_ids' => [], 'trips' => 0];
            $routes[$key]['route_ids'][] = (int) $r['id'];
            $routes[$key]['trips'] += $trips->where('route_id', $r['id'])->count();
        }

        // Trips on the road now: where along their line, the bus and its crew.
        $live = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')->whereIn('t.status', ['scheduled', 'in_progress'])
            ->where('t.departure_at', '<=', $now)->where('t.arrival_at', '>', $now)
            ->get(['t.id', 't.vehicle_id', 't.departure_at', 't.arrival_at', 'r.origin_id', 'r.destination_id', 'v.name as vehicle']);
        $liveCrew = DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->whereIn('c.trip_id', $live->pluck('id'))
            ->orderBy('c.role')->get(['c.trip_id', 'c.role', 'e.firstname', 'e.lastname'])->groupBy('trip_id');
        $live = $live->map(fn ($t) => ['id' => (int) $t->id, 'vehicle_id' => (int) $t->vehicle_id, 'vehicle' => $t->vehicle,
            'origin_id' => (int) $t->origin_id, 'destination_id' => (int) $t->destination_id,
            'origin' => $byId[$t->origin_id]->name ?? '', 'destination' => $byId[$t->destination_id]->name ?? '',
            'progress' => round(Carbon::parse($t->departure_at)->diffInSeconds($now) / max(1, Carbon::parse($t->departure_at)->diffInSeconds(Carbon::parse($t->arrival_at))), 3),
            'departure_at' => $t->departure_at, 'arrival_at' => $t->arrival_at,
            'pilots' => ($liveCrew[$t->id] ?? collect())->where('role', 'pilot')->map(fn ($c) => trim($c->firstname.' '.$c->lastname))->values(),
            'hostesses' => ($liveCrew[$t->id] ?? collect())->where('role', 'hostess')->map(fn ($c) => trim($c->firstname.' '.$c->lastname))->values(),
        ])->values();

        // Where each bus is now: on a trip, in maintenance, or at the agency its last trip
        // arrived at (else the one its next trip leaves from).
        $vehicles = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->whereNotIn('status', ['sold', 'inactive'])
            ->orderBy('name')->get(['id', 'name', 'plate_number', 'status']);
        $vids = $vehicles->pluck('id');
        $shop = DB::table('vehicle_maintenances as m')->leftJoin('providers as p', 'p.id', '=', 'm.provider_id')
            ->whereIn('m.vehicle_id', $vids)->whereNull('m.deleted_at')->where('m.status', 'in_progress')
            ->get(['m.vehicle_id', 'm.title', 'm.performed_by', 'p.name as provider'])->keyBy('vehicle_id');
        $lastAt = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->whereIn('t.vehicle_id', $vids)->whereNull('t.deleted_at')->where('t.status', 'completed')->where('t.arrival_at', '<=', $now)
            ->orderBy('t.arrival_at')->get(['t.vehicle_id', 'r.destination_id'])->keyBy('vehicle_id');
        $nextFrom = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->whereIn('t.vehicle_id', $vids)->whereNull('t.deleted_at')->where('t.status', 'scheduled')->where('t.departure_at', '>', $now)
            ->orderByDesc('t.departure_at')->get(['t.vehicle_id', 't.departure_at', 'r.origin_id'])->keyBy('vehicle_id');
        $liveByBus = $live->keyBy('vehicle_id');
        $fleet = $vehicles->map(function ($v) use ($liveByBus, $shop, $lastAt, $nextFrom, $byId) {
            $row = ['id' => (int) $v->id, 'name' => $v->name, 'plate' => $v->plate_number, 'state' => 'agency', 'location_id' => null, 'location' => null, 'detail' => null];
            if (isset($liveByBus[$v->id])) {
                $t = $liveByBus[$v->id];

                return ['state' => 'transit', 'location' => $t['origin'].' → '.$t['destination'], 'detail' => $t['arrival_at']] + $row;
            }
            if ($v->status === 'maintenance' || isset($shop[$v->id])) {
                $m = $shop[$v->id] ?? null;

                return ['state' => 'maintenance', 'location' => $m ? ($m->performed_by === 'workshop' && $m->provider ? $m->provider : null) : null, 'detail' => $m->title ?? null] + $row;
            }
            $at = $lastAt[$v->id]->destination_id ?? ($nextFrom[$v->id]->origin_id ?? null);

            return ['location_id' => $at ? (int) $at : null, 'location' => $at ? ($byId[$at]->name ?? null) : null,
                'detail' => $nextFrom[$v->id]->departure_at ?? null] + $row;
        })->values();

        // Per agency: its terminals, the buses parked there now, crew that left from it, departures.
        $hubs = $locs->where('kind', 'agency')->map(function ($t) use ($locs, $trips, $crew, $fleet) {
            $out = $trips->where('origin_id', $t->id);
            $people = $out->flatMap(fn ($x) => ($crew[$x->id] ?? collect())->pluck('employee_id'))->unique();

            return ['id' => (int) $t->id, 'name' => $t->name, 'city' => $t->city, 'image_url' => $t->image_url,
                'terminals' => $locs->where('kind', 'terminal')->where('hub_id', $t->id)->count(),
                'parked' => $fleet->where('state', 'agency')->where('location_id', $t->id)->count(),
                'crew' => $people->count(), 'departures' => $out->count()];
        })->values();

        // Pilots' driving hours in the period (trips done or under way).
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $limit = (int) round(self::PILOT_HOURS_30_DAYS * $days / 30);
        $hours = [];
        foreach ($trips as $t) {
            if (! in_array($t->status, ['completed', 'in_progress'], true)) {
                continue;
            }
            $end = $t->arrival_at ? min(Carbon::parse($t->arrival_at), $now) : $now;
            $h = max(0, Carbon::parse($t->departure_at)->diffInMinutes($end)) / 60;
            foreach (($crew[$t->id] ?? collect())->where('role', 'pilot') as $c) {
                $hours[$c->employee_id] = ($hours[$c->employee_id] ?? ['hours' => 0, 'trips' => 0]);
                $hours[$c->employee_id]['hours'] += $h;
                $hours[$c->employee_id]['trips']++;
            }
        }
        $names = DB::table('employees')->whereIn('id', array_keys($hours))->get(['id', 'firstname', 'lastname'])->keyBy('id');
        $pilots = collect($hours)->map(fn ($p, $id) => ['id' => (int) $id, 'name' => isset($names[$id]) ? trim($names[$id]->firstname.' '.$names[$id]->lastname) : '#'.$id,
            'hours' => round($p['hours'], 1), 'trips' => $p['trips'], 'pct' => $limit ? round($p['hours'] / $limit * 100) : 0])
            ->sortByDesc('hours')->values();

        $staff = DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', now()->toDateString()))
            ->whereNotNull('staff_type')->selectRaw('staff_type, count(*) n')->groupBy('staff_type')->pluck('n', 'staff_type');

        return response()->json([
            'enabled' => true, 'from' => $from, 'to' => $to, 'days' => $days,
            'locations' => $locs->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'city' => $l->city, 'kind' => $l->kind,
                'lat' => $l->lat !== null ? (float) $l->lat : null, 'lng' => $l->lng !== null ? (float) $l->lng : null, 'hub_id' => $l->hub_id ? (int) $l->hub_id : null])->values(),
            'routes' => array_values($routes), 'live' => $live, 'hubs' => $hubs, 'fleet' => $fleet,
            'totals' => [
                'agencies' => $locs->where('kind', 'agency')->count(), 'terminals' => $locs->where('kind', 'terminal')->count(), 'online' => $locs->where('kind', 'online')->count(),
                'buses' => DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->count(),
                'staff' => array_map('intval', $staff->all()), 'trips' => $trips->count(),
                'km' => (int) round($trips->where('status', 'completed')->sum(fn ($t) => (float) $t->distance_km)),
            ],
            'pilot_limit' => $limit, 'pilots' => $pilots,
        ]);
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
