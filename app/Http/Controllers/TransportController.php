<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\CompanyNames;
use App\Support\ModuleFlags;
use App\Traits\ScopesWarehouseAccess;
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
 *  GET  transport/owner                the dashboard's business figures (income, tickets, trips, fleet costs, alerts)
 *  GET|POST transport/locations, PUT|DELETE transport/locations/{id}
 *  GET|POST transport/routes,    PUT|DELETE transport/routes/{id}
 *  GET|POST transport/trips,     PUT|DELETE transport/trips/{id}
 *  GET  transport/staff?from&to        active staff by type, trips and pay per trip
 */
class TransportController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

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

        return $q->orderBy('name')->get(['id', 'company_id', 'name', 'city', 'address', 'phone', 'description', 'image', 'kind', 'active', 'lat', 'lng', 'hub_id', 'daily_cost'])
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
            'transport_routes.distance_km', 'transport_routes.duration_min', 'transport_routes.toll_cost', 'transport_routes.parcel_rate_m3', 'transport_routes.active', 'o.name as origin', 'd.name as destination'])
            ->map(fn ($r) => (array) $r + ['label' => $r->origin.' → '.$r->destination,
                'stops' => DB::table('transport_route_stops')->where('route_id', $r->id)->orderBy('seq')->get(['location_id', 'km', 'minutes', 'dwell_min'])]);
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
            'daily_cost' => 'nullable|numeric|min:0|max:1000000',
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
            'lat' => $pos[0] ?? null, 'lng' => $pos[1] ?? null, 'hub_id' => $hub ? $hub->id : null, 'daily_cost' => $v['daily_cost'] ?? null, 'updated_at' => now()];
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
            'toll_cost' => 'nullable|numeric|min:0|max:1000000',
            'parcel_rate_m3' => 'nullable|numeric|min:0|max:1000000',
            'stops' => 'nullable|array', 'stops.*.location_id' => 'required|integer', 'stops.*.km' => 'required|numeric|min:0', 'stops.*.minutes' => 'required|integer|min:0', 'stops.*.dwell_min' => 'nullable|integer|min:0|max:600',
        ]);
        $this->own('transport_locations', (int) $v['origin_id'], $cid);
        $this->own('transport_locations', (int) $v['destination_id'], $cid);
        $dup = DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('origin_id', $v['origin_id'])->where('destination_id', $v['destination_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['destination_id' => 'This route already exists.']);
        }
        $set = ['origin_id' => $v['origin_id'], 'destination_id' => $v['destination_id'], 'distance_km' => $v['distance_km'] ?? null,
            'duration_min' => $v['duration_min'] ?? null, 'toll_cost' => $v['toll_cost'] ?? null, 'parcel_rate_m3' => $v['parcel_rate_m3'] ?? null, 'active' => (bool) ($v['active'] ?? true), 'updated_at' => now()];
        if ($id) {
            $this->own('transport_routes', $id, $cid);
            DB::table('transport_routes')->where('id', $id)->update($set);
        } else {
            $id = DB::table('transport_routes')->insertGetId($set + ['company_id' => $cid, 'created_at' => now()]);
        }
        // The route's stops, in the order of their km (a bus stops there and sells the seats emptied).
        if (array_key_exists('stops', $v)) {
            $stops = collect($v['stops'] ?? [])->sortBy('km')->values();
            foreach ($stops as $s) {
                $this->own('transport_locations', (int) $s['location_id'], $cid);
                abort_if(in_array((int) $s['location_id'], [(int) $v['origin_id'], (int) $v['destination_id']], true), 422, 'A stop is a place between the origin and the destination.');
            }
            DB::table('transport_route_stops')->where('route_id', $id)->delete();
            DB::table('transport_route_stops')->insert($stops->map(fn ($s, $i) => ['company_id' => $cid, 'route_id' => $id, 'location_id' => $s['location_id'], 'seq' => $i + 1, 'km' => $s['km'], 'minutes' => $s['minutes'],
                'dwell_min' => $s['dwell_min'] ?? 25, 'created_at' => now(), 'updated_at' => now()])->all());
            \App\Support\TransportLegs::forget();
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
        // A crew member who can only view (pilot / hostess, 2026-11-03) sees their own trips.
        $user = $request->user('api');
        $ownEmployee = HelpdeskAccess::can($user, 'transport_manage') ? null
            : DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->value('id');
        if ($ownEmployee && DB::table('transport_trip_crew')->where('employee_id', $ownEmployee)->exists()) {
            $request->merge(['employee_id' => $ownEmployee]);
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
            'fuel_cost' => $t->fuel_cost !== null ? (float) $t->fuel_cost : null,
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
            'fuel_cost' => 'nullable|numeric|min:0|max:1000000',
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

        $wasStatus = $id ? DB::table('transport_trips')->where('id', $id)->value('status') : null;
        $id = DB::transaction(function () use ($v, $cid, $id, $request, $people, $route, $wasStatus) {
            // A finished trip's km go on the bus's odometer: the maintenance plan runs on it.
            if ($v['status'] === 'completed' && $wasStatus !== 'completed' && (float) $route->distance_km > 0) {
                DB::table('vehicles')->where('id', $v['vehicle_id'])->increment('odometer', (float) $route->distance_km);
            }
            $set = ['route_id' => $v['route_id'], 'vehicle_id' => $v['vehicle_id'], 'departure_at' => $v['departure_at'],
                'arrival_at' => $v['arrival_at'] ?? null, 'status' => $v['status'], 'notes' => isset($v['notes']) ? strip_tags($v['notes']) : null,
                'fuel_cost' => $v['fuel_cost'] ?? null, 'updated_at' => now()];
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
        $type = isset(\App\Support\TransportTickets::DOCS[$request->query('doc_type')]) ? $request->query('doc_type') : 'dni';
        $dni = \App\Support\TransportTickets::docNumber($type, (string) $request->query('dni'));
        $valid = preg_match(\App\Support\TransportTickets::DOCS[$type], $dni);
        $cid = (int) $this->resolveActingCompanyId($request);
        $p = $valid ? DB::table('transport_tickets')->where('company_id', $cid)
            ->where('doc_type', $type)->where('dni', $dni)->orderByDesc('id')->first(['first_name', 'last_name', 'passenger_type']) : null;

        return response()->json(['passenger' => $p, 'loyalty' => $valid ? \App\Support\TransportTickets::loyalty($cid, $type, $dni) : null]);
    }

    /** GET transport/seats?trip_id=&route_id= : the seat map for the passenger's stretch on that trip. */
    public function seats(Request $request)
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'Pos_view'), 403);

        return response()->json(['seats' => \App\Support\TransportTickets::seats((int) $this->resolveActingCompanyId($request), (int) $request->query('trip_id'), (int) $request->query('route_id'))]);
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

    // ------------------------------------------------------------------ owner's panel

    /**
     * GET transport/owner -- the business figures on top of the dashboard (2026-11-04): income and
     * tickets today / this month by agency, today's trips, the month's fleet costs, the
     * best-selling routes and what falls due on the buses. Money: needs the dashboard
     * permission too, so a pilot's transport_view never shows it. Only the locations the
     * user may see.
     */
    public function owner(Request $request)
    {
        $this->can($request, 'transport_view');
        abort_unless(HelpdeskAccess::can($request->user('api'), 'dashboard'), 403);
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $scope = $this->warehouseScopeIds();
        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();
        $sales = fn () => DB::table('sales as s')->where('s.company_id', $cid)->whereNull('s.deleted_at')->where('s.statut', 'completed')
            ->when($scope !== null, fn ($q) => $q->whereIn('s.warehouse_id', $scope))
            ->whereDate('s.date', '>=', $month)->whereDate('s.date', '<=', $today);

        $income = $sales()->selectRaw('s.warehouse_id, SUM(s.GrandTotal) month_total, SUM(CASE WHEN DATE(s.date) = ? THEN s.GrandTotal ELSE 0 END) today_total', [$today])
            ->groupBy('s.warehouse_id')->get()->keyBy('warehouse_id');
        $products = \App\Support\TransportTickets::products($cid);
        $lines = $sales()->join('sale_details as d', 'd.sale_id', '=', 's.id')->whereIn('d.product_id', array_keys($products))
            ->selectRaw('s.warehouse_id, d.product_id, SUM(d.quantity) qty, SUM(d.total) total, SUM(CASE WHEN DATE(s.date) = ? THEN d.quantity ELSE 0 END) qty_today', [$today])
            ->groupBy('s.warehouse_id', 'd.product_id')->get();

        // One row per agency; sales of a location that is no agency go together at the end.
        $agencies = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->where('active', 1)
            ->when($scope !== null, fn ($q) => $q->whereIn('warehouse_id', $scope))->orderBy('name')->get(['name', 'warehouse_id']);
        $row = fn (?string $name, $wids) => [
            'name' => $name,
            'today' => round((float) $income->whereIn('warehouse_id', $wids)->sum('today_total'), 2),
            'month' => round((float) $income->whereIn('warehouse_id', $wids)->sum('month_total'), 2),
            'tickets_today' => (int) $lines->whereIn('warehouse_id', $wids)->sum('qty_today'),
            'tickets_month' => (int) $lines->whereIn('warehouse_id', $wids)->sum('qty'),
        ];
        $rows = $agencies->map(fn ($a) => $row($a->name, [$a->warehouse_id]))->values();
        $others = $income->keys()->diff($agencies->pluck('warehouse_id'))->values()->all();
        if ($others) {
            $rows->push($row(null, $others));
        }
        $routes = $lines->groupBy(fn ($l) => $products[$l->product_id]['route_id'] ?? 0)->map(fn ($g) => [
            'route' => str_replace(' VIP', '', $products[$g->first()->product_id]['label'] ?? ''), 'tickets' => (int) $g->sum('qty'), 'income' => round((float) $g->sum('total'), 2),
        ])->sortByDesc('tickets')->values();

        $trips = DB::table('transport_trips')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('departure_at', $today)
            ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        // The buses: spend so far this month (a job still scheduled or cancelled cost nothing yet).
        $buses = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')
            ->when($scope !== null, fn ($q) => $q->where(fn ($x) => $x->whereNull('warehouse_id')->orWhereIn('warehouse_id', $scope)))->pluck('id')->all();
        $fuel = (float) DB::table('vehicle_fuel_logs')->whereNull('deleted_at')->whereIn('vehicle_id', $buses)
            ->whereDate('log_date', '>=', $month)->whereDate('log_date', '<=', $today)->sum('total_cost');
        $maintenance = (float) DB::table('vehicle_maintenances')->whereNull('deleted_at')->whereIn('vehicle_id', $buses)
            ->whereNotIn('status', ['scheduled', 'cancelled'])
            ->whereDate('service_date', '>=', $month)->whereDate('service_date', '<=', $today)->sum('cost');
        $alerts = app(FleetVehicleController::class)->alerts(Carbon::today(), Carbon::today()->addDays(30), $buses);

        // The last 12 months (user's requests, 2026-11-11: "cómo le va"; the line is the profit, not the tickets): income, costs
        // and profit of the trips run each month, from TransportProfit -- the same figures as "Profit by route" -- the current
        // month up to today.
        $since = now()->startOfMonth()->subMonths(11)->toDateString();
        $run = collect(\App\Services\TransportProfit::compute($cid, $since, $today, $scope)['routes'] ?? [])
            ->flatMap(fn ($r) => $r['trips'])->where('result', '!=', 'pending');
        $byMonth = $run->groupBy(fn ($t) => substr((string) $t['departure_at'], 0, 7));
        $months = [];
        for ($m = Carbon::parse($since); $m->lte(now()); $m->addMonth()) {
            $g = $byMonth[$m->format('Y-m')] ?? collect();
            $in = (float) $g->sum(fn ($t) => $t['income']['total']);
            $out = (float) $g->sum(fn ($t) => $t['costs']['total']);
            $months[] = ['month' => $m->format('Y-m'), 'trips' => $g->count(), 'income' => round($in, 2), 'costs' => round($out, 2), 'profit' => round($in - $out, 2)];
        }

        return response()->json([
            'months' => $months,
            'enabled' => true,
            'income' => ['today' => round((float) $income->sum('today_total'), 2), 'month' => round((float) $income->sum('month_total'), 2)],
            'tickets' => ['today' => (int) $lines->sum('qty_today'), 'month' => (int) $lines->sum('qty')],
            'trips' => ['total' => (int) $trips->sum(), 'scheduled' => (int) ($trips['scheduled'] ?? 0), 'in_progress' => (int) ($trips['in_progress'] ?? 0),
                'completed' => (int) ($trips['completed'] ?? 0), 'cancelled' => (int) ($trips['cancelled'] ?? 0)],
            'costs' => ['fuel' => round($fuel, 2), 'maintenance' => round($maintenance, 2), 'total' => round($fuel + $maintenance, 2)],
            'agencies' => $rows, 'routes' => $routes->take(5)->values(), 'alerts' => array_slice($alerts, 0, 6), 'alerts_total' => count($alerts),
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
