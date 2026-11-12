<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\TransportControl;
use App\Support\CompanyFiles;
use App\Support\ModuleFlags;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle monitoring (user's request, 2026-11-06). The crew reports from the phone with transport_report, on their own
 * trips only; whoever has transport_monitor watches the trips of their locations and can report for a crew without a phone.
 * A pilot never reports with the bus moving (TransportControl::drivingBlock).
 *
 *  GET  transport/control/mine?trip              my current trip and my next ones
 *  POST transport/control/trips/{id}/position    {lat, lng, speed, accuracy}
 *  POST transport/control/trips/{id}/arrive      {location_id, photo, lat, lng, speed, accuracy, note}: the photo is required
 *  POST transport/control/trips/{id}/depart      {location_id, lat, lng, speed, accuracy}
 *  POST transport/control/trips/{id}/skip        {location_id, lat, lng, speed, accuracy}
 *  POST transport/control/trips/{id}/photo       {location_id, photo}: the arrival the GPS detected
 *  POST transport/control/trips/{id}/report      {kind, note, photo, lat, lng, speed, accuracy}
 *  GET  transport/control/monitor?date           the day's trips, where they are, how late
 *  GET  transport/control/trips/{id}             one trip's control points and reports
 *  GET  transport/control/punctuality?from&to    by route, bus and pilot
 *  GET  transport/control/photo/{kind}/{id}      a private photo (checkpoint | report)
 */
class TransportControlController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    private function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    private function can(Request $request, string $perm): bool
    {
        return HelpdeskAccess::can($request->user('api'), $perm);
    }

    /** A monitor user's trips: those that leave from, stop at or are coming to their locations. */
    private function scoped($q)
    {
        $scope = $this->warehouseScopeIds();

        return $q->when($scope !== null, fn ($x) => $x->where(fn ($w) => $w->whereIn('o.warehouse_id', $scope)->orWhereIn('d.warehouse_id', $scope)->orWhereExists(fn ($e) => $e->from('transport_route_stops as s')
            ->join('transport_locations as l', 'l.id', '=', 's.location_id')->whereColumn('s.route_id', 't.route_id')->whereIn('l.warehouse_id', $scope))));
    }

    /** Trips with their names. */
    private function trips(int $cid)
    {
        return DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
            ->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->where('t.company_id', $cid)->whereNull('t.deleted_at')
            ->select('t.*', 'o.name as origin', 'd.name as destination', 'v.name as vehicle', 'v.plate_number');
    }

    /** The trip this user may report on, and their crew role on it. 404 / 403 otherwise. */
    private function reportable(Request $request, int $id, int $cid): array
    {
        $trip = $this->trips($cid)->where('t.id', $id)->first();
        abort_unless($trip, 404);
        $user = $request->user('api');
        $role = TransportControl::crewRole($id, (int) $user->id);
        if ($role && $this->can($request, 'transport_report')) {
            return [$trip, $role];
        }
        // The monitor, or an agency supervisor ("Mi agencia" -> incidents, 2026-11-11), on a trip of their locations.
        abort_unless(($this->can($request, 'transport_monitor') || $this->can($request, 'transport_agency_view'))
            && $this->scoped($this->trips($cid)->where('t.id', $id))->exists(), 403, 'This trip is not yours.');

        return [$trip, $role];
    }

    /** The position sent with a request. */
    private function fix(Request $request): array
    {
        $v = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'speed' => 'nullable|numeric|min:0|max:300', 'accuracy' => 'nullable|numeric|min:0|max:100000',
        ]);

        return array_filter($v, fn ($x) => $x !== null);
    }

    private function refuseIfDriving(object $trip, ?string $role, array $fix): void
    {
        if ($error = TransportControl::drivingBlock($trip, $role, $fix)) {
            throw ValidationException::withMessages(['driving' => $error]);
        }
    }

    /** The picture of a request, stored privately. */
    private function photo(Request $request, int $cid): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }
        $request->validate(['photo' => 'file|max:15360']);
        $file = $request->file('photo');
        $path = CompanyFiles::putPhoto($cid, 'transport', (string) file_get_contents($file->getRealPath()), 'control', true);
        if (! $path) {
            throw ValidationException::withMessages(['photo' => 'That file is not a picture.']);
        }

        return $path;
    }

    private function when(?Carbon $at): ?string
    {
        return $at ? $at->format('Y-m-d H:i:s') : null;
    }

    /** A trip's control points for the screen. */
    private function pointsOut(array $points): array
    {
        return array_map(fn ($p) => array_merge($p, ['plan_arrive' => $this->when($p['plan_arrive']), 'plan_depart' => $this->when($p['plan_depart'])]), $points);
    }

    private function detail(object $trip, ?string $role): array
    {
        $points = TransportControl::points($trip);
        $next = TransportControl::next($points);
        $reports = DB::table('transport_trip_reports as x')->leftJoin('users as u', 'u.id', '=', 'x.user_id')->where('x.trip_id', $trip->id)->orderByDesc('x.id')
            ->get(['x.id', 'x.kind', 'x.note', 'x.created_at', 'x.lat', 'x.lng', 'x.speed_kmh', 'u.username', DB::raw('x.photo IS NOT NULL as has_photo')]);

        return [
            'id' => (int) $trip->id, 'route' => $trip->origin.' → '.$trip->destination, 'vehicle' => trim(($trip->vehicle ?? '').' '.($trip->plate_number ? '('.$trip->plate_number.')' : '')),
            'departure_at' => $trip->departure_at, 'arrival_at' => $trip->arrival_at, 'status' => $trip->status, 'my_role' => $role,
            'must_stop' => $role === 'pilot', 'tracking' => TransportControl::tracking($trip, $points), 'stop_kmh' => TransportControl::STOP_KMH,
            'points' => $this->pointsOut($points), 'next' => $next, 'reports' => $reports,
            'crew' => $this->crew([(int) $trip->id])[(int) $trip->id] ?? [],
        ];
    }

    /** [trip id => [{role, name}]] */
    private function crew(array $ids): array
    {
        return DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->whereIn('c.trip_id', $ids)->orderBy('c.role')
            ->get(['c.trip_id', 'c.role', 'e.firstname', 'e.lastname'])
            ->groupBy('trip_id')->map(fn ($g) => $g->map(fn ($c) => ['role' => $c->role, 'name' => trim($c->firstname.' '.$c->lastname)])->values()->all())->all();
    }

    // ------------------------------------------------------------------ the crew's phone

    public function mine(Request $request)
    {
        $cid = $this->company($request);
        abort_unless($this->can($request, 'transport_report') || $this->can($request, 'transport_monitor'), 403);
        $user = $request->user('api');
        if ($request->filled('trip')) {
            [$trip, $role] = $this->reportable($request, (int) $request->input('trip'), $cid);

            return response()->json(['trip' => $this->detail($trip, $role), 'trips' => []]);
        }
        $employee = DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->value('id');
        $list = ! $employee ? collect() : $this->trips($cid)
            ->join('transport_trip_crew as c', 'c.trip_id', '=', 't.id')->where('c.employee_id', $employee)
            ->where(fn ($q) => $q->where('t.status', 'in_progress')->orWhere(fn ($w) => $w->where('t.status', 'scheduled')->whereBetween('t.departure_at', [now()->subHours(12), now()->addDays(3)])))
            ->orderByRaw("t.status = 'in_progress' DESC")->orderBy('t.departure_at')->limit(10)->get();
        $current = $list->first();

        return response()->json([
            'trip' => $current ? $this->detail($current, TransportControl::crewRole((int) $current->id, (int) $user->id)) : null,
            'trips' => $list->slice(1)->map(fn ($t) => ['id' => (int) $t->id, 'route' => $t->origin.' → '.$t->destination, 'departure_at' => $t->departure_at, 'vehicle' => $t->vehicle])->values(),
        ]);
    }

    public function position(Request $request, int $id)
    {
        $cid = $this->company($request);
        [$trip, $role] = $this->reportable($request, $id, $cid);
        $fix = $this->fix($request);
        if (! isset($fix['lat'], $fix['lng'])) {
            throw ValidationException::withMessages(['lat' => 'No position.']);
        }
        $points = TransportControl::points($trip);
        if (! TransportControl::tracking($trip, $points)) {
            return response()->json(['tracking' => false]);
        }
        // Before storing: the arrival compares this position with the previous one.
        $auto = TransportControl::autoDetect($trip, $fix);
        TransportControl::storePosition($trip, (int) $request->user('api')->id, $fix);

        return response()->json(['tracking' => true, 'auto' => $auto, 'trip' => $auto ? $this->detail($this->trips($cid)->where('t.id', $id)->first(), $role) : null]);
    }

    public function arrive(Request $request, int $id)
    {
        $cid = $this->company($request);
        [$trip, $role] = $this->reportable($request, $id, $cid);
        $v = $request->validate(['location_id' => 'required|integer', 'note' => 'nullable|string|max:500']);
        $fix = $this->fix($request);
        $this->refuseIfDriving($trip, $role, $fix);
        if (! $request->hasFile('photo')) {
            throw ValidationException::withMessages(['photo' => 'The arrival needs its photo.']);
        }
        $photo = $this->photo($request, $cid);
        $error = TransportControl::mark($id, (int) $v['location_id'], 'arrive', 'manual', (int) $request->user('api')->id, $fix, $photo, isset($v['note']) ? strip_tags($v['note']) : null);
        if ($error) {
            @unlink(public_path($photo));
            abort(422, $error);
        }

        return response()->json(['trip' => $this->detail($this->trips($cid)->where('t.id', $id)->first(), $role)]);
    }

    public function depart(Request $request, int $id)
    {
        $cid = $this->company($request);
        [$trip, $role] = $this->reportable($request, $id, $cid);
        $v = $request->validate(['location_id' => 'required|integer']);
        $fix = $this->fix($request);
        $this->refuseIfDriving($trip, $role, $fix);
        if ($error = TransportControl::mark($id, (int) $v['location_id'], 'depart', 'manual', (int) $request->user('api')->id, $fix)) {
            abort(422, $error);
        }

        return response()->json(['trip' => $this->detail($this->trips($cid)->where('t.id', $id)->first(), $role)]);
    }

    public function skip(Request $request, int $id)
    {
        $cid = $this->company($request);
        [$trip, $role] = $this->reportable($request, $id, $cid);
        $v = $request->validate(['location_id' => 'required|integer']);
        $this->refuseIfDriving($trip, $role, $this->fix($request));
        if ($error = TransportControl::skip($id, (int) $v['location_id'], (int) $request->user('api')->id)) {
            abort(422, $error);
        }

        return response()->json(['trip' => $this->detail($this->trips($cid)->where('t.id', $id)->first(), $role)]);
    }

    /** The photo of an arrival the GPS detected. */
    public function addPhoto(Request $request, int $id)
    {
        $cid = $this->company($request);
        [$trip, $role] = $this->reportable($request, $id, $cid);
        $v = $request->validate(['location_id' => 'required|integer']);
        $this->refuseIfDriving($trip, $role, $this->fix($request));
        abort_unless($request->hasFile('photo'), 422, 'Take the photo.');
        $row = DB::table('transport_trip_checkpoints')->where('trip_id', $id)->where('location_id', (int) $v['location_id'])->whereNotNull('arrived_at')->whereNull('photo')->first();
        abort_unless($row, 422, 'That arrival already has its photo.');
        DB::table('transport_trip_checkpoints')->where('id', $row->id)->update(['photo' => $this->photo($request, $cid), 'photo_at' => now(), 'updated_at' => now()]);

        return response()->json(['trip' => $this->detail($this->trips($cid)->where('t.id', $id)->first(), $role)]);
    }

    public function report(Request $request, int $id)
    {
        $cid = $this->company($request);
        [$trip, $role] = $this->reportable($request, $id, $cid);
        $v = $request->validate(['kind' => 'required|in:'.implode(',', TransportControl::REPORT_KINDS), 'note' => 'nullable|string|max:1000']);
        $fix = $this->fix($request);
        $this->refuseIfDriving($trip, $role, $fix);
        $photo = $this->photo($request, $cid);
        DB::table('transport_trip_reports')->insert(['company_id' => $cid, 'trip_id' => $id, 'user_id' => $request->user('api')->id, 'kind' => $v['kind'],
            'note' => isset($v['note']) ? strip_tags($v['note']) : null, 'lat' => $fix['lat'] ?? null, 'lng' => $fix['lng'] ?? null, 'speed_kmh' => $fix['speed'] ?? null,
            'photo' => $photo, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['trip' => $this->detail($this->trips($cid)->where('t.id', $id)->first(), $role)]);
    }

    // ------------------------------------------------------------------ the monitor

    public function monitor(Request $request)
    {
        $cid = $this->company($request);
        abort_unless($this->can($request, 'transport_monitor'), 403);
        $date = $request->input('date') ?: now()->toDateString();
        $today = $date === now()->toDateString();
        $trips = $this->scoped($this->trips($cid))
            ->where(fn ($q) => $q->whereDate('t.departure_at', $date)->when($today, fn ($w) => $w->orWhere('t.status', 'in_progress')))
            ->where('t.status', '!=', 'cancelled')->orderBy('t.departure_at')->get();
        $ids = $trips->pluck('id')->all();
        $boardings = DB::table('transport_trip_boardings')->whereIn('trip_id', $ids)->orderBy('seq')->get(['trip_id', 'location_id', 'label', 'board_at'])->groupBy('trip_id');
        $checks = DB::table('transport_trip_checkpoints')->whereIn('trip_id', $ids)->get()->groupBy('trip_id');
        $crew = $this->crew($ids);
        $reportRows = DB::table('transport_trip_reports')->whereIn('trip_id', $ids)->orderBy('id')->get(['trip_id', 'kind', 'created_at'])->groupBy('trip_id');
        $latest = DB::table('transport_vehicle_positions')->whereIn('id', DB::table('transport_vehicle_positions')->whereIn('trip_id', $ids)->selectRaw('MAX(id)')->groupBy('trip_id'))->get()->keyBy('trip_id');
        $now = now();

        $rows = $trips->map(function ($t) use ($boardings, $checks, $crew, $reportRows, $latest, $now) {
            $points = TransportControl::points($t, $boardings[$t->id] ?? collect(), $checks[$t->id] ?? collect());
            $next = TransportControl::next($points);
            $position = $latest[$t->id] ?? null;
            $reported = collect($points)->filter(fn ($p) => $p['arrived_at'] || $p['departed_at'] || $p['skipped'])->count();
            $reports = collect($reportRows[$t->id] ?? []);
            // The last control point reported: where the bus is on the map when the phone sends no position.
            $lastEvent = null;
            foreach ($points as $p) {
                if ($p['departed_at'] || $p['arrived_at']) {
                    $lastEvent = ['name' => $p['name'], 'action' => $p['departed_at'] ? 'depart' : 'arrive', 'at' => $p['departed_at'] ?: $p['arrived_at'],
                        'delay_min' => $p['departed_at'] ? $p['depart_delay_min'] : $p['arrive_delay_min'], 'lat' => $p['lat'], 'lng' => $p['lng']];
                }
            }
            $end = $points ? end($points) : null;
            // How late: the last time on record, or how far past its plan the next point already is.
            $delay = null;
            foreach ($points as $p) {
                $delay = $p['depart_delay_min'] ?? $p['arrive_delay_min'] ?? $delay;
            }
            if ($next && $reported) {
                $plan = $next['action'] === 'arrive' ? $points[$next['index']]['plan_arrive'] : $points[$next['index']]['plan_depart'];
                if ($plan && $now->gt($plan)) {
                    $delay = max($delay ?? 0, (int) round($plan->diffInMinutes($now, false)));
                }
            }

            // When it reaches its destination: the plan moved by the delay it carries, or the real arrival.
            $eta = null;
            if ($end && $end['arrived_at']) {
                $eta = Carbon::parse($end['arrived_at']);
            } elseif ($end && $end['plan_arrive']) {
                $eta = $end['plan_arrive']->copy()->addMinutes(max(0, (int) $delay));
            }
            $grave = $reports->whereIn('kind', ['accident', 'breakdown'])->last();

            return [
                'id' => (int) $t->id, 'route' => $t->origin.' → '.$t->destination, 'origin' => $t->origin, 'destination' => $t->destination,
                'vehicle' => trim(($t->vehicle ?? '').' '.($t->plate_number ? '('.$t->plate_number.')' : '')), 'bus' => $t->vehicle ?: $t->plate_number,
                'departure_at' => $t->departure_at, 'arrival_at' => $t->arrival_at, 'status' => $t->status, 'crew' => $crew[$t->id] ?? [],
                'destination_lat' => $end ? $end['lat'] : null, 'destination_lng' => $end ? $end['lng'] : null, 'eta' => $this->when($eta), 'eta_is_real' => (bool) ($end['arrived_at'] ?? false),
                'points' => count($points), 'reported' => $reported, 'next' => $next ? ['name' => $points[$next['index']]['name'], 'action' => $next['action']] : null,
                'last_event' => $lastEvent, 'delay_min' => $delay, 'late' => $delay !== null && $delay > TransportControl::LATE_MIN,
                'photos_missing' => collect($points)->filter(fn ($p) => $p['arrived_at'] && ! $p['has_photo'])->count(), 'reports' => $reports->count(),
                'alert' => $grave && $t->status === 'in_progress' ? ['kind' => $grave->kind, 'at' => $grave->created_at] : null,
                'position' => $position ? ['lat' => (float) $position->lat, 'lng' => (float) $position->lng, 'speed' => $position->speed_kmh !== null ? (float) $position->speed_kmh : null,
                    'at' => $position->recorded_at, 'age_min' => (int) round(Carbon::parse($position->recorded_at)->diffInMinutes($now, false))] : null,
            ];
        })->values();

        return response()->json([
            'date' => $date, 'trips' => $rows, 'late_min' => TransportControl::LATE_MIN,
            'places' => DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->whereNotNull('lat')->get(['id', 'name', 'lat', 'lng']),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $cid = $this->company($request);
        abort_unless($this->can($request, 'transport_monitor'), 403);
        $trip = $this->scoped($this->trips($cid)->where('t.id', $id))->first();
        abort_unless($trip, 404);

        return response()->json(['trip' => $this->detail($trip, null)]);
    }

    /** Times on record, grouped by route, bus and pilot. */
    public function punctuality(Request $request)
    {
        $cid = $this->company($request);
        abort_unless($this->can($request, 'transport_monitor'), 403);
        $from = $request->input('from') ?: now()->subDays(30)->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        $rows = $this->scoped($this->trips($cid))->join('transport_trip_checkpoints as k', 'k.trip_id', '=', 't.id')
            ->whereDate('t.departure_at', '>=', $from)->whereDate('t.departure_at', '<=', $to)
            ->addSelect('k.arrive_delay_min', 'k.depart_delay_min')->get();
        $pilots = DB::table('transport_trip_crew as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->whereIn('c.trip_id', $rows->pluck('id')->unique())->where('c.role', 'pilot')
            ->get(['c.trip_id', 'e.firstname', 'e.lastname'])->groupBy('trip_id');

        $group = function (callable $key) use ($rows) {
            $out = [];
            foreach ($rows as $r) {
                foreach ((array) $key($r) as $k) {
                    $out[$k] ??= ['name' => $k, 'trips' => [], 'marks' => 0, 'late' => 0, 'sum' => 0];
                    $out[$k]['trips'][$r->id] = true;
                    foreach ([$r->arrive_delay_min, $r->depart_delay_min] as $d) {
                        if ($d !== null) {
                            $out[$k]['marks']++;
                            $out[$k]['sum'] += max(0, (int) $d);
                            $out[$k]['late'] += (int) $d > TransportControl::LATE_MIN ? 1 : 0;
                        }
                    }
                }
            }

            return collect($out)->map(fn ($g) => ['name' => $g['name'], 'trips' => count($g['trips']), 'marks' => $g['marks'], 'late' => $g['late'],
                'on_time_pct' => $g['marks'] ? round(100 * ($g['marks'] - $g['late']) / $g['marks']) : null, 'avg_delay' => $g['marks'] ? round($g['sum'] / $g['marks'], 1) : null,
            ])->sortBy('name')->values();
        };

        return response()->json([
            'from' => $from, 'to' => $to, 'late_min' => TransportControl::LATE_MIN,
            'routes' => $group(fn ($r) => $r->origin.' → '.$r->destination),
            'vehicles' => $group(fn ($r) => trim(($r->vehicle ?? '').' '.($r->plate_number ? '('.$r->plate_number.')' : ''))),
            'pilots' => $group(fn ($r) => collect($pilots[$r->id] ?? [])->map(fn ($p) => trim($p->firstname.' '.$p->lastname))->all()),
        ]);
    }

    /** A private photo: the company's, for the monitor or the trip's crew. */
    public function photoFile(Request $request, string $kind, int $id)
    {
        $cid = $this->company($request);
        $row = DB::table($kind === 'report' ? 'transport_trip_reports' : 'transport_trip_checkpoints')->where('id', $id)->where('company_id', $cid)->first(['trip_id', 'photo']);
        abort_unless($row && CompanyFiles::owns($row->photo, $cid, 'transport'), 404);
        $this->reportable($request, (int) $row->trip_id, $cid);

        return response()->file(public_path($row->photo), ['Content-Type' => 'image/jpeg']);
    }
}
