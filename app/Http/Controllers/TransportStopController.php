<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportControl;
use App\Services\TransportTicketSale;
use App\Support\ModuleFlags;
use App\Support\TransportLegs;
use App\Support\TransportTickets;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Mi parada" (Turismo Cautivo, 2026-11-18): the work screen of the one person of a pass-through agency (Chepén, Trujillo).
 * The buses that pass through the agency today, and for each one: when it arrives (plan + the delay it carries, or the real
 * arrival), who gets off and on here, the last-minute passengers in the seats free from here on, the parcels to unload and
 * load, and the bus dispatched (a control point, TransportControl::mark with source "agency").
 * Permission transport_stop. The agent sees only the agencies of their locations; a Manager / Operaciones picks one.
 *
 *  GET  transport/stop?agency_id&date                 the agency's buses of the day
 *  GET  transport/stop/trips/{id}?agency_id           one bus at this stop: passengers, parcels, free seats
 *  POST transport/stop/tickets/{id}                   {agency_id, action: board|no_show|alight|undo}
 *  POST transport/stop/parcels/{id}                   {agency_id, action: load|unload}
 *  POST transport/stop/trips/{id}/arrive              {agency_id}
 *  POST transport/stop/trips/{id}/depart              {agency_id}: "Bus despachado"
 *  POST transport/stop/trips/{id}/sell                {agency_id, to_location_id, seat_number, doc_type, dni, first_name, last_name, price, payment_method_id}
 */
