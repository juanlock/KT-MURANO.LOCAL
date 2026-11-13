<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\CompanyFiles;
use App\Support\CompanyMail;
use App\Support\ModuleFlags;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily departures (Turismo Cautivo, user's yes 2026-11-10): the agency's day in place of the client's Excel.
 * The supervisor plans the day (copied from an earlier day: bus, time, boarding points), the POS tickets fill
 * passengers and amount (or they are typed when the agency sold outside Murano), toll and fuel are added, and
 * "Close day" keeps the figures (transport_day_closures), makes the PDF (private) and emails it to the report's
 * list. A closed day is locked; transport_departures_reopen opens it again, on record.
 * Each user works on the agencies of their locations (warehouseScopeIds; a Manager has all of them).
 *
 *  GET    transport/daily?date&agency_id          the agency's day
 *  POST   transport/daily/trips                    a new departure     PUT transport/daily/trips/{id}   DELETE transport/daily/trips/{id}
 *  POST   transport/daily/copy {date, agency_id, from}
 *  POST   transport/daily/price {date, agency_id, high, low}
 *  POST   transport/daily/emails {emails}
 *  POST   transport/daily/close {date, agency_id}   POST transport/daily/reopen {date, agency_id, note}
 *  GET    transport/daily/history?from&to&agency_id GET transport/daily/pdf/{id}
 */
class TransportDailyController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    private function allow(Request $request, string ...$perms): void
    {
        $user = $request->user('api');
        foreach ($perms as $perm) {
            if (HelpdeskAccess::can($user, $perm)) {
                return;
            }
        }
        abort(403, __('You do not have permission for this.'));
    }

    private function can(Request $request, string $perm): bool
    {
        return HelpdeskAccess::can($request->user('api'), $perm);
    }

    private function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    /** The agencies of the user's locations. */
    private function agencies(int $cid)
    {
        $scope = $this->warehouseScopeIds();

        return DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->where('active', 1)
            ->when($scope !== null, fn ($q) => $q->whereIn('warehouse_id', $scope))->orderBy('name')->get(['id', 'name']);
    }

    private function agency(Request $request, int $cid): object
    {
        $agencies = $this->agencies($cid);
        $id = (int) $request->input('agency_id');
        $a = $id ? $agencies->firstWhere('id', $id) : $agencies->first();
        abort_unless($a, $id ? 403 : 404, $id ? 'This agency is not one of your locations.' : 'There are no agencies.');

        return $a;
    }

    private function day(Request $request): string
    {
        $d = (string) ($request->input('date') ?: now()->toDateString());
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $d), 422, 'Wrong date.');

        return $d;
    }

    private function closure(int $cid, int $agencyId, string $day): ?object
    {
        return DB::table('transport_day_closures')->where('company_id', $cid)->where('location_id', $agencyId)->where('day', $day)->first();
    }

    private function abortIfClosed(int $cid, int $agencyId, string $day): void
    {
        $c = $this->closure($cid, $agencyId, $day);
        abort_if($c && $c->status === 'closed', 423, 'This day is closed. A Manager can reopen it.');
    }

    /** The agency's departures of the day with everything the screen and the PDF show. */
    private function rows(int $cid, int $agencyId, string $day, ?string $to = null): array
    {
        $trips = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as d', 'd.id', '=', 'r.destination_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')->where('r.origin_id', $agencyId)
            ->when($to, fn ($q) => $q->whereBetween('t.departure_at', [$day.' 00:00:00', $to.' 23:59:59']), fn ($q) => $q->whereDate('t.departure_at', $day))
            ->orderBy('t.departure_at')
            ->get(['t.id', 't.route_id', 't.vehicle_id', 't.departure_at', 't.status', 't.confirmed_at', 't.fuel_cost', 't.reported_passengers', 't.reported_income',
                'd.name as destination', 'v.name as bus', 'v.plate_number', 'v.seats']);
        $ids = $trips->pluck('id')->all();
        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $ids)->selectRaw('trip_id, COUNT(*) n, SUM(price) income')->groupBy('trip_id')->get()->keyBy('trip_id');
        $tolls = DB::table('transport_tolls')->whereIn('trip_id', $ids)->whereNull('deleted_at')->selectRaw('trip_id, SUM(amount) s')->groupBy('trip_id')->pluck('s', 'trip_id');
        $boardings = DB::table('transport_trip_boardings as b')->join('transport_locations as l', 'l.id', '=', 'b.location_id')->whereIn('b.trip_id', $ids)
            ->orderBy('b.seq')->get(['b.trip_id', 'b.location_id', 'l.name', 'b.label', 'b.board_at'])->groupBy('trip_id');
        $crew = DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->whereIn('c.trip_id', $ids)
            ->orderBy('c.role')->get(['c.trip_id', 'c.role', 'e.firstname', 'e.lastname'])->groupBy('trip_id');

        return $trips->map(function ($t) use ($tickets, $tolls, $boardings, $crew) {
            $x = $tickets[$t->id] ?? null;
            $passengers = $t->reported_passengers !== null ? (int) $t->reported_passengers : (int) ($x->n ?? 0);
            $amount = $t->reported_income !== null ? (float) $t->reported_income : (float) ($x->income ?? 0);
            $seats = $t->seats ? (int) $t->seats : null;

            return [
                'id' => (int) $t->id, 'route_id' => (int) $t->route_id, 'vehicle_id' => $t->vehicle_id ? (int) $t->vehicle_id : null,
                'time' => Carbon::parse($t->departure_at)->format('H:i'), 'departure_at' => $t->departure_at, 'date' => substr((string) $t->departure_at, 0, 10),
                'destination' => $t->destination, 'bus' => trim(($t->bus ?? '').($t->plate_number ? ' ('.$t->plate_number.')' : '')), 'seats' => $seats,
                'status' => $t->status, 'confirmed' => (bool) $t->confirmed_at,
                'points' => collect($boardings[$t->id] ?? [])->map(fn ($b) => [
                    'location_id' => (int) $b->location_id, 'name' => $b->name, 'label' => $b->label, 'time' => Carbon::parse($b->board_at)->format('H:i'),
                ])->values()->all(),
                'pos_passengers' => (int) ($x->n ?? 0), 'pos_amount' => round((float) ($x->income ?? 0), 2),
                'reported_passengers' => $t->reported_passengers !== null ? (int) $t->reported_passengers : null,
                'reported_income' => $t->reported_income !== null ? round((float) $t->reported_income, 2) : null,
                'passengers' => $passengers, 'amount' => round($amount, 2),
                'occupancy' => $seats ? (int) round(100 * $passengers / $seats) : null,
                'toll' => isset($tolls[$t->id]) ? round((float) $tolls[$t->id], 2) : null,
                'fuel' => $t->fuel_cost !== null ? round((float) $t->fuel_cost, 2) : null,
                'crew' => collect($crew[$t->id] ?? [])->map(fn ($c) => trim($c->firstname.' '.$c->lastname))->values()->all(),
            ];
        })->all();
    }

    private function totals(array $rows): array
    {
        $live = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'cancelled'));
        $seats = array_sum(array_map(fn ($r) => (int) $r['seats'], $live));
        $passengers = array_sum(array_column($live, 'passengers'));
        $amount = array_sum(array_column($live, 'amount'));
        $tolls = array_sum(array_map(fn ($r) => (float) $r['toll'], $live));
        $fuel = array_sum(array_map(fn ($r) => (float) $r['fuel'], $live));

        return [
            'departures' => count($live), 'cancelled' => count($rows) - count($live), 'passengers' => $passengers, 'seats' => $seats,
            'occupancy' => $seats ? (int) round(100 * $passengers / $seats) : null,
            'amount' => round($amount, 2), 'tolls' => round($tolls, 2), 'fuel' => round($fuel, 2), 'net' => round($amount - $tolls - $fuel, 2),
            'missing_toll' => count(array_filter($live, fn ($r) => $r['toll'] === null)), 'missing_fuel' => count(array_filter($live, fn ($r) => $r['fuel'] === null)),
        ];
    }

    private function closureOut(?object $c): ?array
    {
        if (! $c) {
            return null;
        }
        $names = DB::table('users')->whereIn('id', array_filter([$c->closed_by, $c->reopened_by]))->pluck('username', 'id');

        return [
            'id' => (int) $c->id, 'status' => $c->status, 'closed_at' => $c->closed_at, 'closed_by' => $names[$c->closed_by] ?? null,
            'reopened_at' => $c->reopened_at, 'reopened_by' => $names[$c->reopened_by] ?? null, 'reopen_note' => $c->reopen_note,
            'has_pdf' => (bool) $c->pdf, 'emailed_to' => $c->emailed_to,
        ];
    }

    // ------------------------------------------------------------------ the day

    public function show(Request $request)
    {
        $this->allow($request, 'transport_departures_view', 'transport_departures_manage');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agencies = $this->agencies($cid);
        if ($agencies->isEmpty()) {
            return response()->json(['agencies' => [], 'rows' => []]);
        }
        // Every agency of the user's locations at once (user's request, 2026-11-11): read the whole day, each row
        // with its agency; planning, copying, closing and the price stay per agency.
        // History (user's request, 2026-11-11, replacing the "Closed days" drawer): every departure of the last 60 days and
        // the week ahead, newest first, for one agency or all of them; each row says whether its day is closed.
        if ($request->boolean('history')) {
            return response()->json($this->historyRows($request, $cid, $agencies));
        }
        // (A login with one agency simply gets its agency.)
        if ($request->input('agency_id') === 'all' && $agencies->count() > 1) {
            return response()->json($this->allAgencies($request, $cid, $day, $agencies));
        }
        $agency = $this->agency($request, $cid);
        $rows = $this->rows($cid, (int) $agency->id, $day);
        $price =DB::table('transport_day_prices')->where('location_id', $agency->id)->whereDate('day', $day)->first();
        $copyFrom = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->where('t.company_id', $cid)->whereNull('t.deleted_at')
            ->where('t.status', '!=', 'cancelled')->where('r.origin_id', $agency->id)->whereDate('t.departure_at', '<', $day)->max('t.departure_at');
        $manage = $this->can($request, 'transport_departures_manage');

        return response()->json([
            'date' => $day, 'agency_id' => (int) $agency->id, 'agency' => $agency->name,
            'agencies' => $agencies->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])->values(),
            'can_manage' => $manage, 'can_reopen' => $this->can($request, 'transport_departures_reopen'),
            'closure' => $this->closureOut($this->closure($cid, (int) $agency->id, $day)),
            'price' => $price ? ['high' => (float) $price->price_high, 'low' => (float) $price->price_low] : null,
            'emails' => $manage ? (string) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('transport_daily_emails') : null,
            'copy_from' => $copyFrom ? substr((string) $copyFrom, 0, 10) : null,
            'rows' => $rows, 'totals' => $this->totals($rows),
            'routes' => DB::table('transport_routes as r')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')->where('r.company_id', $cid)
                ->where('r.origin_id', $agency->id)->whereNull('r.deleted_at')->where('r.active', 1)->orderBy('d.name')
                ->get(['r.id', 'd.name as destination', 'r.duration_min'])->map(fn ($r) => ['id' => (int) $r->id, 'destination' => $r->destination, 'minutes' => (int) $r->duration_min])->values(),
            'vehicles' => DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->where(fn ($q) => $q->where('type', 'bus')->orWhereNotNull('seats'))
                ->orderBy('name')->get(['id', 'name', 'plate_number', 'seats'])
                ->map(fn ($v) => ['id' => (int) $v->id, 'name' => trim($v->name.($v->plate_number ? ' ('.$v->plate_number.')' : '')), 'seats' => $v->seats ? (int) $v->seats : null])->values(),
            // Boarding points: the agency and its terminals first.
            'points' => DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('active', 1)->orderBy('name')
                ->get(['id', 'name', 'hub_id'])->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'own' => (int) $l->id === (int) $agency->id || (int) $l->hub_id === (int) $agency->id])->values(),
        ]);
    }

    /** Every departure from 60 days back to a week ahead (one agency or all), newest first, with its day's closure. */
    private function historyRows(Request $request, int $cid, $agencies): array
    {
        $from = now()->subDays(60)->toDateString();
        $to = now()->addDays(7)->toDateString();
        $all = $request->input('agency_id') === 'all' && $agencies->count() > 1;
        $list = $all ? $agencies : collect([$this->agency($request, $cid)]);
        $closures = DB::table('transport_day_closures')->where('company_id', $cid)->whereIn('location_id', $list->pluck('id'))
            ->whereBetween('day', [$from, $to])->get(['id', 'location_id', 'day', 'status', 'pdf'])
            ->keyBy(fn ($c) => $c->location_id.'@'.substr((string) $c->day, 0, 10));
        $rows = [];
        foreach ($list as $a) {
            foreach ($this->rows($cid, (int) $a->id, $from, $to) as $r) {
                $c = $closures[$a->id.'@'.$r['date']] ?? null;
                $rows[] = $r + ['agency_id' => (int) $a->id, 'agency' => $a->name, 'closed' => $c && $c->status === 'closed',
                    'closure_id' => $c && $c->status === 'closed' && $c->pdf ? (int) $c->id : null];
            }
        }
        usort($rows, fn ($x, $y) => strcmp((string) $y['departure_at'], (string) $x['departure_at']) ?: strcmp($x['agency'], $y['agency']));
        $one = $all ? null : $list->first();

        return [
            'history' => true, 'from' => $from, 'to' => $to, 'date' => $this->day($request),
            'agency_id' => $all ? 'all' : (int) $one->id, 'agency' => $one->name ?? null, 'all' => $all,
            'agencies' => $agencies->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])->values(),
            'can_manage' => $this->can($request, 'transport_departures_manage'), 'can_reopen' => $this->can($request, 'transport_departures_reopen'),
            'closure' => null, 'price' => null, 'copy_from' => null, 'emails' => null,
            'rows' => $rows, 'totals' => $this->totals($rows),
        ];
    }

    /** The day of every agency the user has: all the departures (by time), the totals and each agency's state. */
    private function allAgencies(Request $request, int $cid, string $day, $agencies): array
    {
        $rows = [];
        $summary = [];
        foreach ($agencies as $a) {
            $mine = $this->rows($cid, (int) $a->id, $day);
            $c = $this->closure($cid, (int) $a->id, $day);
            $closed = $c && $c->status === 'closed';
            foreach ($mine as $r) {
                $rows[] = $r + ['agency_id' => (int) $a->id, 'agency' => $a->name, 'closed' => $closed];
            }
            $tot = $this->totals($mine);
            $summary[] = ['id' => (int) $a->id, 'name' => $a->name, 'departures' => $tot['departures'], 'passengers' => $tot['passengers'],
                'amount' => $tot['amount'], 'state' => $closed ? 'closed' : ($c ? 'reopened' : 'open'), 'closure_id' => $c ? (int) $c->id : null,
                'has_pdf' => $c ? (bool) $c->pdf : false];
        }
        usort($rows, fn ($x, $y) => strcmp((string) $x['departure_at'], (string) $y['departure_at']) ?: strcmp($x['agency'], $y['agency']));

        return [
            'date' => $day, 'agency_id' => 'all', 'agency' => null, 'all' => true,
            'agencies' => $agencies->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name])->values(),
            'summary' => $summary,
            'can_manage' => $this->can($request, 'transport_departures_manage'), 'can_reopen' => $this->can($request, 'transport_departures_reopen'),
            'closure' => null, 'price' => null, 'copy_from' => null, 'emails' => null,
            'rows' => $rows, 'totals' => $this->totals($rows),
        ];
    }

    /** A departure of the agency (new or changed): time, route, bus, boarding points and the day's figures. */
    public function save(Request $request, ?int $id = null)
    {
        $this->allow($request, 'transport_departures_manage');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agency = $this->agency($request, $cid);
        $this->abortIfClosed($cid, (int) $agency->id, $day);
        $v = $request->validate([
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'route_id' => 'required|integer',
            'vehicle_id' => 'nullable|integer',
            'points' => 'array|max:12',
            'points.*.location_id' => 'required|integer',
            'points.*.label' => 'nullable|string|max:40',
            'points.*.time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'reported_passengers' => 'nullable|integer|min:0|max:500',
            'reported_income' => 'nullable|numeric|min:0|max:9999999',
            'toll' => 'nullable|numeric|min:0|max:999999',
            'fuel' => 'nullable|numeric|min:0|max:999999',
        ]);
        $route = DB::table('transport_routes')->where('id', $v['route_id'])->where('company_id', $cid)->where('origin_id', $agency->id)->whereNull('deleted_at')->first();
        if (! $route) {
            throw ValidationException::withMessages(['route_id' => 'Pick a route that leaves from this agency.']);
        }
        $vehicle = null;
        if (! empty($v['vehicle_id'])) {
            $vehicle = DB::table('vehicles')->where('id', $v['vehicle_id'])->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'seats']);
            if (! $vehicle) {
                throw ValidationException::withMessages(['vehicle_id' => 'Pick one of the company\'s buses.']);
            }
            if (isset($v['reported_passengers']) && $vehicle->seats && $v['reported_passengers'] > (int) $vehicle->seats) {
                throw ValidationException::withMessages(['reported_passengers' => 'More passengers than the bus\'s seats.']);
            }
        }
        $points = $v['points'] ?? [];
        $own = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('id', array_column($points, 'location_id'))->pluck('id')->map(fn ($x) => (int) $x)->all();
        foreach ($points as $i => $p) {
            if (! in_array((int) $p['location_id'], $own, true)) {
                throw ValidationException::withMessages(["points.$i.location_id" => 'Unknown boarding point.']);
            }
        }

        $departure = Carbon::parse($day.' '.$v['time']);
        $old = $id ? DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->where('t.id', $id)->where('t.company_id', $cid)
            ->whereNull('t.deleted_at')->where('r.origin_id', $agency->id)->first(['t.*']) : null;
        abort_if($id && ! $old, 404);
        if ($old && substr((string) $old->departure_at, 0, 10) !== $day) {
            abort(422, __('This departure is on another day.'));
        }
        $userId = $request->user('api')->id;

        $tripId = DB::transaction(function () use ($id, $old, $cid, $route, $vehicle, $departure, $points, $v, $userId) {
            $set = [
                'route_id' => $route->id, 'vehicle_id' => $vehicle->id ?? null, 'departure_at' => $departure,
                'arrival_at' => $departure->copy()->addMinutes((int) ($route->duration_min ?: 240)),
                'reported_passengers' => $v['reported_passengers'] ?? null, 'reported_income' => $v['reported_income'] ?? null,
                'fuel_cost' => $v['fuel'] ?? null, 'updated_at' => now(),
            ];
            if ($old) {
                DB::table('transport_trips')->where('id', $id)->update($set);
                $tripId = $id;
            } else {
                $tripId = DB::table('transport_trips')->insertGetId($set + ['company_id' => $cid, 'status' => 'scheduled', 'created_by' => $userId, 'created_at' => now()]);
            }
            DB::table('transport_trip_boardings')->where('trip_id', $tripId)->delete();
            foreach (array_values($points) as $seq => $p) {
                $at = Carbon::parse($departure->toDateString().' '.$p['time']);
                if ($at->lt($departure->copy()->subHours(2))) {
                    $at->addDay(); // a boarding after midnight
                }
                DB::table('transport_trip_boardings')->insert([
                    'company_id' => $cid, 'trip_id' => $tripId, 'location_id' => (int) $p['location_id'], 'label' => (trim(strip_tags((string) ($p['label'] ?? ''))) ?: null),
                    'seq' => $seq + 1, 'board_at' => $at, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->setToll($cid, $tripId, $v['toll'] ?? null, $departure->toDateString(), $userId);

            return $tripId;
        });

        return response()->json(['success' => true, 'id' => (int) $tripId]);
    }

    /**
     * The trip's toll as one figure: this screen keeps its own row ("Reporte diario"); rows from Transport -> Tolls stay
     * and count, so the figure can't be lower than them.
     */
    private function setToll(int $cid, int $tripId, $amount, string $day, int $userId): void
    {
        $mine = DB::table('transport_tolls')->where('trip_id', $tripId)->whereNull('deleted_at')->where('place', 'Reporte diario');
        $others = (float) DB::table('transport_tolls')->where('trip_id', $tripId)->whereNull('deleted_at')->where('place', '!=', 'Reporte diario')->sum('amount');
        if ($amount === null || $amount === '') {
            (clone $mine)->update(['deleted_at' => now()]);

            return;
        }
        if ((float) $amount < $others) {
            throw ValidationException::withMessages(['toll' => 'Tolls already registered for this trip add up to '.number_format($others, 2).'.']);
        }
        $rest = round((float) $amount - $others, 2);
        if ((clone $mine)->exists()) {
            (clone $mine)->update(['amount' => $rest, 'paid_on' => $day, 'updated_at' => now()]);
        } elseif ($rest > 0 || $others == 0) {
            DB::table('transport_tolls')->insert(['company_id' => $cid, 'trip_id' => $tripId, 'place' => 'Reporte diario', 'amount' => $rest, 'paid_on' => $day,
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Removes a departure planned by mistake; one with tickets sold is cancelled from Today's departures instead. */
    public function destroy(Request $request, int $id)
    {
        $this->allow($request, 'transport_departures_manage');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agency = $this->agency($request, $cid);
        $this->abortIfClosed($cid, (int) $agency->id, $day);
        $trip = DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->where('t.id', $id)->where('t.company_id', $cid)
            ->whereNull('t.deleted_at')->where('r.origin_id', $agency->id)->first(['t.id', 't.status']);
        abort_unless($trip, 404);
        abort_if(DB::table('transport_tickets')->where('trip_id', $id)->exists(), 422, 'It has tickets sold: cancel it from Today\'s departures.');
        abort_if(DB::table('transport_parcels')->where('trip_id', $id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')->exists(), 422, 'It carries parcels: cancel it from Today\'s departures.');
        abort_if(in_array($trip->status, ['in_progress', 'completed'], true), 422, 'This departure already left.');
        DB::transaction(function () use ($id) {
            DB::table('transport_trips')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            DB::table('transport_tolls')->where('trip_id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    /** The day planned from an earlier one: same times, routes, buses and boarding points (those already there are skipped). */
    public function copy(Request $request)
    {
        $this->allow($request, 'transport_departures_manage');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agency = $this->agency($request, $cid);
        $this->abortIfClosed($cid, (int) $agency->id, $day);
        $from = (string) $request->input('from');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && $from !== $day, 422, 'Pick the day to copy from.');
        $q = fn (string $d) => DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->where('t.company_id', $cid)
            ->whereNull('t.deleted_at')->where('t.status', '!=', 'cancelled')->where('r.origin_id', $agency->id)->whereDate('t.departure_at', $d);
        $source = $q($from)->orderBy('t.departure_at')->get(['t.id', 't.route_id', 't.vehicle_id', 't.departure_at', 't.arrival_at', 'r.duration_min']);
        abort_if($source->isEmpty(), 422, 'That day has no departures.');
        $there = $q($day)->get(['t.route_id', 't.departure_at'])->map(fn ($t) => $t->route_id.'@'.substr((string) $t->departure_at, 11, 5))->all();
        $boardings = DB::table('transport_trip_boardings')->whereIn('trip_id', $source->pluck('id'))->orderBy('seq')->get()->groupBy('trip_id');
        $userId = $request->user('api')->id;
        $made = 0;
        DB::transaction(function () use ($source, $there, $boardings, $day, $cid, $userId, &$made) {
            foreach ($source as $t) {
                $time = substr((string) $t->departure_at, 11, 5);
                if (in_array($t->route_id.'@'.$time, $there, true)) {
                    continue;
                }
                $old = Carbon::parse($t->departure_at);
                $new = Carbon::parse($day.' '.$time);
                $minutes = $t->arrival_at ? $old->diffInMinutes(Carbon::parse($t->arrival_at)) : (int) ($t->duration_min ?: 240);
                $id = DB::table('transport_trips')->insertGetId([
                    'company_id' => $cid, 'route_id' => $t->route_id, 'vehicle_id' => $t->vehicle_id, 'departure_at' => $new, 'arrival_at' => $new->copy()->addMinutes($minutes),
                    'status' => 'scheduled', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($boardings[$t->id] ?? [] as $b) {
                    DB::table('transport_trip_boardings')->insert([
                        'company_id' => $cid, 'trip_id' => $id, 'location_id' => $b->location_id, 'label' => $b->label, 'seq' => $b->seq,
                        'board_at' => $new->copy()->addMinutes((int) round(($b->board_at ? Carbon::parse($b->board_at)->getTimestamp() - $old->getTimestamp() : 0) / 60)),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $made++;
            }
        });

        return response()->json(['success' => true, 'made' => $made]);
    }

    public function price(Request $request)
    {
        $this->allow($request, 'transport_departures_manage');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agency = $this->agency($request, $cid);
        $this->abortIfClosed($cid, (int) $agency->id, $day);
        $v = $request->validate(['high' => 'nullable|numeric|min:0|max:99999', 'low' => 'nullable|numeric|min:0|max:99999']);
        DB::table('transport_day_prices')->updateOrInsert(['location_id' => $agency->id, 'day' => $day],
            ['company_id' => $cid, 'price_high' => $v['high'] ?? 0, 'price_low' => $v['low'] ?? 0, 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function emails(Request $request)
    {
        $this->allow($request, 'transport_departures_manage');
        $cid = $this->company($request);
        $list = collect(preg_split('/[\s,;]+/', (string) $request->input('emails'), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($e) => strtolower(trim($e)))->unique()->values();
        foreach ($list as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['emails' => "Not an email: {$e}"]);
            }
        }
        abort_if($list->count() > 15, 422, 'At most 15 emails.');
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['transport_daily_emails' => $list->implode(', ') ?: null]);

        return response()->json(['success' => true, 'emails' => $list->implode(', ')]);
    }

    // ------------------------------------------------------------------ close / reopen / history

    public function close(Request $request)
    {
        $this->allow($request, 'transport_departures_manage');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agency = $this->agency($request, $cid);
        $this->abortIfClosed($cid, (int) $agency->id, $day);
        abort_if($day > now()->toDateString(), 422, 'A day can be closed only once it has started.');
        $rows = $this->rows($cid, (int) $agency->id, $day);
        abort_if(! $rows, 422, 'There are no departures to close.');
        $totals = $this->totals($rows);
        $userId = $request->user('api')->id;

        $closureId = DB::transaction(function () use ($cid, $agency, $day, $rows, $totals, $userId) {
            $ids = array_column(array_filter($rows, fn ($r) => $r['status'] !== 'cancelled' && ! $r['confirmed']), 'id');
            if ($ids) {
                DB::table('transport_trips')->whereIn('id', $ids)->update(['confirmed_at' => now(), 'confirmed_by' => $userId, 'updated_at' => now()]);
            }
            $set = ['status' => 'closed', 'closed_by' => $userId, 'closed_at' => now(), 'totals' => json_encode($totals),
                'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'pdf' => null, 'emailed_to' => null, 'updated_at' => now()];
            $c = $this->closure($cid, (int) $agency->id, $day);
            if ($c) {
                DB::table('transport_day_closures')->where('id', $c->id)->update($set);

                return (int) $c->id;
            }

            return DB::table('transport_day_closures')->insertGetId($set + ['company_id' => $cid, 'location_id' => $agency->id, 'day' => $day, 'created_at' => now()]);
        });

        // The PDF and the email after the day is safely closed: a mail failure never reopens it.
        $pdf = $this->makePdf($closureId);
        $sent = $pdf ? $this->sendPdf($cid, $closureId, $pdf, $agency->name, $day) : [];

        return response()->json(['success' => true, 'pdf' => (bool) $pdf, 'emailed_to' => $sent]);
    }

    public function reopen(Request $request)
    {
        $this->allow($request, 'transport_departures_reopen');
        $cid = $this->company($request);
        $day = $this->day($request);
        $agency = $this->agency($request, $cid);
        $note = trim(strip_tags((string) $request->input('note')));
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Write the reason.']);
        }
        $c = $this->closure($cid, (int) $agency->id, $day);
        abort_unless($c && $c->status === 'closed', 422, 'This day is not closed.');
        DB::table('transport_day_closures')->where('id', $c->id)->update(['status' => 'open', 'reopened_by' => $request->user('api')->id, 'reopened_at' => now(),
            'reopen_note' => mb_substr($note, 0, 500), 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function history(Request $request)
    {
        $this->allow($request, 'transport_departures_view', 'transport_departures_manage');
        $cid = $this->company($request);
        $agencies = $this->agencies($cid);
        $from = $request->input('from') ?: now()->subDays(60)->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        $rows = DB::table('transport_day_closures as c')->leftJoin('users as u', 'u.id', '=', 'c.closed_by')->where('c.company_id', $cid)
            ->whereIn('c.location_id', $agencies->pluck('id'))->whereBetween('c.day', [$from, $to])
            ->when($request->filled('agency_id'), fn ($q) => $q->where('c.location_id', (int) $request->input('agency_id')))
            ->orderByDesc('c.day')->get(['c.id', 'c.location_id', 'c.day', 'c.status', 'c.closed_at', 'c.totals', 'c.pdf', 'c.emailed_to', 'u.username']);
        $names = $agencies->pluck('name', 'id');

        return response()->json(['rows' => $rows->map(fn ($c) => [
            'id' => (int) $c->id, 'agency_id' => (int) $c->location_id, 'agency' => $names[$c->location_id] ?? '', 'day' => substr((string) $c->day, 0, 10),
            'status' => $c->status, 'closed_at' => $c->closed_at, 'closed_by' => $c->username, 'totals' => json_decode((string) $c->totals, true),
            'has_pdf' => (bool) $c->pdf, 'emailed_to' => $c->emailed_to,
        ])->values()]);
    }

    public function pdf(Request $request, int $id)
    {
        $this->allow($request, 'transport_departures_view', 'transport_departures_manage');
        $cid = $this->company($request);
        $c = DB::table('transport_day_closures')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($c && $this->agencies($cid)->contains('id', $c->location_id), 404);
        if (! $c->pdf || ! CompanyFiles::owns($c->pdf, $cid, 'transport')) {
            $this->makePdf($id);
            $c = DB::table('transport_day_closures')->where('id', $id)->first();
        }
        abort_unless($c->pdf && CompanyFiles::owns($c->pdf, $cid, 'transport'), 404);

        return response()->file(public_path($c->pdf), ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($c->pdf).'"']);
    }

    // ------------------------------------------------------------------ PDF and email

    /** The closed day's PDF (from the figures kept at closing), private: files/company_{id}/transport/daily/. */
    private function makePdf(int $closureId): ?string
    {
        try {
            $c = DB::table('transport_day_closures')->where('id', $closureId)->first();
            $agency = (string) DB::table('transport_locations')->where('id', $c->location_id)->value('name');
            $company = (string) DB::table('companies')->where('id', $c->company_id)->value('name');
            $es = (string) DB::table('settings')->where('company_id', $c->company_id)->whereNull('deleted_at')->value('default_language') !== 'en';
            $price = DB::table('transport_day_prices')->where('location_id', $c->location_id)->whereDate('day', $c->day)->first();
            $html = view('pdf.transport_daily', [
                'es' => $es, 'company' => $company, 'agency' => $agency, 'day' => Carbon::parse($c->day), 'rows' => json_decode((string) $c->rows, true) ?: [],
                'totals' => json_decode((string) $c->totals, true) ?: [], 'price' => $price,
                'closedBy' => (string) DB::table('users')->where('id', $c->closed_by)->value('username'), 'closedAt' => $c->closed_at,
            ])->render();
            $bytes = \PDF::loadHTML($html)->setPaper('a4', 'landscape')->output();
            $name = 'salidas-'.\Illuminate\Support\Str::slug($agency).'-'.substr((string) $c->day, 0, 10).'.pdf';
            $old = $c->pdf;
            $rel = CompanyFiles::putBytes((int) $c->company_id, 'transport/daily', $name, $bytes, true);
            CompanyFiles::protect(public_path(CompanyFiles::dir((int) $c->company_id, 'transport', true)));
            DB::table('transport_day_closures')->where('id', $closureId)->update(['pdf' => $rel, 'updated_at' => now()]);
            if ($old && $old !== $rel && is_file(public_path($old))) {
                @unlink(public_path($old));
            }

            return $rel;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string> who got it */
    private function sendPdf(int $cid, int $closureId, string $rel, string $agency, string $day): array
    {
        $list = collect(preg_split('/[\s,;]+/', (string) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('transport_daily_emails'), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! CompanyMail::reserved($e))->values();
        if ($list->isEmpty()) {
            return [];
        }
        $c = DB::table('transport_day_closures')->where('id', $closureId)->first();
        $t = json_decode((string) $c->totals, true) ?: [];
        $es = (string) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('default_language') !== 'en';
        $L = fn ($en, $sp) => $es ? $sp : $en;
        $date = Carbon::parse($day)->format($es ? 'd-m-Y' : 'm-d-Y');
        $occ = $t['occupancy'] ?? null;
        $subject = $L('Departures report', 'Reporte de salidas')." {$agency} {$date}";
        $html = '<p>'.$L('Departures report of', 'Reporte de salidas de').' <b>'.e($agency).'</b>, '.e($date).':</p><ul>'
            .'<li>'.$L('Departures', 'Salidas').': '.(int) ($t['departures'] ?? 0).'</li>'
            .'<li>'.$L('Passengers', 'Pasajeros').': '.(int) ($t['passengers'] ?? 0).($occ !== null ? ' ('.$occ.' % '.$L('occupancy', 'de ocupación').')' : '').'</li>'
            .'<li>'.$L('Amount', 'Monto').': S/ '.number_format((float) ($t['amount'] ?? 0), 2).'</li>'
            .'<li>'.$L('Tolls', 'Peajes').': S/ '.number_format((float) ($t['tolls'] ?? 0), 2).'</li>'
            .'<li>'.$L('Fuel', 'Combustible').': S/ '.number_format((float) ($t['fuel'] ?? 0), 2).'</li></ul>'
            .'<p>'.$L('The detail is in the attached PDF.', 'El detalle va en el PDF adjunto.').'</p>';
        $sent = [];
        try {
            [$mailer] = CompanyMail::mailer($cid);
            foreach ($list as $to) {
                try {
                    $mailer->html($html, fn ($m) => $m->to($to)->subject($subject)->attach(public_path($rel), ['as' => basename($rel), 'mime' => 'application/pdf']));
                    $sent[] = $to;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        DB::table('transport_day_closures')->where('id', $closureId)->update(['emailed_to' => $sent ? implode(', ', $sent) : null, 'updated_at' => now()]);

        return $sent;
    }
}
