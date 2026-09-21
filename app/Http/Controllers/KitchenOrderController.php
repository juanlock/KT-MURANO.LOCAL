<?php

namespace App\Http\Controllers;

use App\Models\KitchenOrder;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Unit;
use App\Models\Warehouse;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;

class KitchenOrderController extends Controller
{
    /**
     * GET /kitchen/orders
     * Query: status, from, to, q (ref/customer). Returns tickets grouped by status.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $statusFilter = trim((string) $request->query('status', ''));

        $orders = KitchenOrder::query()
            ->with('client', 'assignedUser', 'dispatchedWarehouse', 'sale:id,deleted_at')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('ref', 'like', "%{$q}%")
                        ->orWhereHas('client', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                                ->orWhere('phone', 'like', "%{$q}%");
                        });
                });
            })
            ->when($statusFilter !== '' && in_array($statusFilter, KitchenOrder::STATUSES, true),
                fn ($qq) => $qq->where('status', $statusFilter))
            ->when($from, fn ($qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('created_at', '<=', $to))
            ->orderBy('created_at', 'asc')
            ->get();

        $data = $orders->map(fn (KitchenOrder $o) => $this->mapOrder($o, true));

        // Group by the four kitchen statuses for the board layout.
        $grouped = [];
        foreach (KitchenOrder::STATUSES as $s) {
            $grouped[$s] = $data->where('status', $s)->values();
        }

        $settings = \App\Models\Setting::whereNull('deleted_at')->first();

        return response()->json([
            'data' => $data->values(),
            'grouped' => $grouped,
            'counts' => collect(KitchenOrder::STATUSES)
                ->mapWithKeys(fn ($s) => [$s => $data->where('status', $s)->count()]),
            // Bundled so the board's "send to warehouse" dropdown is always populated.
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            // Prep-time target (minutes) driving the board's overdue color escalation;
            // null disables it.
            'target_minutes' => $settings && $settings->kitchen_target_minutes
                ? (int) $settings->kitchen_target_minutes
                : null,
            // Prep stations ([{id, name, category_ids}]) so the board can filter per station.
            'stations' => self::decodeStations($settings),
        ]);
    }

    /**
     * GET /kitchen/orders/poll
     * Lightweight signal so the board can detect new/updated tickets without re-rendering.
     */
    public function poll(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $latest = KitchenOrder::max('id');
        $lastUpdate = KitchenOrder::max('updated_at');

        $counts = collect(KitchenOrder::STATUSES)->mapWithKeys(function ($s) {
            return [$s => KitchenOrder::where('status', $s)->count()];
        });

        return response()->json([
            'latest_id' => (int) $latest,
            'last_update' => $lastUpdate ? Carbon::parse($lastUpdate)->toDateTimeString() : null,
            'counts' => $counts,
        ]);
    }

