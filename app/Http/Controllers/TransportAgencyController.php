<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportClaims;
use App\Services\TransportControl;
use App\Support\ModuleFlags;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Mi agencia" (Turismo Cautivo, user's request 2026-11-11): what an agency's supervisor needs besides the day's
 * departures, for the agencies of their locations only (a Manager / IT Admin has every agency):
 *  - summary: departures, passengers, occupancy, ticket and parcel income, pending parcels, complaints and incidents,
 *    today and in the period, plus the passengers of the last 14 days. No profit or costs (that stays with the owner);
 *  - claims: the complaints book counted for their agencies and terminals (TransportClaims with places);
 *  - incidents: the reports on their trips (transport_trip_reports, made by the crew from My trip or here), and the
 *    trips they can report on (created with TransportControlController::report).
 * The parcels tab uses transport/parcels/board, which follows the same agencies.
 *
 *  GET transport/agency/summary?from&to   GET transport/agency/claims?from&to
 *  GET transport/agency/incidents?from&to GET transport/agency/incident-trips
 */
class TransportAgencyController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    private function company(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'transport_agency_view'), 403, 'You do not have permission for this.');
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    /** The user's agencies with their terminals: [agencies, every location id of them, every name of them]. */
    private function mine(int $cid): array
    {
        $scope = $this->warehouseScopeIds();
        $agencies = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')
            ->when($scope !== null, fn ($q) => $q->whereIn('warehouse_id', $scope ?: [0]))->orderBy('name')->get(['id', 'name', 'warehouse_id']);
        $ids = $agencies->pluck('id')->map(fn ($v) => (int) $v)->all();
        $places = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('id', $ids ?: [0])->orWhereIn('hub_id', $ids ?: [0]))->get(['id', 'name']);

        return [$agencies, $places->pluck('id')->map(fn ($v) => (int) $v)->all(), $places->pluck('name')->all(), $scope === null];
    }

    private function period(Request $request): array
    {
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = min($request->input('to') ?: now()->toDateString(), now()->toDateString());

        return [$from, $to];
    }

    /** The trips leaving from the user's agencies. */
    private function trips(int $cid, array $agencyIds)
    {
        return DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')->whereIn('r.origin_id', $agencyIds ?: [0]);
    }

    /** Departures, passengers, seats and ticket income of the trips between two moments. */
    private function figures(int $cid, array $agencyIds, string $from, string $to): array
    {
        $trips = $this->trips($cid, $agencyIds)->where('t.status', '!=', 'cancelled')->whereBetween('t.departure_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['t.id', 't.departure_at', 't.reported_passengers', 't.reported_income', 'v.seats']);
        $tickets = DB::table('transport_tickets')->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->selectRaw('trip_id, COUNT(*) n, SUM(price) s')->groupBy('trip_id')->get()->keyBy('trip_id');
        $pax = $seats = 0;
        $amount = 0.0;
        $byDay = [];
        foreach ($trips as $t) {
            $x = $tickets[$t->id] ?? null;
            $p = $t->reported_passengers !== null ? (int) $t->reported_passengers : (int) ($x->n ?? 0);
            $pax += $p;
            $seats += (int) $t->seats;
            $amount += $t->reported_income !== null ? (float) $t->reported_income : (float) ($x->s ?? 0);
            $d = substr((string) $t->departure_at, 0, 10);
            $byDay[$d] = ($byDay[$d] ?? 0) + $p;
        }

        return ['departures' => $trips->count(), 'passengers' => $pax, 'seats' => $seats, 'occupancy' => $seats ? (int) round(100 * $pax / $seats) : null,
            'tickets_amount' => round($amount, 2), 'by_day' => $byDay];
    }

    public function summary(Request $request)
    {
        $cid = $this->company($request);
        [$agencies, $placeIds, $names, $everything] = $this->mine($cid);
        [$from, $to] = $this->period($request);
        $agencyIds = $agencies->pluck('id')->map(fn ($v) => (int) $v)->all();
        $today = now()->toDateString();
        $warehouses = $agencies->pluck('warehouse_id')->filter()->map(fn ($v) => (int) $v)->all();

        $parcels = fn (string $a, string $b) => DB::table('transport_parcels')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereIn('warehouse_id', $warehouses ?: [0])->whereBetween('received_on', [$a, $b]);
        $pending = DB::table('transport_parcels')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('warehouse_id', $warehouses ?: [0])
            ->whereNotIn('status', ['delivered', 'cancelled'])->where('received_on', '<=', $today)->count();
        $incidents = fn (string $a, string $b) => DB::table('transport_trip_reports as x')->join('transport_trips as t', 't.id', '=', 'x.trip_id')
            ->join('transport_routes as r', 'r.id', '=', 't.route_id')->where('x.company_id', $cid)->whereIn('r.origin_id', $agencyIds ?: [0])
            ->whereBetween('x.created_at', [$a.' 00:00:00', $b.' 23:59:59']);
        $claims = ModuleFlags::enabled($cid, 'forms') ? TransportClaims::compute($cid, $from, $to, $everything ? null : $names) : [];

        $period = $this->figures($cid, $agencyIds, $from, $to);
        $day = $this->figures($cid, $agencyIds, $today, $today);
        $trend = $this->figures($cid, $agencyIds, now()->subDays(13)->toDateString(), $today)['by_day'];
        $points = [];
        for ($d = now()->subDays(13); $d->lte(now()); $d->addDay()) {
            $points[] = ['date' => $d->toDateString(), 'passengers' => $trend[$d->toDateString()] ?? 0];
        }
        unset($period['by_day'], $day['by_day']);

        // Per unit (user's request, 2026-11-11: "pasajeros mínimo, abordados por unidad"): each bus's departures from the agency
        // in the last 14 days, with the passengers who boarded (the boarding pass read, else the client's reported figure) --
        // the fewest, the average and the most per trip, against its seats.
        $since = now()->subDays(13)->startOfDay();
        $trips = $this->trips($cid, $agencyIds)->where('t.status', '!=', 'cancelled')->whereBetween('t.departure_at', [$since, now()])
            ->get(['t.id', 't.vehicle_id', 't.reported_passengers', 'v.name as bus', 'v.seats']);
        $boarded = DB::table('transport_tickets')->whereIn('trip_id', $trips->pluck('id')->all() ?: [0])->whereNotNull('boarded_at')
            ->selectRaw('trip_id, COUNT(*) n')->groupBy('trip_id')->pluck('n', 'trip_id');
        // The minimum required (user's request, 2026-11-11): the seats a trip needs to cover its costs -- the same break-even
        // the owner's Risks tab uses (TransportProfit) -- averaged per bus. The bar (seats used = boarded per trip) is green
        // from 110 % of it, orange from 100 %, red below (TransportIndicators::RISK coverage).
        $breakEven = collect(\App\Services\TransportProfit::compute($cid, $since->toDateString(), now()->toDateString(), $warehouses ?: [0])['routes'] ?? [])
            ->flatMap(fn ($r) => $r['trips'])->where('result', '!=', 'pending')->whereNotNull('break_even_seats')->pluck('break_even_seats', 'id');
        $limits = \App\Services\TransportIndicators::RISK['coverage'];
        $units = $trips->filter(fn ($t) => $t->vehicle_id)->groupBy('vehicle_id')->map(function ($g) use ($boarded, $breakEven, $limits) {
            $counts = $g->map(fn ($t) => $t->reported_passengers !== null ? (int) $t->reported_passengers : (int) ($boarded[$t->id] ?? 0))->values();
            $needs = $g->map(fn ($t) => $breakEven[$t->id] ?? null)->filter(fn ($v) => $v !== null);
            $avg = (int) round($counts->avg());
            $need = $needs->count() ? (int) ceil($needs->avg()) : null;
            $pct = $need ? $avg / $need * 100 : null;

            return ['bus' => $g->first()->bus, 'seats' => $g->first()->seats ? (int) $g->first()->seats : null, 'trips' => $g->count(),
                'used' => $avg, 'lowest' => $counts->min(), 'needed' => $need,
                'level' => $pct === null ? null : ($pct < $limits['bad'] ? 'bad' : ($pct < $limits['warn'] ? 'warn' : 'ok'))];
        })->sortBy('bus', SORT_NATURAL)->values();

        return response()->json([
            'agencies' => $agencies->pluck('name')->values(), 'from' => $from, 'to' => $to,
            'today' => $day + ['parcels' => (clone $parcels($today, $today))->count(), 'parcels_amount' => round((float) (clone $parcels($today, $today))->sum('price'), 2),
                'incidents' => $incidents($today, $today)->count()],
            'period' => $period + ['parcels' => (clone $parcels($from, $to))->count(), 'parcels_amount' => round((float) (clone $parcels($from, $to))->sum('price'), 2),
                'incidents' => $incidents($from, $to)->count(), 'claims' => $claims['total'] ?? null],
            'pending_parcels' => $pending,
            'trend' => $points,
            'units' => $units,
        ]);
    }

    public function claims(Request $request)
    {
        $cid = $this->company($request);
        if (! ModuleFlags::enabled($cid, 'forms')) {
            return response()->json(['enabled' => false]);
        }
        [, , $names, $everything] = $this->mine($cid);

        return response()->json(TransportClaims::compute($cid, $request->input('from') ?: null, $request->input('to') ?: null, $everything ? null : $names));
    }

    public function incidents(Request $request)
    {
        $cid = $this->company($request);
        [$agencies] = $this->mine($cid);
        [$from, $to] = $this->period($request);
        $rows = DB::table('transport_trip_reports as x')->join('transport_trips as t', 't.id', '=', 'x.trip_id')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')->leftJoin('users as u', 'u.id', '=', 'x.user_id')
            ->where('x.company_id', $cid)->whereIn('r.origin_id', $agencies->pluck('id')->all() ?: [0])
            ->whereBetween('x.created_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderByDesc('x.id')->limit(500)
            ->get(['x.id', 'x.kind', 'x.note', 'x.created_at', 'x.trip_id', 't.departure_at', 'o.name as origin', 'd.name as destination', 'v.name as bus',
                'u.username', 'u.firstname', 'u.lastname', DB::raw('x.photo IS NOT NULL as has_photo')]);

        return response()->json(['kinds' => TransportControl::REPORT_KINDS, 'rows' => $rows->map(fn ($x) => [
            'id' => (int) $x->id, 'kind' => $x->kind, 'note' => $x->note, 'at' => $x->created_at, 'trip_id' => (int) $x->trip_id,
            'route' => $x->origin.' → '.$x->destination, 'departure_at' => $x->departure_at, 'bus' => $x->bus,
            'by' => trim(($x->firstname ?? '').' '.($x->lastname ?? '')) ?: $x->username, 'has_photo' => (bool) $x->has_photo,
        ])->values()]);
    }

    /** Trips one can report an incident on: on the road, or leaving / arrived within a day. */
    public function incidentTrips(Request $request)
    {
        $cid = $this->company($request);
        [$agencies] = $this->mine($cid);
        $trips = $this->trips($cid, $agencies->pluck('id')->map(fn ($v) => (int) $v)->all())
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->where('t.status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('t.status', 'in_progress')->orWhereBetween('t.departure_at', [now()->subDay(), now()->addDay()]))
            ->orderBy('t.departure_at')->limit(80)->get(['t.id', 't.departure_at', 't.status', 'o.name as origin', 'd.name as destination', 'v.name as bus']);

        return response()->json(['trips' => $trips->map(fn ($t) => [
            'id' => (int) $t->id, 'label' => Carbon::parse($t->departure_at)->format('d-m H:i').' · '.$t->origin.' → '.$t->destination.($t->bus ? ' · '.$t->bus : ''),
            'status' => $t->status,
        ])->values()]);
    }
}
