<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Permission;
use App\Models\Warehouse;
use App\Support\CompanyNames;
use App\Traits\ScopesWarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hospital Pharmacy (2026-10-21): the medicines and supplies the hospital has
 * -- the company's products with the pharmacy fields (generic name, strength,
 * form, prescription), stock summed over the warehouses the user may see, the
 * nearest batch expiry and alerts (low / out of stock, expiring). Read only:
 * stock moves through Purchases / Adjustments / Sales as for any product.
 * Permission hms_pharmacy.
 */
class HospitalPharmacyController extends Controller
{
    use ScopesToCompany, ScopesWarehouseAccess;

    private const EXPIRY_DAYS = 90;

    public function index(Request $request)
    {
        $user = $request->user('api');
        $perm = Permission::where('name', 'hms_pharmacy')->first();
        abort_unless($user && $perm && $user->hasRole($perm->roles), 403);

        $cid = $this->listCompanyId($request);
        $warehouses = Warehouse::whereNull('deleted_at')->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->pluck('id')->all();
        if ($user->type !== 'SuperAdmin') {
            $warehouses = array_values(array_intersect($warehouses, $this->userWarehouseIds()));
        }
        $wh = $warehouses ?: [0];
        $in = implode(',', array_map('intval', $wh));
        $limitDate = now()->addDays(self::EXPIRY_DAYS)->toDateString();
        $today = now()->toDateString();

        $stockSql = "(SELECT COALESCE(SUM(pw.qte),0) FROM product_warehouse pw WHERE pw.product_id = p.id AND pw.deleted_at IS NULL AND pw.warehouse_id IN ($in))";
        $expirySql = "(SELECT MIN(b.expiry_date) FROM product_batches b WHERE b.product_id = p.id AND b.deleted_at IS NULL AND b.qty > 0 AND b.expiry_date IS NOT NULL AND b.warehouse_id IN ($in))";

        $q = DB::table('products as p')->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->select('p.id', 'p.company_id', 'p.code', 'p.name', 'p.generic_name', 'p.strength', 'p.dosage_form', 'p.pack_size',
                'p.manufacturer', 'p.prescription_required', 'p.drug_schedule', 'p.price', 'p.cost', 'p.stock_alert', 'p.type',
                'c.name as category', 'u.ShortName as unit')
            ->selectRaw("$stockSql as stock")
            ->selectRaw("$expirySql as next_expiry");
        if ($cid !== null) {
            $q->where('p.company_id', $cid);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('p.name', 'LIKE', "%{$s}%")->orWhere('p.generic_name', 'LIKE', "%{$s}%")
                ->orWhere('p.code', 'LIKE', "%{$s}%")->orWhere('p.manufacturer', 'LIKE', "%{$s}%"));
        }
        if ($request->filled('category_id')) {
            $q->where('p.category_id', $request->category_id);
        }
        if ($request->boolean('rx_only')) {
            $q->where('p.prescription_required', 1);
        }

        $rows = $q->orderBy('p.name')->get()->map(function ($r) use ($limitDate, $today) {
            $r->stock = (float) $r->stock;
            $r->price = (float) $r->price;
            $r->cost = (float) $r->cost;
            $r->stock_alert = (float) $r->stock_alert;
            $r->prescription_required = (bool) $r->prescription_required;
            $r->out_of_stock = $r->type !== 'is_service' && $r->stock <= 0;
            $r->low_stock = ! $r->out_of_stock && $r->stock_alert > 0 && $r->stock <= $r->stock_alert;
            $r->expired = $r->next_expiry && $r->next_expiry < $today;
            $r->expiring = ! $r->expired && $r->next_expiry && $r->next_expiry <= $limitDate;

            return $r;
        });

        $kpis = [
            'items' => $rows->count(),
            'in_stock' => $rows->where('stock', '>', 0)->count(),
            'low_stock' => $rows->where('low_stock', true)->count(),
            'out_of_stock' => $rows->where('out_of_stock', true)->count(),
            'expiring' => $rows->where('expiring', true)->count(),
            'expired' => $rows->where('expired', true)->count(),
            'stock_value' => round($rows->sum(fn ($r) => max(0, $r->stock) * $r->cost), 2),
            'expiry_days' => self::EXPIRY_DAYS,
        ];

        $filter = (string) $request->get('filter', '');
        $rows = match ($filter) {
            'in_stock' => $rows->where('stock', '>', 0),
            'low' => $rows->where('low_stock', true),
            'out' => $rows->where('out_of_stock', true),
            'expiring' => $rows->filter(fn ($r) => $r->expiring || $r->expired),
            default => $rows,
        };

        $categories = DB::table('categories')->whereNull('deleted_at')
            ->whereIn('id', DB::table('products')->whereNull('deleted_at')->when($cid !== null, fn ($w) => $w->where('company_id', $cid))->select('category_id'))
            ->orderBy('name')->get(['id', 'name']);

        $list = CompanyNames::attach($rows->values()->all());

        return response()->json(['medicines' => $list, 'kpis' => $kpis, 'categories' => $categories]);
    }

    /** One medicine's stock per warehouse and its batches (the row's detail). */
    public function show(Request $request, $id)
    {
        $user = $request->user('api');
        $perm = Permission::where('name', 'hms_pharmacy')->first();
        abort_unless($user && $perm && $user->hasRole($perm->roles), 403);

        $cid = $this->listCompanyId($request);
        $product = DB::table('products')->whereNull('deleted_at')->where('id', $id)
            ->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->first(['id', 'name', 'company_id']);
        abort_unless($product, 404);

        $warehouses = Warehouse::whereNull('deleted_at')->where('company_id', $product->company_id)->pluck('id')->all();
        if ($user->type !== 'SuperAdmin') {
            $warehouses = array_values(array_intersect($warehouses, $this->userWarehouseIds()));
        }

        return response()->json([
            'stock' => DB::table('product_warehouse as pw')->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
                ->whereNull('pw.deleted_at')->where('pw.product_id', $id)->whereIn('pw.warehouse_id', $warehouses ?: [0])
                ->groupBy('w.id', 'w.name')->orderBy('w.name')->selectRaw('w.name as warehouse, SUM(pw.qte) as qty')->get(),
            'batches' => DB::table('product_batches as b')->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
                ->whereNull('b.deleted_at')->where('b.product_id', $id)->whereIn('b.warehouse_id', $warehouses ?: [0])
                ->orderBy('b.expiry_date')->get(['b.batch_no', 'b.expiry_date', 'b.qty', 'b.status', 'w.name as warehouse']),
        ]);
    }
}