    /**
     * GET /kitchen/orders/{id}
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $order = KitchenOrder::with('client', 'warehouse', 'assignedUser', 'sentBy', 'dispatchedWarehouse')->findOrFail($id);

        return response()->json($this->mapOrder($order, true));
    }

    /**
     * POST /kitchen/orders   (Send Later)
     * Create a kitchen ticket for an existing sale that does not already have one.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'instructions' => 'nullable|string',
        ]);

        $existing = KitchenOrder::where('sale_id', $data['sale_id'])->first();
        if ($existing) {
            return response()->json([
                'ok' => true,
                'already_sent' => true,
                'order' => $this->mapOrder($existing->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
            ]);
        }

        $order = self::createForSale((int) $data['sale_id'], $data['instructions'] ?? null, 'manual');

        if (! $order) {
            return response()->json(['error' => 'Sale not found.'], 404);
        }

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ], 201);
    }

    /**
     * PATCH /kitchen/orders/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'status' => 'required|in:pending,preparing,completed,on_hold',
        ]);

        $order = KitchenOrder::findOrFail($id);
        $order->status = $data['status'];

        if ($data['status'] === 'preparing' && ! $order->started_at) {
            $order->started_at = now();
        }
        if ($data['status'] === 'completed') {
            $order->completed_at = now();
        }

        $order->save();

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * PATCH /kitchen/orders/{id}/item   (per-item bump)
     * Toggle one line's done flag. Checking the first item on a pending ticket
     * starts preparation; checking the last one completes the whole ticket.
     */
    public function updateItem(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'item_id' => 'required|integer',
            'done' => 'required|boolean',
        ]);

        $order = KitchenOrder::findOrFail($id);

        $detailIds = SaleDetail::where('sale_id', $order->sale_id)->pluck('id');
        if (! $detailIds->contains((int) $data['item_id'])) {
            return response()->json(['error' => 'Item does not belong to this order.'], 422);
        }

        $states = (array) ($order->item_states ?? []);
        if ($data['done']) {
            $states[(string) $data['item_id']] = true;
        } else {
            unset($states[(string) $data['item_id']]);
        }
        $order->item_states = $states;

        $autoCompleted = false;
        if ($data['done']) {
            if ($order->status === 'pending' || $order->status === 'on_hold') {
                $order->status = 'preparing';
                if (! $order->started_at) {
                    $order->started_at = now();
                }
            }
            $allDone = $detailIds->every(fn ($did) => ! empty($states[(string) $did]));
            if ($allDone && $order->status !== 'completed') {
                $order->status = 'completed';
                $order->completed_at = now();
                $autoCompleted = true;
            }
        }
        // Unchecking never demotes a completed ticket — that stays an explicit
        // "Reopen", matching the whole-ticket workflow.

        $order->save();

        return response()->json([
            'ok' => true,
            'auto_completed' => $autoCompleted,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * PATCH /kitchen/orders/{id}/assign
     */
    public function assign(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $order = KitchenOrder::findOrFail($id);
        $order->assigned_to = $data['assigned_to'] ?? null;
        $order->save();

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * GET /kitchen/warehouses
     * Lightweight warehouse list for the "send to warehouse" dropdown. Gated by the kitchen
     * view permission so kitchen staff don't need the separate warehouse permission.
     */
    public function warehouses(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $warehouses = Warehouse::whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['warehouses' => $warehouses]);
    }

    /**
     * GET /kitchen/stations
     * Stations config plus the category list the editor needs. View-gated so
     * kitchen staff can pick their station without extra permissions.
     */
    public function stations(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $settings = \App\Models\Setting::whereNull('deleted_at')->first();

        return response()->json([
            'stations' => self::decodeStations($settings),
            'categories' => \App\Models\Category::whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * PUT /kitchen/stations   (manage)
     * Replace the stations list. Shape: [{id?, name, category_ids: []}].
     */
    public function saveStations(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'stations' => 'present|array|max:30',
            'stations.*.id' => 'nullable|string|max:40',
            'stations.*.name' => 'required|string|max:60',
            'stations.*.category_ids' => 'present|array',
            'stations.*.category_ids.*' => 'integer',
        ]);

        $stations = collect($data['stations'])->map(fn ($s, $i) => [
            'id' => ($s['id'] ?? '') !== '' ? (string) $s['id'] : 'st_'.now()->timestamp.'_'.$i,
            'name' => trim($s['name']),
            'category_ids' => array_values(array_unique(array_map('intval', $s['category_ids']))),
        ])->values()->all();

        $settings = \App\Models\Setting::whereNull('deleted_at')->first();
        if (! $settings) {
            return response()->json(['error' => 'Settings not found.'], 404);
        }
        \App\Models\Setting::whereId($settings->id)->update([
            'kitchen_stations' => json_encode($stations),
        ]);

        return response()->json(['ok' => true, 'stations' => $stations]);
    }

    /** settings.kitchen_stations json → clean array (never null). */
    protected static function decodeStations($settings): array
    {
        $raw = $settings ? $settings->kitchen_stations : null;
        $list = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * PATCH /kitchen/orders/{id}/dispatch
     * Records which warehouse a completed order was handed off to. Display-only —
     * no stock movement or transfer is created.
     */
    public function dispatchOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'dispatched_warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $order = KitchenOrder::findOrFail($id);

        if ($order->status !== 'completed') {
            return response()->json(['error' => 'Only completed orders can be sent to a warehouse.'], 422);
        }

        $order->dispatched_warehouse_id = (int) $data['dispatched_warehouse_id'];
        $order->dispatched_at = now();
        $order->save();

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * POST /kitchen/ready-screen/generate
     * Token for the public customer-facing "Order Ready" page — same cache-token
     * pattern as the POS customer display, but valid 7 days so a wall TV doesn't
     * need daily re-pairing.
     */
    public function generateReadyScreen(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $token = \Illuminate\Support\Str::random(40);
        cache(['order_ready_token' => $token], now()->addDays(7));

        return response()->json([
            'token' => $token,
            'url' => url('/order-ready').'?token='.$token,
        ]);
    }

    /**
     * GET /kitchen/ready-screen/data   (public, token-guarded)
     * Token lists for the Order Ready screen: everything still in the kitchen
     * today, and tickets completed within the last 4 hours.
     */
    public function readyScreenData(Request $request)
    {
        $token = (string) $request->query('token', '');
        if ($token === '' || $token !== cache('order_ready_token')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $label = fn (KitchenOrder $o) => $o->token_number
            ? '#'.$o->token_number
            : ($o->ref ?: '#'.$o->id);

        $preparing = KitchenOrder::whereIn('status', ['pending', 'preparing', 'on_hold'])
            ->whereDate('created_at', now()->toDateString())
            ->orderBy('created_at')
            ->limit(60)->get()->map($label)->values();

        $ready = KitchenOrder::where('status', 'completed')
            ->where('completed_at', '>=', now()->subHours(4))
            ->orderByDesc('completed_at')
            ->limit(60)->get()->map($label)->values();

        return response()->json([
            'preparing' => $preparing,
            'ready' => $ready,
            'ts' => now()->timestamp,
        ]);
    }

    /**
     * GET /kitchen/report
     * Kitchen performance: per-ticket rows (wait/prep/total minutes) plus KPI
     * aggregates, a per-day prep-time trend, staff summary and top products.
     * Uses the standard report paging contract (limit/page/SortField/SortType/search).
     */
    public function report(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $start = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay()
                                          : now()->subDays(29)->startOfDay();
        $end = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay()
                                      : now()->endOfDay();
        $search = trim((string) $request->get('search', ''));

        $perPage = max(1, (int) $request->get('limit', 10));
        $page = max(1, (int) $request->get('page', 1));
        $order = $request->get('SortField', 'sent_at');
        $dir = strtolower((string) $request->get('SortType', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowed = ['ref', 'token_number', 'source', 'status', 'staff_name', 'sent_at',
            'wait_minutes', 'prep_minutes', 'total_minutes'];
        if (! in_array($order, $allowed, true)) {
            $order = 'sent_at';
        }

        $between = [$start->toDateTimeString(), $end->toDateTimeString()];

        $waitExpr = 'TIMESTAMPDIFF(MINUTE, COALESCE(k.sent_at, k.created_at), k.started_at)';
        $prepExpr = 'TIMESTAMPDIFF(MINUTE, k.started_at, k.completed_at)';
        $totalExpr = 'TIMESTAMPDIFF(MINUTE, COALESCE(k.sent_at, k.created_at), k.completed_at)';
        // Age of a still-open ticket, so overdue counts include tickets not yet completed.
        $ageExpr = "COALESCE($totalExpr, TIMESTAMPDIFF(MINUTE, COALESCE(k.sent_at, k.created_at), NOW()))";

        $base = \DB::table('kitchen_orders as k')
            ->leftJoin('clients as c', 'c.id', '=', 'k.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'k.assigned_to')
            ->whereNull('k.deleted_at')
            ->whereBetween('k.created_at', $between);

        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $q->where('k.ref', 'LIKE', "%{$search}%")
                    ->orWhere('c.name', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = (clone $base)->count();

        $rows = (clone $base)
            ->selectRaw("k.id, k.ref, k.token_number, k.source, k.status,
                COALESCE(c.name, '') as customer_name,
                TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) as staff_name,
                k.sent_at, k.started_at, k.completed_at,
                $waitExpr as wait_minutes,
                $prepExpr as prep_minutes,
                $totalExpr as total_minutes")
            ->orderBy($order, $dir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $settings = \App\Models\Setting::whereNull('deleted_at')->first();
        $target = $settings && $settings->kitchen_target_minutes ? (int) $settings->kitchen_target_minutes : null;

        $kpi = (clone $base)->selectRaw("
                COUNT(*) as tickets,
                SUM(k.status = 'completed') as completed,
                AVG($waitExpr) as avg_wait,
                AVG($prepExpr) as avg_prep,
                AVG($totalExpr) as avg_total"
                .($target ? ", SUM(CASE WHEN $ageExpr > {$target} THEN 1 ELSE 0 END) as overdue" : ''))
            ->first();

        $timeseries = (clone $base)
            ->selectRaw("DATE(k.created_at) as d, COUNT(*) as tickets, AVG($prepExpr) as avg_prep")
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => [
                'd' => $r->d,
                'tickets' => (int) $r->tickets,
                'avg_prep' => $r->avg_prep !== null ? round((float) $r->avg_prep, 1) : null,
            ])->values();

        $byStaff = (clone $base)
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''), '—') as staff_name,
                COUNT(*) as tickets,
                SUM(k.status = 'completed') as completed,
                AVG($prepExpr) as avg_prep")
            ->groupBy('staff_name')->orderByDesc('tickets')->limit(15)->get()
            ->map(fn ($r) => [
                'staff_name' => $r->staff_name,
                'tickets' => (int) $r->tickets,
                'completed' => (int) $r->completed,
                'avg_prep' => $r->avg_prep !== null ? round((float) $r->avg_prep, 1) : null,
            ])->values();

        $topProducts = \DB::table('kitchen_orders as k')
            ->join('sale_details as sd', 'sd.sale_id', '=', 'k.sale_id')
            ->join('products as p', 'p.id', '=', 'sd.product_id')
            ->whereNull('k.deleted_at')
            ->whereBetween('k.created_at', $between)
            ->selectRaw('p.name, SUM(sd.quantity) as qty')
            ->groupBy('p.name')->orderByDesc('qty')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (float) $r->qty])->values();

        return response()->json([
            'report' => $rows,
            'totalRows' => $totalRows,
            'kpis' => [
                'tickets' => (int) ($kpi->tickets ?? 0),
                'completed' => (int) ($kpi->completed ?? 0),
                'avg_wait' => $kpi && $kpi->avg_wait !== null ? round((float) $kpi->avg_wait, 1) : null,
                'avg_prep' => $kpi && $kpi->avg_prep !== null ? round((float) $kpi->avg_prep, 1) : null,
                'avg_total' => $kpi && $kpi->avg_total !== null ? round((float) $kpi->avg_total, 1) : null,
                'overdue' => $target ? (int) ($kpi->overdue ?? 0) : null,
                'target_minutes' => $target,
            ],
            'timeseries' => $timeseries,
            'by_staff' => $byStaff,
            'top_products' => $topProducts,
        ]);
    }

    /**
     * Create a kitchen ticket from a sale. Shared by the POS "Send to Kitchen" flow
     * (PosController) and the "Send Later" endpoint. Returns null if the sale is missing.
     */
    public static function createForSale(int $saleId, ?string $instructions = null, string $source = 'pos'): ?KitchenOrder
    {
        $sale = Sale::find($saleId);
        if (! $sale) {
            return null;
        }

        // Short daily call number: 1, 2, 3… resets each day. withTrashed so a
        // deleted ticket's token isn't reissued to a different order the same day.
        $token = (int) KitchenOrder::withTrashed()
            ->whereDate('created_at', now()->toDateString())
            ->max('token_number') + 1;

        return KitchenOrder::create([
            'sale_id' => $sale->id,
            'ref' => $sale->Ref,
            'token_number' => $token,
            'source' => in_array($source, ['pos', 'online', 'manual'], true) ? $source : 'pos',
            'client_id' => $sale->client_id,
            'warehouse_id' => $sale->warehouse_id,
            'user_id' => optional(Auth::user())->id,
            'status' => 'pending',
            'instructions' => $instructions !== null && $instructions !== '' ? $instructions : $sale->notes,
            'sent_at' => now(),
        ]);
    }

    /**
     * Shape a kitchen order for the frontend.
     */
    protected function mapOrder(KitchenOrder $o, bool $withItems = false): array
    {
        $payload = [
            'id' => $o->id,
            'sale_id' => $o->sale_id,
            'ref' => $o->ref,
            'token_number' => $o->token_number,
            'source' => $o->source ?: 'pos',
            // Sale rows are "deleted" by setting deleted_at (no SoftDeletes trait),
            // so a voided ticket is one whose sale carries a deleted_at stamp.
            'voided' => (bool) optional($o->sale)->deleted_at,
            'status' => $o->status,
            'customer_name' => optional($o->client)->name,
            'assigned_to' => $o->assigned_to,
            'assigned_name' => optional($o->assignedUser)->firstname
                ? trim(optional($o->assignedUser)->firstname.' '.optional($o->assignedUser)->lastname)
                : null,
            'instructions' => $o->instructions,
            'sent_at' => optional($o->sent_at)->toDateTimeString(),
            'started_at' => optional($o->started_at)->toDateTimeString(),
            'completed_at' => optional($o->completed_at)->toDateTimeString(),
            'created_at' => optional($o->created_at)->toDateTimeString(),
            // Display-only dispatch marker
            'dispatched_warehouse_id' => $o->dispatched_warehouse_id,
            'dispatched_warehouse_name' => optional($o->dispatchedWarehouse)->name,
            'dispatched_at' => optional($o->dispatched_at)->toDateTimeString(),
        ];

        if ($withItems) {
            $payload['items'] = $this->itemsForSale($o->sale_id, (array) ($o->item_states ?? []));
        }

        return $payload;
    }

    /**
     * Read the line items for a sale from sale_details, resolving product / variant / unit names.
     * $itemStates marks lines the kitchen already bumped ({sale_detail_id: true}).
     */
    protected function itemsForSale($saleId, array $itemStates = []): array
    {
        $details = SaleDetail::with('product')
            ->where('sale_id', $saleId)
            ->get();

        $unitCache = [];
        $variantCache = [];

        return $details->map(function (SaleDetail $d) use (&$unitCache, &$variantCache, $itemStates) {
            $name = optional($d->product)->name ?? ('#'.$d->product_id);

            if ($d->product_variant_id) {
                if (! array_key_exists($d->product_variant_id, $variantCache)) {
                    $variantCache[$d->product_variant_id] = ProductVariant::find($d->product_variant_id);
                }
                $variant = $variantCache[$d->product_variant_id];
                if ($variant) {
                    $name .= ' - '.$variant->name;
                }
            }

            $unitName = null;
            if ($d->sale_unit_id) {
                if (! array_key_exists($d->sale_unit_id, $unitCache)) {
                    $unitCache[$d->sale_unit_id] = Unit::find($d->sale_unit_id);
                }
                $unit = $unitCache[$d->sale_unit_id];
                $unitName = $unit ? ($unit->ShortName ?: $unit->name) : null;
            }

            return [
                'id' => $d->id,
                'product_id' => $d->product_id,
                'category_id' => optional($d->product)->category_id,
                'name' => $name,
                'quantity' => (float) $d->quantity,
                'unit' => $unitName,
                'price' => (float) $d->price,
                'total' => (float) $d->total,
                'done' => ! empty($itemStates[(string) $d->id]),
            ];
        })->values()->all();
    }
}
