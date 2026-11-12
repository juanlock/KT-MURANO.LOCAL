<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportClaims;
use App\Services\TransportDepartures;
use App\Services\TransportIndicators;
use App\Services\TransportParcelAssign;
use App\Services\TransportParcelSale;
use App\Services\TransportProfit;
use App\Support\CompanyNames;
use App\Support\ModuleFlags;
use App\Support\TransportLegs;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transport's daily work (2026-11-04): the agency supervisor's departures (run, cancel or
 * reschedule a trip), parcels with their box measures against the bus's cargo limit,
 * tolls, and each trip's profit. Each user works on the trips that leave from their
 * locations (warehouseScopeIds; a Manager has all of them).
 * Permissions: transport_supervise, transport_parcels, transport_tolls, transport_profit.
 *
 *  GET  transport/today?date&warehouse_id      the day's departures of the agency
 *  POST transport/trips/{id}/decision          {action: confirm|cancel|reschedule, note, departure_at}
 *  GET  transport/trips/{id}/manifest          passengers and parcels of a trip
 *  GET|POST transport/parcels, PUT transport/parcels/{id}, POST transport/parcels/{id}/status
 *  GET  transport/parcels/meta                 agencies, prices per m³, estimate: the system picks the bus
 *  GET|POST transport/tolls, DELETE transport/tolls/{id}
 *  GET  transport/profit?from&to               TransportProfit
 *  GET  transport/indicators?from&to           TransportIndicators: profitability and risks, for charts
 *  GET  transport/departures?date&agency_id      TransportDepartures: the client's daily report, per agency
 *  GET  transport/claims?from&to               TransportClaims: the complaints book, counts only (needs Forms)
 */
