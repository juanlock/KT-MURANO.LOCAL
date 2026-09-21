<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfitReportController extends Controller
{
    /**
     * Profit analysis grouped by one dimension:
     *   GET report/profit/{dimension}  — product | category | unit | customer | date | warehouse
     *
     * Line revenue = sale_details.total; line cost = quantity * COALESCE(variant
     * cost, product cost). Returns {rows, totalRows, kpis, chart, warehouses}.
     * KPIs and chart cover the WHOLE filtered set; rows are paginated.
     */
    public function index(Request $request, $dimension)
    {
        // Reports_profit lives on ClientPolicy (same as the Profit & Loss report).
        $this->authorizeForUser($request->user('api'), 'Reports_profit', Client::class);

        $dimensions = ['product', 'category', 'unit', 'customer', 'date', 'warehouse'];
        abort_unless(in_array($dimension, $dimensions, true), 404);

        // ---- Inputs
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) ($request->page ?? 1));
        $offset = $perPage === -1 ? 0 : ($page - 1) * $perPage;
        $order = $request->SortField ?: 'profit';
        $dir = strtolower($request->SortType ?: 'desc') === 'asc' ? 'asc' : 'desc';

        $today = Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : $today->copy()->subDays(29)->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : $today->toDateString();
        if ($from > $to) {
            $from = $to;
        }

        // ---- Warehouse scope
        $user = Auth::user();
        $allowed = null;
        if ($user && ! $user->is_all_warehouses) {
            $allowed = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
        }
        $warehouseId = $request->warehouse_id;
        if ($allowed !== null && $warehouseId && ! in_array((int) $warehouseId, $allowed, true)) {
            $warehouseId = null;
        }

        $costExpr = 'sd.quantity * COALESCE(pv.cost, p.cost, 0)';

        $base = fn () => DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->join('products as p', 'p.id', '=', 'sd.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'sd.product_variant_id')
            ->whereNull('s.deleted_at')
            ->whereBetween('s.date', [$from, $to])
            ->when($allowed !== null, fn ($q) => $q->whereIn('s.warehouse_id', $allowed))
            ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId));

        // ---- Dimension: label expression, joins and group key
        $dim = [
            'product' => ['label' => 'p.name', 'group' => 'p.id', 'join' => null, 'search' => 'p.name'],
            'category' => ['label' => "COALESCE(c.name, '—')", 'group' => 'p.category_id', 'join' => fn ($q) => $q->leftJoin('categories as c', 'c.id', '=', 'p.category_id'), 'search' => 'c.name'],
            'unit' => ['label' => "COALESCE(u.ShortName, '—')", 'group' => 'u.id', 'join' => fn ($q) => $q->leftJoin('units as u', function ($j) {
                $j->on(DB::raw('u.id'), '=', DB::raw('COALESCE(sd.sale_unit_id, p.unit_sale_id, p.unit_id)'));
            }), 'search' => 'u.ShortName'],
            'customer' => ['label' => 'cl.name', 'group' => 's.client_id', 'join' => fn ($q) => $q->join('clients as cl', 'cl.id', '=', 's.client_id'), 'search' => 'cl.name'],
            'date' => ['label' => 's.date', 'group' => 's.date', 'join' => null, 'search' => 's.date'],
            'warehouse' => ['label' => 'w.name', 'group' => 's.warehouse_id', 'join' => fn ($q) => $q->join('warehouses as w', 'w.id', '=', 's.warehouse_id'), 'search' => 'w.name'],
        ][$dimension];

        $grouped = fn () => tap($base(), function ($q) use ($dim, $request, $costExpr) {
            if ($dim['join']) {
                ($dim['join'])($q);
            }
            if ($request->filled('search')) {
                $q->where(DB::raw($dim['search']), 'LIKE', '%'.$request->search.'%');
            }
            $q->groupBy(DB::raw($dim['group']))
                ->selectRaw("{$dim['label']} as label,
                             COALESCE(SUM(sd.quantity),0) as qty,
                             COALESCE(SUM(sd.total),0) as revenue,
                             COALESCE(SUM({$costExpr}),0) as cost,
                             COALESCE(SUM(sd.total) - SUM({$costExpr}),0) as profit");
        });

        // ---- Total group count (explicit select, so the derived table is valid)
        $countQ = $grouped();
        $totalRows = DB::table(DB::raw("({$countQ->toSql()}) as x"))
            ->mergeBindings($countQ)
            ->count();

        // ---- Table rows (aliases are sortable in MySQL after GROUP BY)
        $sortable = ['label', 'qty', 'revenue', 'cost', 'profit'];
        $rowsQ = $grouped()->orderBy(in_array($order, $sortable, true) ? $order : 'profit', $dir);
        if ($perPage !== -1) {
            $rowsQ->offset($offset)->limit($perPage);
        }
        $rows = $rowsQ->get()->map(function ($r) {
            $r->qty = round((float) $r->qty, 2);
            $r->revenue = round((float) $r->revenue, 2);
            $r->cost = round((float) $r->cost, 2);
            $r->profit = round((float) $r->profit, 2);
            $r->margin = $r->revenue > 0 ? round($r->profit / $r->revenue * 100, 1) : 0;

            return $r;
        });

        // ---- KPIs over the whole filtered set
        $k = $base()->selectRaw("COALESCE(SUM(sd.total),0) as revenue, COALESCE(SUM({$costExpr}),0) as cost")->first();
        $kpis = [
            'revenue' => round((float) $k->revenue, 2),
            'cost' => round((float) $k->cost, 2),
            'profit' => round($k->revenue - $k->cost, 2),
            'margin' => $k->revenue > 0 ? round(($k->revenue - $k->cost) / $k->revenue * 100, 1) : 0,
        ];

        // ---- Chart: chronological for dates, top 10 by profit otherwise
        $chartQ = $grouped();
        $chart = ($dimension === 'date'
            ? $chartQ->orderBy('label', 'asc')->limit(180)
            : $chartQ->orderBy('profit', 'desc')->limit(10)
        )->get()->map(fn ($r) => [
            'label' => $r->label,
            'revenue' => round((float) $r->revenue, 2),
            'profit' => round((float) $r->profit, 2),
        ]);

        $warehouses = Warehouse::whereNull('deleted_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->get(['id', 'name']);

        return response()->json([
            'rows' => $rows,
            'totalRows' => $totalRows,
            'kpis' => $kpis,
            'chart' => $chart,
            'warehouses' => $warehouses,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