class TransportStopController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    private function company(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'transport_stop'), 403, __('You do not have permission for this.'));
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, __('Pick a company in the header first.'));
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    /** The agencies of the user's locations. */
    private function agencies(int $cid)
    {
        $scope = $this->warehouseScopeIds();

        return DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->whereNotNull('warehouse_id')
            ->when($scope !== null, fn ($q) => $q->whereIn('warehouse_id', $scope ?: [0]))->orderBy('name')->get(['id', 'name', 'warehouse_id']);
    }

    /** The agency asked for (one of the user's), else their first one. */
    private function agency(Request $request, int $cid): object
    {
        $list = $this->agencies($cid);
        abort_if($list->isEmpty(), 422, __('Your login has no transport agency.'));
        $agency = $request->filled('agency_id') ? $list->firstWhere('id', (int) $request->input('agency_id')) : $list->first();
        abort_unless($agency, 403, __('That agency is not yours.'));

        return $agency;
    }

    /** Routes of the company whose path goes through the location: [route id => index on the path]. */
    private function routesThrough(int $cid, int $locationId): array
    {
        $out = [];
        foreach (DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->pluck('id') as $rid) {
            $at = array_search($locationId, array_column(TransportLegs::path((int) $rid), 'location_id'), true);
            if ($at !== false) {
                $out[(int) $rid] = $at;
            }
        }

        return $out;
    }

    private function trips(int $cid)
    {
        return DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')
            ->select('t.*', 'o.name as origin', 'd.name as destination', 'v.name as vehicle', 'v.plate_number', 'v.seats', 'v.seats_lower', 'v.vip_seats');
    }

    /** A trip of the company that goes through the agency (404 otherwise). */
    private function tripHere(int $cid, int $id, object $agency): object
    {
        $trip = $this->trips($cid)->where('t.id', $id)->first();
        abort_unless($trip && array_key_exists((int) $trip->route_id, $this->routesThrough($cid, (int) $agency->id)), 404);

        return $trip;
    }

    private function when(?Carbon $at): ?string
    {
        return $at ? $at->format('Y-m-d H:i:s') : null;
    }

    /** The agency's point on the trip, with the arrival / departure expected from the delay the bus carries. */
    private function stopInfo(object $trip, array $points, int $locationId, ?object $position): ?array
    {
        $i = array_search($locationId, array_column($points, 'location_id'), true);
        if ($i === false) {
            return null;
        }
        $p = $points[$i];
        $running = in_array($trip->status, ['scheduled', 'in_progress'], true);
        $delay = TransportControl::carriedDelay($points, null, $running);
        // The agency waits for it: past its time here and not arrived is late by that much, reported or not.
        $due = $p['first'] ? $p['plan_depart'] : $p['plan_arrive'];
        if ($running && $due && ! $p['arrived_at'] && ! $p['departed_at'] && ! $p['skipped'] && now()->gt($due)) {
            $delay = max((int) $delay, (int) round($due->diffInMinutes(now(), false)));
        }
        $late = max(0, (int) $delay);
        $eta = $p['arrived_at'] ? Carbon::parse($p['arrived_at']) : ($p['plan_arrive'] ? $p['plan_arrive']->copy()->addMinutes($late) : null);
        $etd = $p['departed_at'] ? Carbon::parse($p['departed_at']) : ($p['plan_depart'] ? $p['plan_depart']->copy()->addMinutes($late) : null);
        $km = null;
        if ($position && $p['lat'] !== null && ! $p['arrived_at'] && ! $p['departed_at']) {
            $km = round(TransportControl::metres((float) $position->lat, (float) $position->lng, $p['lat'], $p['lng']) / 1000, 1);
        }

        $kind = $p['first'] ? 'origin' : ($p['last'] ? 'destination' : 'stop');
        // Still to attend here: a running trip that has not left this agency (a trip on its way has left its origin).
        $pending = $running && ! $p['departed_at'] && ! $p['skipped'] && ! ($kind === 'destination' && $p['arrived_at'])
            && ! ($kind === 'origin' && $trip->status === 'in_progress');

        return [
            'index' => $i, 'kind' => $kind, 'pending' => $pending,
            'plan_arrive' => $this->when($p['plan_arrive']), 'plan_depart' => $this->when($p['plan_depart']),
            'eta' => $this->when($eta), 'etd' => $this->when($etd), 'delay_min' => $delay, 'late' => $delay !== null && $delay > TransportControl::LATE_MIN,
            'arrived_at' => $p['arrived_at'], 'departed_at' => $p['departed_at'], 'skipped' => $p['skipped'],
            'position' => $position ? ['km' => $km, 'speed' => $position->speed_kmh !== null ? (float) $position->speed_kmh : null,
                'age_min' => (int) round(Carbon::parse($position->recorded_at)->diffInMinutes(now(), false))] : null,
            // The time this bus is at the agency, to sort and to pick the day.
            'at' => $p['plan_arrive'] ?? $p['plan_depart'],
        ];
    }

    // ------------------------------------------------------------------ the day's buses

    public function index(Request $request)
    {
        $cid = $this->company($request);
        $agency = $this->agency($request, $cid);
        $date = $request->input('date') ?: now()->toDateString();
        $routes = $this->routesThrough($cid, (int) $agency->id);
        $day = Carbon::parse($date);
        // A long route reaches a stop the day after it leaves (Lima 16:30 -> Trujillo 01:30).
        $trips = ! $routes ? collect() : $this->trips($cid)->whereIn('t.route_id', array_keys($routes))->where('t.status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereBetween('t.departure_at', [$day->copy()->subDay()->startOfDay(), $day->copy()->endOfDay()])->orWhere('t.status', 'in_progress'))
            ->orderBy('t.departure_at')->get();
        $ids = $trips->pluck('id')->all();
        $boardings = DB::table('transport_trip_boardings')->whereIn('trip_id', $ids ?: [0])->orderBy('seq')->get(['trip_id', 'location_id', 'label', 'board_at'])->groupBy('trip_id');
        $checks = DB::table('transport_trip_checkpoints')->whereIn('trip_id', $ids ?: [0])->get()->groupBy('trip_id');
        $latest = DB::table('transport_vehicle_positions')->whereIn('id', DB::table('transport_vehicle_positions')->whereIn('trip_id', $ids ?: [0])->selectRaw('MAX(id)')->groupBy('trip_id'))->get()->keyBy('trip_id');
        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $ids ?: [0])->get(['trip_id', 'from_location_id', 'to_location_id', 'seat_number', 'boarded_at', 'no_show_at', 'alighted_at'])->groupBy('trip_id');
        $parcels = DB::table('transport_parcels')->whereIn('trip_id', $ids ?: [0])->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('from_location_id', $agency->id)->orWhere('to_location_id', $agency->id))->get(['id', 'trip_id', 'from_location_id', 'to_location_id', 'status'])->groupBy('trip_id');
        $unloaded = $this->unloaded($parcels->flatten()->pluck('id')->all());
        $today = $date === now()->toDateString();

        $buses = [];
        foreach ($trips as $t) {
            $points = TransportControl::points($t, $boardings[$t->id] ?? collect(), $checks[$t->id] ?? collect());
            $s = $this->stopInfo($t, $points, (int) $agency->id, $latest[$t->id] ?? null);
            if (! $s || ! $s['at']) {
                continue;
            }
            // The day's buses; today also a bus on its way that is late for this agency since yesterday.
            if ($s['at']->toDateString() !== $date && ! ($today && $t->status === 'in_progress' && $s['pending'] && $s['at']->gte(now()->subHours(12)))) {
                continue;
            }
            $down = $up = ['n' => 0, 'done' => 0, 'no_show' => 0];
            foreach ($tickets[$t->id] ?? [] as $k) {
                [$a, $b] = TransportLegs::ticketStretch((int) $t->route_id, $k);
                if ($b === $s['index']) {
                    $down['n']++;
                    $down['done'] += $k->alighted_at ? 1 : 0;
                }
                if ($a === $s['index']) {
                    $up['n']++;
                    $up['done'] += $k->boarded_at ? 1 : 0;
                    $up['no_show'] += $k->no_show_at ? 1 : 0;
                }
            }
            $pc = collect($parcels[$t->id] ?? []);
            $pDown = $pc->where('to_location_id', $agency->id);
            $pUp = $pc->where('from_location_id', $agency->id);
            $buses[] = [
                'id' => (int) $t->id, 'route' => $t->origin.' → '.$t->destination, 'origin' => $t->origin, 'destination' => $t->destination,
                'vehicle' => $t->vehicle, 'plate' => $t->plate_number, 'status' => $t->status, 'departure_at' => $t->departure_at,
                'down' => $down, 'up' => $up,
                'parcels_down' => ['n' => $pDown->count(), 'done' => $pDown->filter(fn ($p) => isset($unloaded[$p->id]) || $p->status === 'delivered')->count()],
                'parcels_up' => ['n' => $pUp->count(), 'done' => $pUp->whereIn('status', ['on_board', 'delivered'])->count()],
            ] + array_diff_key($s, ['at' => 1, 'index' => 1]) + ['sort' => $this->when($s['at'])];
        }
        usort($buses, fn ($x, $y) => strcmp($x['sort'], $y['sort']));

        return response()->json([
            'date' => $date, 'agency' => ['id' => (int) $agency->id, 'name' => $agency->name], 'late_min' => TransportControl::LATE_MIN,
            'agencies' => $this->agencies($cid)->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])->values(),
            'buses' => $buses,
        ]);
    }

    /** [parcel id => when] of the parcels unloaded ("Descargado" here). */
    private function unloaded(array $ids): array
    {
        return $ids ? DB::table('transport_parcel_events')->whereIn('parcel_id', $ids)->where('status', 'unloaded')->pluck('created_at', 'parcel_id')->all() : [];
    }

    // ------------------------------------------------------------------ one bus at the stop

    public function trip(Request $request, int $id)
    {
        $cid = $this->company($request);
        $agency = $this->agency($request, $cid);

        return response()->json($this->detail($cid, $this->tripHere($cid, $id, $agency), $agency));
    }

    private function detail(int $cid, object $trip, object $agency): array
    {
        $points = TransportControl::points($trip);
        $position = DB::table('transport_vehicle_positions')->where('trip_id', $trip->id)->orderByDesc('id')->first();
        $s = $this->stopInfo($trip, $points, (int) $agency->id, $position);
        $path = TransportLegs::path((int) $trip->route_id);
        $here = $this->routesThrough($cid, (int) $agency->id)[(int) $trip->route_id];
        $names = DB::table('transport_locations')->whereIn('id', array_column($path, 'location_id'))->pluck('name', 'id');

        $rows = DB::table('transport_tickets')->where('trip_id', $trip->id)->orderBy('seat_number')
            ->get(['id', 'code', 'seat_number', 'is_vip', 'first_name', 'last_name', 'doc_type', 'dni', 'passenger_type', 'luggage', 'channel', 'from_location_id', 'to_location_id',
                'boarded_at', 'no_show_at', 'alighted_at', 'price']);
        $down = $up = [];
        foreach ($rows as $k) {
            [$a, $b] = TransportLegs::ticketStretch((int) $trip->route_id, $k);
            $row = (array) $k + ['from_name' => $names[$path[$a]['location_id']] ?? '', 'to_name' => $names[$path[$b]['location_id']] ?? ''];
            if ($b === $here) {
                $down[] = $row;
            }
            if ($a === $here) {
                $up[] = $row;
            }
        }

        $parcels = DB::table('transport_parcels as p')->leftJoin('transport_locations as a', 'a.id', '=', 'p.from_location_id')->leftJoin('transport_locations as b', 'b.id', '=', 'p.to_location_id')
            ->where('p.trip_id', $trip->id)->whereNull('p.deleted_at')->where('p.status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('p.from_location_id', $agency->id)->orWhere('p.to_location_id', $agency->id))->orderBy('p.number')
            ->get(['p.id', 'p.number', 'p.from_location_id', 'p.to_location_id', 'p.sender_name', 'p.receiver_name', 'p.receiver_phone', 'p.description', 'p.pieces', 'p.weight_kg',
                'p.volume_m3', 'p.cargo_type', 'p.payment', 'p.price', 'p.status', 'a.name as from_name', 'b.name as to_name']);
        $unloaded = $this->unloaded($parcels->pluck('id')->all());
        $parcels = $parcels->map(fn ($p) => (array) $p + ['unloaded_at' => $unloaded[$p->id] ?? null]);

        // Last-minute passengers: the seats free from here to each later stop, and the stretch's ticket prices.
        $map = TransportLegs::seatMap($trip);
        $products = DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->where('code', 'like', 'PAS-%')->get(['id', 'code', 'price'])->keyBy('code');
        $routeIds = DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->where('origin_id', $agency->id)->pluck('id', 'destination_id');
        $sellable = $this->sellable($trip, $s);
        $stops = [];
        for ($j = $here + 1; $j < count($path); $j++) {
            $loc = $path[$j]['location_id'];
            $taken = TransportLegs::takenSeats((int) $trip->id, (int) $trip->route_id, $here, $j);
            $rid = $routeIds[$loc] ?? null;
            $stops[] = [
                'location_id' => $loc, 'name' => $names[$loc] ?? '',
                'free' => array_values(array_map(fn ($x) => ['number' => $x['number'], 'vip' => $x['vip'], 'floor' => $x['floor']], array_filter($map, fn ($x) => ! in_array($x['number'], $taken, true)))),
                'price' => $rid && isset($products['PAS-'.$rid]) ? (float) $products['PAS-'.$rid]->price : null,
                'price_vip' => $rid && isset($products['PAS-'.$rid.'-VIP']) ? (float) $products['PAS-'.$rid.'-VIP']->price : null,
            ];
        }

        return [
            'trip' => ['id' => (int) $trip->id, 'route' => $trip->origin.' → '.$trip->destination, 'vehicle' => $trip->vehicle, 'plate' => $trip->plate_number,
                'status' => $trip->status, 'departure_at' => $trip->departure_at, 'seats' => $trip->seats ? (int) $trip->seats : null],
            'agency' => ['id' => (int) $agency->id, 'name' => $agency->name],
            'stop' => $s ? array_diff_key($s, ['at' => 1, 'index' => 1]) : null,
            'crew' => DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->where('c.trip_id', $trip->id)->orderBy('c.role')
                ->get(['c.role', 'e.firstname', 'e.lastname', 'e.phone'])->map(fn ($c) => ['role' => $c->role, 'name' => trim($c->firstname.' '.$c->lastname), 'phone' => $c->phone])->values(),
            'down' => $down, 'up' => $up,
            'parcels_down' => $parcels->where('to_location_id', $agency->id)->values(), 'parcels_up' => $parcels->where('from_location_id', $agency->id)->values(),
            'can_sell' => $sellable === null, 'sell_block' => $sellable, 'stops' => $stops,
            'payment_methods' => DB::table('payment_methods')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'register_open' => DB::table('cash_registers')->where('user_id', auth()->id())->where('warehouse_id', $agency->warehouse_id)->where('status', 'open')->exists(),
        ];
    }

    /** Why no passenger can be sold here now (null = they can). */
    private function sellable(object $trip, ?array $s): ?string
    {
        if (! in_array($trip->status, ['scheduled', 'in_progress'], true)) {
            return __('This trip is not running.');
        }
        if (! $s || $s['kind'] === 'destination') {
            return __('The bus ends its route here.');
        }
        if (! $s['pending']) {
            return __('The bus already left this agency.');
        }

        return null;
    }

    // ------------------------------------------------------------------ passengers

    public function ticket(Request $request, int $id)
    {
        $cid = $this->company($request);
        $agency = $this->agency($request, $cid);
        $v = $request->validate(['action' => 'required|in:board,no_show,alight,undo']);
        $ticket = DB::table('transport_tickets')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($ticket && $ticket->trip_id, 404);
        $trip = $this->tripHere($cid, (int) $ticket->trip_id, $agency);
        $here = $this->routesThrough($cid, (int) $agency->id)[(int) $trip->route_id];
        [$a, $b] = TransportLegs::ticketStretch((int) $trip->route_id, $ticket);
        $uid = (int) $request->user('api')->id;
        $boardsHere = $a === $here;

        if ($v['action'] === 'board' || $v['action'] === 'no_show') {
            abort_unless($boardsHere, 422, __('This passenger does not board here.'));
            abort_if($trip->status === 'cancelled', 422, __('This trip was cancelled.'));
        }
        if ($v['action'] === 'board' && ($error = TransportTickets::board($ticket, $uid))) {
            abort(422, __($error));
        }
        if ($v['action'] === 'no_show') {
            abort_if((bool) $ticket->boarded_at, 422, __('This passenger is already on board.'));
            DB::table('transport_tickets')->where('id', $id)->whereNull('no_show_at')->update(['no_show_at' => now(), 'no_show_by' => $uid, 'updated_at' => now()]);
        }
        if ($v['action'] === 'alight') {
            abort_unless($b === $here, 422, __('This passenger does not get off here.'));
            DB::table('transport_tickets')->where('id', $id)->whereNull('alighted_at')->update(['alighted_at' => now(), 'alighted_by' => $uid, 'updated_at' => now()]);
        }
        if ($v['action'] === 'undo') {
            DB::transaction(function () use ($ticket, $trip, $a, $b, $here, $boardsHere, $id) {
                if ($boardsHere && $ticket->no_show_at) {
                    // The seat may have been sold again meanwhile.
                    DB::table('transport_trips')->where('id', $trip->id)->lockForUpdate()->first();
                    if ($ticket->seat_number && in_array((int) $ticket->seat_number, TransportLegs::takenSeats((int) $trip->id, (int) $trip->route_id, $a, $b), true)) {
                        abort(422, __('That seat was sold again: the passenger needs another seat.'));
                    }
                    DB::table('transport_tickets')->where('id', $id)->update(['no_show_at' => null, 'no_show_by' => null, 'updated_at' => now()]);
                } elseif ($boardsHere && $ticket->boarded_at) {
                    DB::table('transport_tickets')->where('id', $id)->update(['boarded_at' => null, 'boarded_by' => null, 'updated_at' => now()]);
                } elseif ($b === $here && $ticket->alighted_at) {
                    DB::table('transport_tickets')->where('id', $id)->update(['alighted_at' => null, 'alighted_by' => null, 'updated_at' => now()]);
                }
            });
        }

        return response()->json($this->detail($cid, $this->tripHere($cid, (int) $trip->id, $agency), $agency));
    }

    /** POST transport/stop/trips/{id}/sell -- a last-minute passenger: the ticket and its sale on the agent's register, one transaction. */
    public function sell(Request $request, int $id)
    {
        $cid = $this->company($request);
        $agency = $this->agency($request, $cid);
        $v = $request->validate([
            'to_location_id' => 'required|integer', 'seat_number' => 'nullable|integer|min:1|max:200', 'doc_type' => 'nullable|in:'.implode(',', array_keys(TransportTickets::DOCS)),
            'dni' => 'required|string|max:20', 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'passenger_type' => 'nullable|in:'.implode(',', TransportTickets::PASSENGER_TYPES), 'luggage' => 'nullable|boolean',
            'price' => 'required|numeric|min:0|max:100000', 'payment_method_id' => 'nullable|integer',
        ]);
        $docType = $v['doc_type'] ?? 'dni';
        $dni = TransportTickets::docNumber($docType, $v['dni']);
        if (! preg_match(TransportTickets::DOCS[$docType], $dni)) {
            throw ValidationException::withMessages(['dni' => __('A valid document number (DNI = 8 digits).')]);
        }
        $this->tripHere($cid, $id, $agency);
        $uid = (int) $request->user('api')->id;

        $ticketId = DB::transaction(function () use ($cid, $id, $agency, $v, $docType, $dni, $uid) {
            DB::table('transport_trips')->where('id', $id)->lockForUpdate()->first();
            $trip = $this->trips($cid)->where('t.id', $id)->first();
            $points = TransportControl::points($trip);
            if ($why = $this->sellable($trip, $this->stopInfo($trip, $points, (int) $agency->id, null))) {
                abort(422, $why);
            }
            $stretch = TransportLegs::stretch((int) $trip->route_id, (int) $agency->id, (int) $v['to_location_id']);
            if (! $stretch) {
                throw ValidationException::withMessages(['to_location_id' => __('This bus does not go from here to that destination.')]);
            }
            $map = TransportLegs::seatMap($trip);
            $seat = null;
            $vip = false;
            if ($map) {
                $seat = (int) ($v['seat_number'] ?? 0);
                if (! $seat) {
                    throw ValidationException::withMessages(['seat_number' => __('Choose a seat.')]);
                }
                // A VIP seat is a VIP ticket (its product, when the company has one); the agent charges what was agreed.
                $vip = (bool) ($map[$seat]['vip'] ?? false);
                $taken = TransportLegs::takenSeats((int) $trip->id, (int) $trip->route_id, $stretch[0], $stretch[1]);
                if ($error = TransportLegs::seatError($map, $taken, $seat, $vip)) {
                    throw ValidationException::withMessages(['seat_number' => __($error)]);
                }
            }
            // The stretch's own ticket product (its route from this agency), else the trip's.
            $routeId = DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->where('origin_id', $agency->id)->where('destination_id', (int) $v['to_location_id'])->value('id');
            $codes = $routeId ? ['PAS-'.$routeId.($vip ? '-VIP' : ''), 'PAS-'.$routeId] : [];
            $codes[] = 'PAS-'.$trip->route_id;
            $product = null;
            foreach ($codes as $code) {
                $product = DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->where('code', $code)->first(['id', 'code', 'unit_sale_id']);
                if ($product) {
                    break;
                }
            }
            abort_unless($product, 422, __('The company has no ticket product for this route.'));
            $first = mb_substr(trim(strip_tags($v['first_name'])), 0, 100);
            $last = mb_substr(trim(strip_tags($v['last_name'])), 0, 100);
            $to = DB::table('transport_locations')->where('id', (int) $v['to_location_id'])->value('name');
            $sale = TransportTicketSale::create($cid, (int) $agency->warehouse_id, $uid, $product, round((float) $v['price'], 2),
                isset($v['payment_method_id']) ? (int) $v['payment_method_id'] : (DB::table('payment_methods')->whereNull('deleted_at')->where('name', 'Cash')->value('id') ?: null),
                'Pasaje '.$agency->name.' → '.$to.' · '.$last.', '.$first);

            return DB::table('transport_tickets')->insertGetId([
                'company_id' => $cid, 'sale_id' => $sale->id, 'product_id' => $product->id, 'route_id' => $routeId ?: $trip->route_id, 'trip_id' => $trip->id,
                'warehouse_id' => $agency->warehouse_id, 'seat_number' => $seat, 'is_vip' => $vip,
                'from_location_id' => $agency->id, 'to_location_id' => (int) $v['to_location_id'], 'code' => TransportLegs::ticketCode(), 'loyalty_free' => 0,
                'doc_type' => $docType, 'passenger_type' => $v['passenger_type'] ?? 'adult', 'dni' => $dni, 'first_name' => $first, 'last_name' => $last,
                'luggage' => ! empty($v['luggage']), 'price' => round((float) $v['price'], 2), 'channel' => 'agency',
                // Sold at the door of the bus: on board at once.
                'boarded_at' => now(), 'boarded_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['ticket_id' => $ticketId] + $this->detail($cid, $this->tripHere($cid, $id, $agency), $agency));
    }

    // ------------------------------------------------------------------ parcels

    public function parcel(Request $request, int $id)
    {
        $cid = $this->company($request);
        $agency = $this->agency($request, $cid);
        $v = $request->validate(['action' => 'required|in:load,unload']);
        $parcel = DB::table('transport_parcels')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->first();
        abort_unless($parcel && $parcel->trip_id, 404);
        $trip = $this->tripHere($cid, (int) $parcel->trip_id, $agency);
        $uid = (int) $request->user('api')->id;
        if ($v['action'] === 'load') {
            abort_unless((int) $parcel->from_location_id === (int) $agency->id, 422, __('This parcel is not loaded here.'));
            abort_unless($parcel->status === 'received', 422, __('This parcel is already on the bus.'));
            DB::transaction(function () use ($id, $uid) {
                DB::table('transport_parcels')->where('id', $id)->update(['status' => 'on_board', 'updated_at' => now()]);
                DB::table('transport_parcel_events')->insert(['parcel_id' => $id, 'status' => 'on_board', 'user_id' => $uid, 'created_at' => now()]);
            });
        } else {
            abort_unless((int) $parcel->to_location_id === (int) $agency->id, 422, __('This parcel is not unloaded here.'));
            abort_if(in_array($parcel->status, ['delivered', 'cancelled'], true) || DB::table('transport_parcel_events')->where('parcel_id', $id)->where('status', 'unloaded')->exists(),
                422, __('This parcel was already unloaded.'));
            // It stays "on_board" until the receiver picks it up (Parcels -> Delivered); the event says it is at the agency.
            DB::table('transport_parcel_events')->insert(['parcel_id' => $id, 'status' => 'unloaded', 'user_id' => $uid, 'created_at' => now()]);
        }

        return response()->json($this->detail($cid, $this->tripHere($cid, (int) $trip->id, $agency), $agency));
    }

    // ------------------------------------------------------------------ the bus

    public function arrive(Request $request, int $id)
    {
        return $this->markHere($request, $id, 'arrive');
    }

    public function depart(Request $request, int $id)
    {
        return $this->markHere($request, $id, 'depart');
    }

    private function markHere(Request $request, int $id, string $action)
    {
        $cid = $this->company($request);
        $agency = $this->agency($request, $cid);
        $this->tripHere($cid, $id, $agency);
        if ($error = TransportControl::mark($id, (int) $agency->id, $action, 'agency', (int) $request->user('api')->id, [], null, null, true)) {
            abort(422, __($error));
        }

        return response()->json($this->detail($cid, $this->tripHere($cid, $id, $agency), $agency));
    }
}
