<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's "Parcels" tab (Turismo Cautivo, 2026-11-09): how many parcels were attended (delivered) and how many
 * are pending, per agency (the agency that received and charged it) and per route, what they are worth, and the
 * complaints about parcels. Figures only: senders, receivers and phones never leave this class.
 */
class TransportParcelsBoard
{
    /** Size => up to this many kg (null = the rest). Ours, until the client gives theirs. */
    private const SIZES = ['s' => 5, 'm' => 15, 'l' => 25, 'xl' => null];

    /**
     * @param array|null $warehouses the user's locations (null = all)
     * @param int|null $agencyId transport_locations.id of the agency picked with the buttons (null = all)
     */
    public static function compute(int $companyId, string $from, string $to, ?array $warehouses, ?int $agencyId): array
    {
        $today = now()->toDateString();
        $agencies = DB::table('transport_locations')->where('company_id', $companyId)->where('kind', 'agency')->whereNull('deleted_at')
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses ?: [0]))
            ->orderBy('name')->get(['id', 'name', 'warehouse_id']);
        $names = DB::table('transport_locations')->where('company_id', $companyId)->pluck('name', 'id');
        // A terminal belongs to its agency (hub_id): a parcel received there counts for the agency.
        $hub = DB::table('transport_locations')->where('company_id', $companyId)->pluck('hub_id', 'id');
        $agencyOf = fn ($id) => $id === null ? null : (isset($hub[$id]) && $hub[$id] ? (int) $hub[$id] : (int) $id);

        $base = DB::table('transport_parcels')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses ?: [0]));
        $cols = ['id', 'status', 'received_on', 'eta_to', 'delivered_at', 'from_location_id', 'to_location_id', 'trip_id', 'price', 'weight_kg', 'cargo_type', 'payment', 'description'];
        $all = (clone $base)->whereBetween('received_on', [$from, $to])->get($cols);

        // The buttons: every agency with its count, before the agency filter.
        $byAgency = [];
        foreach ($all as $p) {
            $a = $agencyOf($p->from_location_id);
            $byAgency[$a] = ($byAgency[$a] ?? 0) + 1;
        }
        $buttons = $agencies->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'n' => $byAgency[$a->id] ?? 0])->values()->all();
        $agency = $agencyId ? $agencies->firstWhere('id', $agencyId) : null;
        $rows = $agency ? $all->filter(fn ($p) => $agencyOf($p->from_location_id) === (int) $agency->id)->values() : $all;

        // Still pending today, whatever day it was received (the period's filter would hide the old ones).
        $open = (clone $base)->where('status', '<>', 'delivered')->where('received_on', '<=', $today)->get($cols);
        if ($agency) {
            $open = $open->filter(fn ($p) => $agencyOf($p->from_location_id) === (int) $agency->id)->values();
        }

        $delivered = $rows->where('status', 'delivered');
        $onTime = $delivered->filter(fn ($p) => $p->eta_to && $p->delivered_at && substr($p->delivered_at, 0, 10) <= $p->eta_to)->count();
        $days = $delivered->filter(fn ($p) => $p->delivered_at)->map(fn ($p) => Carbon::parse($p->received_on)->diffInDays(Carbon::parse(substr($p->delivered_at, 0, 10))));
        $income = (float) $rows->sum('price');
        $kg = (float) $rows->sum('weight_kg');

        // The same number of days just before, to compare the income.
        $span = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $prev = (clone $base)->whereBetween('received_on', [Carbon::parse($from)->subDays($span)->toDateString(), Carbon::parse($from)->subDay()->toDateString()])->get($cols);
        if ($agency) {
            $prev = $prev->filter(fn ($p) => $agencyOf($p->from_location_id) === (int) $agency->id);
        }

        // Per route (origin agency -> destination agency).
        $routes = [];
        foreach ($rows as $p) {
            $key = ($names[$agencyOf($p->from_location_id)] ?? '?').' → '.($names[$agencyOf($p->to_location_id)] ?? '?');
            $routes[$key] ??= ['route' => $key, 'n' => 0, 'income' => 0.0, 'kg' => 0.0, 'delivered' => 0];
            $routes[$key]['n']++;
            $routes[$key]['income'] += (float) $p->price;
            $routes[$key]['kg'] += (float) $p->weight_kg;
            $routes[$key]['delivered'] += $p->status === 'delivered' ? 1 : 0;
        }
        usort($routes, fn ($a, $b) => $b['income'] <=> $a['income']);

        // Per agency (all of them, for the money chart): income and attended / pending.
        $perAgency = [];
        foreach ($all as $p) {
            $a = $agencyOf($p->from_location_id);
            $perAgency[$a] ??= ['name' => $names[$a] ?? '?', 'n' => 0, 'income' => 0.0, 'delivered' => 0];
            $perAgency[$a]['n']++;
            $perAgency[$a]['income'] += (float) $p->price;
            $perAgency[$a]['delivered'] += $p->status === 'delivered' ? 1 : 0;
        }
        usort($perAgency, fn ($a, $b) => $b['income'] <=> $a['income']);

        // Pending today by where they must be delivered: still in the agency, or on a bus.
        $pending = [];
        foreach ($open as $p) {
            $a = $agencyOf($p->to_location_id);
            $pending[$a] ??= ['name' => $names[$a] ?? '?', 'waiting' => 0, 'on_board' => 0, 'late' => 0];
            $pending[$a][$p->status === 'on_board' ? 'on_board' : 'waiting']++;
            $pending[$a]['late'] += $p->eta_to && $p->eta_to < $today ? 1 : 0;
        }
        usort($pending, fn ($a, $b) => ($b['waiting'] + $b['on_board']) <=> ($a['waiting'] + $a['on_board']));

        // Size by weight (our cuts, not the client's: 2026-11-09) and, inside each, what the sender declared.
        $sizes = [];
        foreach (self::SIZES as $key => $max) {
            $sizes[$key] = ['key' => $key, 'max' => $max, 'n' => 0, 'income' => 0.0, 'kg' => 0.0, 'contents' => []];
        }
        foreach ($rows as $p) {
            $kgP = (float) $p->weight_kg;
            $key = 'xl';
            foreach (self::SIZES as $k => $max) {
                if ($max !== null && $kgP <= $max) {
                    $key = $k;
                    break;
                }
            }
            $sizes[$key]['n']++;
            $sizes[$key]['income'] += (float) $p->price;
            $sizes[$key]['kg'] += $kgP;
            $c = trim((string) $p->description) ?: '';
            $sizes[$key]['contents'][$c] = ($sizes[$key]['contents'][$c] ?? 0) + 1;
        }

        // Day by day: received and income.
        $trend = [];
        foreach ($rows as $p) {
            $d = $p->received_on;
            $trend[$d] ??= ['date' => $d, 'n' => 0, 'income' => 0.0];
            $trend[$d]['n']++;
            $trend[$d]['income'] += (float) $p->price;
        }
        ksort($trend);

        // A complaint names an agency or one of its terminals (PLAZA LIMA NORTE is Lima's).
        // A login with only some agencies (a supervisor, 2026-11-11) sees only their complaints.
        $mine = $agency ? [(int) $agency->id] : ($warehouses !== null ? $agencies->pluck('id')->map(fn ($v) => (int) $v)->all() : null);
        $places = $mine === null ? null : $names->filter(fn ($n, $id) => in_array($agencyOf((int) $id), $mine, true))->values()->all();
        $claims = TransportClaims::parcels($companyId, $from, $to, $places);
        // The client's monthly figure for the whole company, brought to the period's days (only without an agency picked).
        $monthRef = (float) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('transport_parcel_month_ref');
        $ref = $monthRef > 0 && ! $agency ? round($monthRef * $span / 30, 2) : null;

        return [
            'enabled' => true, 'from' => $from, 'to' => $to, 'agency' => ($agency ? $agency->id : null), 'buttons' => $buttons,
            'total' => $rows->count(), 'delivered' => $delivered->count(),
            'pending' => $open->count(), 'on_board' => $open->where('status', 'on_board')->count(),
            'late' => $open->filter(fn ($p) => $p->eta_to && $p->eta_to < $today)->count(),
            'on_time' => $delivered->count() ? round($onTime / $delivered->count() * 100) : null,
            'avg_days' => $days->count() ? round($days->avg(), 1) : null,
            'income' => round($income, 2), 'income_prev' => round((float) $prev->sum('price'), 2),
            'avg_price' => $rows->count() ? round($income / $rows->count(), 2) : null,
            'per_kg' => $kg > 0 ? round($income / $kg, 2) : null, 'kg' => round($kg, 1),
            'per_day' => round($income / $span, 2), 'month_ref' => $monthRef ?: null, 'ref' => $ref,
            'ref_pct' => $ref ? round($income / $ref * 100) : null,
            'fragile' => $rows->count() ? round($rows->where('cargo_type', 'fragile')->count() / $rows->count() * 100) : 0,
            'unpaid' => round((float) $rows->where('payment', '<>', 'paid')->sum('price'), 2),
            'routes' => array_values($routes), 'agencies' => array_values($perAgency), 'pending_by' => array_values($pending),
            'trend' => array_values($trend), 'claims' => $claims, 'sizes' => array_values($sizes),
        ];
    }
}