class TransportOperationsController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    public const PARCEL_STATUSES = ['received', 'on_board', 'delivered', 'cancelled'];

    /** Cargo type => the price per m³ multiplied (Turismo's two prices, 2026-11-04: boxes S/ 238 per m³, a television about S/ 875). */
    public const CARGO_TYPES = ['general' => 1.0, 'fragile' => 3.7];

    private function allow(Request $request, string ...$perms): void
    {
        $user = $request->user('api');
        foreach ($perms as $perm) {
            if (HelpdeskAccess::can($user, $perm)) {
                return;
            }
        }
        abort(403, 'You do not have permission for this.');
    }

    /** The acting company (never AdminSystem), module on. */
    private function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    /** A trip of the company that leaves from one of the user's locations (404 otherwise). */
    private function ownTrip(int $id, int $cid): object
    {
        $scope = $this->warehouseScopeIds();
        $trip = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')
            ->where('t.id', $id)->where('t.company_id', $cid)->whereNull('t.deleted_at')
            ->when($scope !== null, fn ($q) => $this->onUsersPath($q, $scope))
            ->first(['t.*', 'o.warehouse_id as origin_warehouse', 'r.duration_min']);
        abort_unless($trip, 404);

        return $trip;
    }

    /** Trips of the company with route / bus names, from the user's locations. */
    private function tripQuery(int $cid)
    {
        $scope = $this->warehouseScopeIds();

        return DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')
            ->when($scope !== null, fn ($q) => $this->onUsersPath($q, $scope));
    }

    /** Trips that leave from or stop at one of the user's locations. */
    private function onUsersPath($q, array $scope)
    {
        return $q->where(fn ($w) => $w->whereIn('o.warehouse_id', $scope)->orWhereExists(fn ($e) => $e->from('transport_route_stops as s')
            ->join('transport_locations as l', 'l.id', '=', 's.location_id')->whereColumn('s.route_id', 't.route_id')->whereIn('l.warehouse_id', $scope)));
    }

    /** Where this user works on the route: the first place on its path among their locations (else the origin). */
    private function userStop(int $routeId): array
    {
        $path = TransportLegs::path($routeId);
        $scope = $this->warehouseScopeIds();
        if ($scope !== null) {
            $own = DB::table('transport_locations')->whereIn('id', array_column($path, 'location_id'))->whereIn('warehouse_id', $scope)->pluck('id')->map(fn ($v) => (int) $v)->all();
            foreach ($path as $stop) {
                if (in_array($stop['location_id'], $own, true)) {
                    return $stop;
                }
            }
        }

        return $path[0];
    }

    private const TRIP_COLUMNS = ['t.id', 't.route_id', 't.vehicle_id', 't.departure_at', 't.arrival_at', 't.status', 't.confirmed_at', 'r.parcel_rate_m3', 'o.name as origin', 'd.name as destination',
        'o.warehouse_id as origin_warehouse', 'v.name as vehicle', 'v.plate_number', 'v.seats', 'v.cargo_volume_m3', 'v.cargo_weight_kg'];

    /** [trip id => parcels, volume, weight] of the parcels a trip carries. */
    private function cargo(array $tripIds): array
    {
        return DB::table('transport_parcels')->whereIn('trip_id', $tripIds)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->selectRaw('trip_id, COUNT(*) n, SUM(volume_m3) volume, SUM(weight_kg) weight')->groupBy('trip_id')->get()->keyBy('trip_id')->all();
    }

    // ------------------------------------------------------------------ the day's departures

    public function today(Request $request)
    {
        $this->allow($request, 'transport_supervise');
        $cid = $this->company($request);
        $date = $request->input('date') ?: now()->toDateString();
        $q = $this->tripQuery($cid)->whereDate('t.departure_at', $date)
            ->when($request->filled('warehouse_id'), fn ($x) => $x->where('o.warehouse_id', (int) $request->input('warehouse_id')));
        $trips = $q->orderBy('t.departure_at')->get(self::TRIP_COLUMNS);
        $ids = $trips->pluck('id')->all();
        $scopeIds = $this->warehouseScopeIds();
        $sold = DB::table('transport_tickets')->whereIn('trip_id', $ids)->selectRaw('trip_id, COUNT(*) n')->groupBy('trip_id')->pluck('n', 'trip_id');
        $cargo = $this->cargo($ids);
        $tolls = DB::table('transport_tolls')->whereIn('trip_id', $ids)->whereNull('deleted_at')->selectRaw('trip_id, SUM(amount) s')->groupBy('trip_id')->pluck('s', 'trip_id');
        $crew = DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->whereIn('c.trip_id', $ids)
            ->orderBy('c.role')->get(['c.trip_id', 'c.role', 'e.firstname', 'e.lastname'])->groupBy('trip_id');
        $last = DB::table('transport_trip_decisions as x')->leftJoin('users as u', 'u.id', '=', 'x.user_id')->whereIn('x.trip_id', $ids)->orderBy('x.id')
            ->get(['x.trip_id', 'x.action', 'x.note', 'x.created_at', 'u.username'])->keyBy('trip_id');

        $scope = $this->warehouseScopeIds();
        $agencies = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->whereNotNull('warehouse_id')
            ->when($scope !== null, fn ($x) => $x->whereIn('warehouse_id', $scope))->orderBy('name')->get(['name', 'warehouse_id']);

        return response()->json([
            'date' => $date,
            'agencies' => $agencies,
            'trips' => $trips->map(fn ($t) => [
                'id' => (int) $t->id, 'route' => $t->origin.' → '.$t->destination, 'arrival_at' => $t->arrival_at,
                // The time it leaves from this user's agency (a stop, on a longer route).
                'departure_at' => Carbon::parse($t->departure_at)->addMinutes($this->userStop((int) $t->route_id)['minutes'])->format('Y-m-d H:i:s'),
                'can_decide' => $scopeIds === null || in_array((int) $t->origin_warehouse, array_map('intval', $scopeIds), true),
                'status' => $t->status, 'confirmed' => (bool) $t->confirmed_at, 'vehicle' => trim(($t->vehicle ?? '').' '.($t->plate_number ? '('.$t->plate_number.')' : '')),
                'seats' => $t->seats ? (int) $t->seats : null, 'sold' => (int) ($sold[$t->id] ?? 0),
                'parcels' => (int) ($cargo[$t->id]->n ?? 0), 'cargo_volume' => round((float) ($cargo[$t->id]->volume ?? 0), 2), 'cargo_limit' => $t->cargo_volume_m3 !== null ? (float) $t->cargo_volume_m3 : null,
                'tolls' => round((float) ($tolls[$t->id] ?? 0), 2),
                'crew' => collect($crew[$t->id] ?? [])->map(fn ($c) => ['role' => $c->role, 'name' => trim($c->firstname.' '.$c->lastname)])->values(),
                'last_decision' => isset($last[$t->id]) ? ['action' => $last[$t->id]->action, 'note' => $last[$t->id]->note, 'by' => $last[$t->id]->username, 'at' => $last[$t->id]->created_at] : null,
            ])->values(),
        ]);
    }

    /**
     * The supervisor's call on a scheduled trip, one transaction and on record. Reschedule keeps the
     * tickets on the trip at its new time; cancel answers who is affected, for the agency to
     * attend (user's OK, 2026-11-04: no automatic refund, no email).
     */
    public function decision(Request $request, int $id)
    {
        $this->allow($request, 'transport_supervise');
        $cid = $this->company($request);
        $v = $request->validate([
            'action' => 'required|in:confirm,cancel,reschedule',
            'note' => 'nullable|string|max:500',
            'departure_at' => 'required_if:action,reschedule|nullable|date',
        ]);
        if ($v['action'] !== 'confirm' && trim((string) ($v['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => 'Write the reason.']);
        }
        $trip = $this->ownTrip($id, $cid);
        abort_unless($trip->status === 'scheduled', 422, 'Only a scheduled trip can be decided.');
        // The agency it leaves from decides; a stop along the way only sells on it.
        $scope = $this->warehouseScopeIds();
        abort_if($scope !== null && ! in_array((int) $trip->origin_warehouse, array_map('intval', $scope), true), 403, 'Only the agency this trip leaves from can decide.');

        $set = ['updated_at' => now()];
        $new = null;
        if ($v['action'] === 'confirm') {
            $set += ['confirmed_at' => now(), 'confirmed_by' => $request->user('api')->id];
        } elseif ($v['action'] === 'cancel') {
            $set += ['status' => 'cancelled'];
        } else {
            $new = Carbon::parse($v['departure_at']);
            if ($new->isPast()) {
                throw ValidationException::withMessages(['departure_at' => 'The new departure must be in the future.']);
            }
            $minutes = $trip->arrival_at ? Carbon::parse($trip->departure_at)->diffInMinutes(Carbon::parse($trip->arrival_at)) : (int) ($trip->duration_min ?: 240);
            $arrival = $new->copy()->addMinutes($minutes);
            $this->checkFree($cid, $trip, $new, $arrival);
            $set += ['departure_at' => $new, 'arrival_at' => $arrival, 'confirmed_at' => null, 'confirmed_by' => null];
        }
        DB::transaction(function () use ($id, $cid, $set, $v, $trip, $new, $request) {
            DB::table('transport_trips')->where('id', $id)->update($set);
            DB::table('transport_trip_decisions')->insert([
                'company_id' => $cid, 'trip_id' => $id, 'action' => $v['action'], 'note' => isset($v['note']) ? strip_tags($v['note']) : null,
                'old_departure_at' => $trip->departure_at, 'new_departure_at' => $new, 'user_id' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        // A cancelled bus's parcels go on the next one with room.
        $affected = $v['action'] === 'cancel' ? $this->people($id) : null;
        if ($v['action'] === 'cancel') {
            TransportParcelAssign::releaseTrip($id);
        }

        return response()->json(['success' => true, 'affected' => $affected]);
    }

    /** Neither the bus nor a crew member may be on another trip at the new time. */
    private function checkFree(int $cid, object $trip, Carbon $from, Carbon $to): void
    {
        $overlap = fn ($q) => $q->where('t.company_id', $cid)->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')->where('t.id', '!=', $trip->id)
            ->where('t.departure_at', '<', $to)
            ->whereRaw('COALESCE(t.arrival_at, DATE_ADD(t.departure_at, INTERVAL COALESCE((select duration_min from transport_routes where id = t.route_id), 240) MINUTE)) > ?', [$from]);
        if (DB::table('transport_trips as t')->where($overlap)->where('t.vehicle_id', $trip->vehicle_id)->exists()) {
            throw ValidationException::withMessages(['departure_at' => 'The bus is on another trip at that time.']);
        }
        $crew = DB::table('transport_trip_crew')->where('trip_id', $trip->id)->pluck('employee_id');
        $busy = DB::table('transport_trips as t')->join('transport_trip_crew as c', 'c.trip_id', '=', 't.id')->where($overlap)->whereIn('c.employee_id', $crew)
            ->join('employees as e', 'e.id', '=', 'c.employee_id')->get(['e.firstname', 'e.lastname']);
        if ($busy->isNotEmpty()) {
            throw ValidationException::withMessages(['departure_at' => 'Crew on another trip at that time: '.$busy->map(fn ($e) => trim($e->firstname.' '.$e->lastname))->unique()->implode(', ').'.']);
        }
    }

    public function manifest(Request $request, int $id)
    {
        $this->allow($request, 'transport_supervise');
        $cid = $this->company($request);
        $this->ownTrip($id, $cid);

        return response()->json($this->people($id));
    }

    private function people(int $tripId): array
    {
        return [
            'passengers' => DB::table('transport_tickets')->where('trip_id', $tripId)->orderBy('last_name')
                ->get(['id', 'doc_type', 'dni', 'first_name', 'last_name', 'passenger_type', 'luggage', 'sale_id', 'seat_number', 'is_vip', 'code', 'boarded_at']),
            'parcels' => DB::table('transport_parcels')->where('trip_id', $tripId)->whereNull('deleted_at')->where('status', '!=', 'cancelled')->orderBy('id')
                ->get(['id', 'number', 'sender_name', 'sender_phone', 'receiver_name', 'receiver_phone', 'description', 'volume_m3', 'weight_kg', 'status']),
        ];
    }

    // ------------------------------------------------------------------ parcels

    public function parcels(Request $request)
    {
        $this->allow($request, 'transport_parcels');
        $cid = $this->listCompanyId($request);
        $scope = $this->warehouseScopeIds();
        // Without a bus yet it is still a parcel: the trip's data is optional. The agency that received it and the one it goes to see it.
        $q = DB::table('transport_parcels as p')->leftJoin('transport_trips as t', 't.id', '=', 'p.trip_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->leftJoin('transport_locations as a', 'a.id', '=', 'p.from_location_id')->leftJoin('transport_locations as b', 'b.id', '=', 'p.to_location_id')
            ->whereNull('p.deleted_at')
            ->when($cid !== null, fn ($x) => $x->where('p.company_id', $cid))
            ->when($scope !== null, fn ($x) => $x->where(fn ($w) => $w->whereIn('p.warehouse_id', $scope)->orWhereIn('b.warehouse_id', $scope)));
        if ($request->filled('status')) {
            $q->where('p.status', $request->input('status'));
        }
        if ($request->boolean('waiting')) {
            $q->whereNull('p.trip_id')->where('p.status', 'received');
        }
        if ($request->filled('from')) {
            $q->whereDate('p.received_on', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('p.received_on', '<=', $request->input('to'));
        }
        if ($request->filled('search')) {
            $s = '%'.trim((string) $request->input('search')).'%';
            $q->where(fn ($x) => $x->where('p.number', 'like', $s)->orWhere('p.sender_name', 'like', $s)->orWhere('p.receiver_name', 'like', $s));
        }
        $total = (clone $q)->count();
        $per = max(5, min(200, (int) $request->input('limit', 20)));
        $rows = $q->orderByDesc('p.id')->forPage(max(1, (int) $request->input('page', 1)), $per)
            ->get(['p.*', 't.departure_at', 'v.name as vehicle', 'a.name as from_name', 'b.name as to_name'])
            ->map(fn ($p) => (array) $p)->all();

        return response()->json(['parcels' => CompanyNames::attach($rows), 'totalRows' => $total]);
    }

    /** What the parcel form needs: where from (the user's agencies), where to, the prices per m³, the estimate. */
    public function parcelMeta(Request $request)
    {
        $this->allow($request, 'transport_parcels');
        $cid = $this->company($request);
        $scope = $this->warehouseScopeIds();
        $agencies = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->where('active', 1)->orderBy('name')->get(['id', 'name', 'warehouse_id']);
        $rates = [];
        foreach (DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('parcel_rate_m3')->get(['origin_id', 'destination_id', 'parcel_rate_m3']) as $r) {
            $rates[$r->origin_id.'-'.$r->destination_id] = (float) $r->parcel_rate_m3;
        }

        return response()->json([
            'origins' => $agencies->filter(fn ($a) => $scope === null || in_array((int) $a->warehouse_id, array_map('intval', $scope), true))->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])->values(),
            'destinations' => $agencies->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])->values(),
            'rates' => (object) $rates, 'cargo_factors' => self::CARGO_TYPES, 'eta_days' => TransportParcelAssign::ETA_DAYS,
            'payment_methods' => DB::table('payment_methods')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Who registers doesn't choose the departure (user's rule, 2026-11-04): date, destination and measures; the system
     * puts it on the first bus with room, and with none yet it waits (transport:assign-parcels).
     */
    public function saveParcel(Request $request, ?int $id = null)
    {
        $this->allow($request, 'transport_parcels');
        $cid = $this->company($request);
        $v = $request->validate([
            'received_on' => 'required|date', 'from_location_id' => 'required|integer', 'to_location_id' => 'required|integer|different:from_location_id',
            'sender_name' => 'required|string|max:120', 'sender_phone' => 'nullable|string|max:40',
            'receiver_name' => 'required|string|max:120', 'receiver_phone' => 'nullable|string|max:40', 'description' => 'nullable|string|max:190',
            'cargo_type' => 'required|in:'.implode(',', array_keys(self::CARGO_TYPES)),
            'payment' => 'required|in:paid,collect', 'payment_method_id' => 'nullable|integer',
            'pieces' => 'required|integer|min:1|max:999', 'length_cm' => 'required|numeric|min:0.1|max:100000', 'width_cm' => 'required|numeric|min:0.1|max:100000',
            'height_cm' => 'required|numeric|min:0.1|max:100000', 'weight_kg' => 'nullable|numeric|min:0|max:1000000', 'price' => 'required|numeric|min:0|max:10000000',
        ]);
        $volume = round((float) $v['length_cm'] * (float) $v['width_cm'] * (float) $v['height_cm'] * (int) $v['pieces'] / 1000000, 4);
        $weight = (float) ($v['weight_kg'] ?? 0);
        $scope = $this->warehouseScopeIds();
        $from = DB::table('transport_locations')->where('id', $v['from_location_id'])->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'warehouse_id']);
        abort_unless($from, 422, 'Choose an origin agency of the company.');
        abort_if($scope !== null && ! in_array((int) $from->warehouse_id, array_map('intval', $scope), true), 403, 'You register parcels at your own agency.');
        abort_unless(DB::table('transport_locations')->where('id', $v['to_location_id'])->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'Choose a destination agency of the company.');
        if (! TransportLegs::routesPassing($cid, (int) $v['from_location_id'], (int) $v['to_location_id'])) {
            throw ValidationException::withMessages(['to_location_id' => 'No route goes from here to that destination.']);
        }
        // It waits for a bus with room, but a box no bus can carry would wait forever.
        if (TransportParcelAssign::neverFits($cid, $volume, $weight)) {
            throw ValidationException::withMessages(['length_cm' => sprintf('It does not fit in any bus: this parcel measures %.2f m³ and weighs %.1f kg.', $volume, $weight)]);
        }
        [$etaFrom, $etaTo] = TransportParcelAssign::eta($v['received_on']);

        $saved = DB::transaction(function () use ($v, $id, $cid, $volume, $weight, $from, $etaFrom, $etaTo, $request) {
            $set = ['received_on' => $v['received_on'], 'eta_from' => $etaFrom, 'eta_to' => $etaTo, 'warehouse_id' => $from->warehouse_id, 'from_location_id' => $v['from_location_id'], 'to_location_id' => $v['to_location_id'],
                'cargo_type' => $v['cargo_type'], 'payment' => $v['payment'], 'sender_name' => strip_tags($v['sender_name']), 'sender_phone' => isset($v['sender_phone']) ? strip_tags($v['sender_phone']) : null,
                'receiver_name' => strip_tags($v['receiver_name']), 'receiver_phone' => isset($v['receiver_phone']) ? strip_tags($v['receiver_phone']) : null,
                'description' => isset($v['description']) ? strip_tags($v['description']) : null, 'pieces' => $v['pieces'], 'length_cm' => $v['length_cm'], 'width_cm' => $v['width_cm'],
                'height_cm' => $v['height_cm'], 'weight_kg' => $weight, 'volume_m3' => $volume, 'price' => $v['price'], 'trip_id' => null, 'updated_at' => now()];
            if ($id) {
                $old = DB::table('transport_parcels')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->first();
                abort_unless($old, 404);
                abort_unless($old->status === 'received', 422, 'Only a parcel still at the agency can be changed.');
                DB::table('transport_parcels')->where('id', $id)->update($set);
                if ($old->sale_id) {
                    TransportParcelSale::reprice((int) $old->sale_id, (float) $v['price']);
                }
                TransportParcelAssign::assign(DB::table('transport_parcels')->where('id', $id)->first());

                return $id;
            }
            $newId = DB::table('transport_parcels')->insertGetId($set + ['company_id' => $cid, 'number' => '', 'status' => 'received', 'created_by' => $request->user('api')->id, 'created_at' => now()]);
            $count = DB::table('transport_parcels')->where('company_id', $cid)->where('id', '<=', $newId)->count();
            DB::table('transport_parcels')->where('id', $newId)->update(['number' => 'ENC-'.str_pad((string) $count, 6, '0', STR_PAD_LEFT), 'tracking_token' => bin2hex(random_bytes(12))]);
            $parcel = DB::table('transport_parcels')->where('id', $newId)->first();
            $sale = TransportParcelSale::create($parcel, (int) $request->user('api')->id, isset($v['payment_method_id']) ? (int) $v['payment_method_id'] : null);
            DB::table('transport_parcels')->where('id', $newId)->update(['sale_id' => $sale->id]);
            DB::table('transport_parcel_events')->insert(['parcel_id' => $newId, 'status' => 'received', 'user_id' => $request->user('api')->id, 'created_at' => now()]);
            TransportParcelAssign::assign($parcel);

            return $newId;
        });

        return response()->json(['id' => $saved, 'volume_m3' => $volume, 'number' => DB::table('transport_parcels')->where('id', $saved)->value('number'), 'parcel' => $this->parcelForPrint($saved)]);
    }

    public function parcelStatus(Request $request, int $id)
    {
        $this->allow($request, 'transport_parcels');
        $cid = $this->company($request);
        $v = $request->validate(['status' => 'required|in:'.implode(',', self::PARCEL_STATUSES), 'delivered_to' => 'required_if:status,delivered|nullable|string|max:120', 'payment_method_id' => 'nullable|integer']);
        $parcel = DB::table('transport_parcels')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->first();
        abort_unless($parcel, 404);
        $scope = $this->warehouseScopeIds();
        // The agency that received it, or the one it goes to.
        $destination = (int) DB::table('transport_locations')->where('id', (int) $parcel->to_location_id)->value('warehouse_id');
        abort_if($scope !== null && ! array_intersect([(int) $parcel->warehouse_id, $destination], array_map('intval', $scope)), 404);
        abort_if(in_array($parcel->status, ['delivered', 'cancelled'], true), 422, 'This parcel is already closed.');
        abort_if($v['status'] === 'on_board' && ! $parcel->trip_id, 422, 'This parcel has no bus yet.');
        DB::transaction(function () use ($id, $v, $parcel, $request) {
            DB::table('transport_parcels')->where('id', $id)->update([
                'status' => $v['status'], 'updated_at' => now(),
                'delivered_to' => $v['status'] === 'delivered' ? strip_tags((string) $v['delivered_to']) : null,
                'delivered_at' => $v['status'] === 'delivered' ? now() : null,
            ]);
            DB::table('transport_parcel_events')->insert(['parcel_id' => $id, 'status' => $v['status'], 'user_id' => $request->user('api')->id, 'created_at' => now()]);
            if ($parcel->sale_id && $v['status'] === 'cancelled') {
                TransportParcelSale::cancel((int) $parcel->sale_id);
            }
            // The receiver pays on delivery.
            if ($parcel->sale_id && $v['status'] === 'delivered' && $parcel->payment === 'collect' && ($sale = \App\Models\Sale::whereNull('deleted_at')->find($parcel->sale_id))) {
                TransportParcelSale::pay($sale, (int) $request->user('api')->id, $request->filled('payment_method_id') ? (int) $request->input('payment_method_id') : null);
            }
        });

        return response()->json(['success' => true]);
    }

    /** What the guide prints. */
    private function parcelForPrint(int $id): array
    {
        $p = DB::table('transport_parcels as p')->leftJoin('transport_trips as t', 't.id', '=', 'p.trip_id')->leftJoin('transport_locations as a', 'a.id', '=', 'p.from_location_id')
            ->leftJoin('transport_locations as b', 'b.id', '=', 'p.to_location_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')->where('p.id', $id)
            ->first(['p.*', 't.departure_at', 'a.name as from_name', 'b.name as to_name', 'v.name as vehicle']);
        $row = (array) $p;
        $row['tracking_url'] = $p && $p->tracking_token ? rtrim(\App\Support\PublicUrl::base((int) $p->company_id), '/').'/encomienda/'.$p->tracking_token : null;

        return $row;
    }

    public function parcelPrint(Request $request, int $id)
    {
        $this->allow($request, 'transport_parcels');
        $cid = $this->company($request);
        abort_unless(DB::table('transport_parcels')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->exists(), 404);
        // A parcel made before tracking gets its link when first printed.
        DB::table('transport_parcels')->where('id', $id)->whereNull('tracking_token')->update(['tracking_token' => bin2hex(random_bytes(12))]);

        return response()->json(['parcel' => $this->parcelForPrint($id)]);
    }

    // ------------------------------------------------------------------ boarding passes

    /** GET transport/tickets?sale_id= or ?trip_id= : the boarding passes to print. */
    public function tickets(Request $request)
    {
        $this->allow($request, 'Pos_view', 'transport_boarding', 'transport_supervise');
        $cid = $this->company($request);
        abort_unless($request->filled('sale_id') || $request->filled('trip_id') || $request->filled('id'), 422);
        $rows = DB::table('transport_tickets as x')->join('transport_trips as t', 't.id', '=', 'x.trip_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->leftJoin('transport_locations as a', 'a.id', '=', 'x.from_location_id')->leftJoin('transport_locations as b', 'b.id', '=', 'x.to_location_id')->where('x.company_id', $cid)
            ->when($request->filled('sale_id'), fn ($q) => $q->where('x.sale_id', (int) $request->input('sale_id')))
            ->when($request->filled('trip_id'), fn ($q) => $q->where('x.trip_id', (int) $request->input('trip_id')))
            ->when($request->filled('id'), fn ($q) => $q->where('x.id', (int) $request->input('id')))
            ->orderBy('x.id')->limit(100)->get(['x.id', 'x.code', 'x.seat_number', 'x.is_vip', 'x.first_name', 'x.last_name', 'x.doc_type', 'x.dni', 'x.luggage', 'x.from_location_id', 't.route_id', 't.departure_at', 'a.name as from_name', 'b.name as to_name', 'v.name as vehicle', 'v.seats_lower']);

        return response()->json(['tickets' => $rows->map(function ($r) {
            $stop = collect(TransportLegs::path((int) $r->route_id))->firstWhere('location_id', (int) $r->from_location_id);

            return (array) $r + ['boards_at' => Carbon::parse($r->departure_at)->addMinutes($stop['minutes'] ?? 0)->format('Y-m-d H:i:s'),
                'floor' => $r->seat_number && $r->seats_lower ? ((int) $r->seat_number <= (int) $r->seats_lower ? 1 : 2) : null];
        })->values()]);
    }

    /** GET transport/boarding?date= : the day's trips here; with trip_id: its passengers. */
    public function boarding(Request $request)
    {
        $this->allow($request, 'transport_boarding', 'transport_supervise');
        $cid = $this->company($request);
        if ($request->filled('trip_id')) {
            $this->ownTrip((int) $request->input('trip_id'), $cid);
            $rows = DB::table('transport_tickets as x')->leftJoin('transport_locations as a', 'a.id', '=', 'x.from_location_id')->leftJoin('transport_locations as b', 'b.id', '=', 'x.to_location_id')
                ->where('x.trip_id', (int) $request->input('trip_id'))->orderBy('x.seat_number')
                ->get(['x.id', 'x.code', 'x.seat_number', 'x.is_vip', 'x.first_name', 'x.last_name', 'x.dni', 'x.boarded_at', 'a.name as from_name', 'b.name as to_name']);

            return response()->json(['passengers' => $rows]);
        }
        $date = $request->input('date') ?: now()->toDateString();
        $trips = $this->tripQuery($cid)->where('t.status', '!=', 'cancelled')->whereDate('t.departure_at', $date)->orderBy('t.departure_at')->get(self::TRIP_COLUMNS);

        return response()->json(['trips' => $trips->map(fn ($t) => ['id' => (int) $t->id, 'status' => $t->status, 'label' => substr((string) $t->departure_at, 11, 5).' · '.$t->origin.' → '.$t->destination.($t->vehicle ? ' · '.$t->vehicle : '')])->values()]);
    }

    /** POST transport/boarding {trip_id, code}: the QR of the boarding pass, read at the door. */
    public function board(Request $request)
    {
        $this->allow($request, 'transport_boarding', 'transport_supervise');
        $cid = $this->company($request);
        $v = $request->validate(['trip_id' => 'required|integer', 'code' => 'required|string|max:40']);
        $trip = $this->ownTrip((int) $v['trip_id'], $cid);
        abort_if($trip->status === 'cancelled', 422, 'This trip was cancelled.');
        $code = strtoupper(trim($v['code']));
        $ticket = DB::table('transport_tickets')->where('company_id', $cid)->where('code', $code)->first();
        if (! $ticket) {
            throw ValidationException::withMessages(['code' => 'That boarding pass does not exist.']);
        }
        if ((int) $ticket->trip_id !== (int) $trip->id) {
            throw ValidationException::withMessages(['code' => 'This boarding pass is for another trip.']);
        }
        if ($ticket->boarded_at) {
            throw ValidationException::withMessages(['code' => 'Already on board since '.substr((string) $ticket->boarded_at, 11, 5).'.']);
        }
        DB::table('transport_tickets')->where('id', $ticket->id)->update(['boarded_at' => now(), 'boarded_by' => $request->user('api')->id, 'updated_at' => now()]);

        return response()->json(['success' => true, 'passenger' => trim($ticket->first_name.' '.$ticket->last_name), 'seat' => $ticket->seat_number, 'vip' => (bool) $ticket->is_vip]);
    }

    // ------------------------------------------------------------------ tolls

    public function tolls(Request $request)
    {
        $this->allow($request, 'transport_tolls');
        $cid = $this->listCompanyId($request);
        $scope = $this->warehouseScopeIds();
        $q = DB::table('transport_tolls as x')->join('transport_trips as t', 't.id', '=', 'x.trip_id')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->whereNull('x.deleted_at')
            ->when($cid !== null, fn ($w) => $w->where('x.company_id', $cid))
            ->when($scope !== null, fn ($w) => $w->whereIn('o.warehouse_id', $scope))
            ->when($request->filled('trip_id'), fn ($w) => $w->where('x.trip_id', (int) $request->input('trip_id')));
        if ($request->filled('from')) {
            $q->whereDate('x.paid_on', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('x.paid_on', '<=', $request->input('to'));
        }
        $total = (clone $q)->count();
        $sum = (float) (clone $q)->sum('x.amount');
        $per = max(5, min(200, (int) $request->input('limit', 20)));
        $rows = $q->orderByDesc('x.paid_on')->orderByDesc('x.id')->forPage(max(1, (int) $request->input('page', 1)), $per)
            ->get(['x.*', 't.departure_at', 'o.name as origin', 'd.name as destination', 'v.name as vehicle'])
            ->map(fn ($r) => (array) $r + ['route' => $r->origin.' → '.$r->destination])->all();

        return response()->json(['tolls' => CompanyNames::attach($rows), 'totalRows' => $total, 'sum' => round($sum, 2)]);
    }

    /** Recent and coming trips to hang a toll on. */
    public function tollTrips(Request $request)
    {
        $this->allow($request, 'transport_tolls');
        $cid = $this->company($request);
        $trips = $this->tripQuery($cid)->where('t.status', '!=', 'cancelled')
            ->whereBetween('t.departure_at', [now()->subDays(14)->startOfDay(), now()->addDays(2)->endOfDay()])
            ->orderByDesc('t.departure_at')->limit(150)->get(self::TRIP_COLUMNS);

        return response()->json(['trips' => $trips->map(fn ($t) => ['id' => (int) $t->id, 'route' => $t->origin.' → '.$t->destination, 'departure_at' => $t->departure_at, 'vehicle' => $t->vehicle])->values()]);
    }

    public function saveToll(Request $request)
    {
        $this->allow($request, 'transport_tolls');
        $cid = $this->company($request);
        $v = $request->validate(['trip_id' => 'required|integer', 'place' => 'required|string|max:120', 'amount' => 'required|numeric|min:0.01|max:1000000',
            'paid_on' => 'required|date', 'note' => 'nullable|string|max:255']);
        $this->ownTrip((int) $v['trip_id'], $cid);
        $id = DB::table('transport_tolls')->insertGetId(['company_id' => $cid, 'trip_id' => $v['trip_id'], 'place' => strip_tags($v['place']), 'amount' => $v['amount'], 'paid_on' => $v['paid_on'],
            'note' => isset($v['note']) ? strip_tags($v['note']) : null, 'created_by' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id]);
    }

    public function deleteToll(Request $request, int $id)
    {
        $this->allow($request, 'transport_tolls');
        $cid = $this->company($request);
        $row = DB::table('transport_tolls')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        $this->ownTrip((int) $row->trip_id, $cid);
        DB::table('transport_tolls')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ profit

    public function profit(Request $request)
    {
        $this->allow($request, 'transport_profit');
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();

        return response()->json(TransportProfit::compute($cid, $from, $to, $this->warehouseScopeIds()));
    }

    public function indicators(Request $request)
    {
        $this->allow($request, 'transport_profit');
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();

        return response()->json(TransportIndicators::compute($cid, $from, $to, $this->warehouseScopeIds()));
    }

    public function departures(Request $request)
    {
        $this->allow($request, 'transport_profit');
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(TransportDepartures::compute($cid, $request->input('date') ?: now()->toDateString(), $request->filled('agency_id') ? (int) $request->input('agency_id') : null, $this->warehouseScopeIds()));
    }

    /** The dashboard's "Gastos" tab (2026-11-11): what the business spends, by kind, with its trend. */
    public function expenses(Request $request)
    {
        $this->allow($request, 'transport_profit');
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $to = min($request->input('to') ?: now()->toDateString(), now()->toDateString());
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $es = (string) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('default_language') !== 'en';

        return response()->json(\App\Services\TransportExpenses::compute($cid, $from, $to, $this->warehouseScopeIds(), $es));
    }

    /** The dashboard's Parcels tab (2026-11-09): attended / pending, per agency and route, what they are worth. */
    public function parcelsBoard(Request $request)
    {
        // A supervisor too (2026-11-11, "Mi agencia"): the board follows the agencies of their locations.
        $this->allow($request, 'transport_profit', 'transport_agency_view');
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport')) {
            return response()->json(['enabled' => false]);
        }
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        // Never past today: "This month" would count parcels not received yet.
        $to = min($request->input('to') ?: now()->toDateString(), now()->toDateString());

        return response()->json(\App\Services\TransportParcelsBoard::compute($cid, $from, $to, $this->warehouseScopeIds(), $request->integer('agency') ?: null));
    }

    public function claims(Request $request)
    {
        $this->allow($request, 'forms_view', 'forms_manage');
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($cid === 999 || ! ModuleFlags::enabled($cid, 'transport') || ! ModuleFlags::enabled($cid, 'forms')) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(TransportClaims::compute($cid, $request->input('from') ?: null, $request->input('to') ?: null));
    }
}
