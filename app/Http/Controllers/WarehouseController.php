<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ScopesToCompany;

    // ----------- GET ALL  Warehouse --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;

        $user = $request->user('api');

        $warehouses = Warehouse::where('deleted_at', '=', null)
            // Company boundary: a SuperAdmin sees every company (optionally
            // narrowed via ?company_id=); everyone else only their own.
            ->when($user->type !== 'SuperAdmin' || $request->filled('company_id'), function ($query) use ($request) {
                return $query->where('company_id', $this->resolveActingCompanyId($request));
            })

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('mobile', 'LIKE', "%{$request->search}%")
                        ->orWhere('country', 'LIKE', "%{$request->search}%")
                        ->orWhere('city', 'LIKE', "%{$request->search}%")
                        ->orWhere('zip', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $warehouses->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $warehouses = $warehouses->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'warehouses' => $warehouses,
            'totalRows' => $totalRows,
        ]);
    }

    // ----------- Store new Warehouse --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Warehouse::class);

        request()->validate([
            'name' => 'required',
        ]);

        $companyId = $this->resolveActingCompanyId($request);

        \DB::transaction(function () use ($request, $companyId) {

            $Warehouse = new Warehouse;
            $Warehouse->name = $request['name'];
            $Warehouse->company_id = $companyId;
            $Warehouse->mobile = $request['mobile'];
            $Warehouse->country = $request['country'];
            $Warehouse->city = $request['city'];
            $Warehouse->zip = $request['zip'];
            $Warehouse->email = $request['email'];
            $Warehouse->save();

            // Only seed opening stock for THIS company's own products -- a
            // new warehouse belongs to one company, so creating
            // product_warehouse rows for every company's products would
            // leak stock-tracking rows across the company boundary.
            $products = Product::where('deleted_at', '=', null)->where('company_id', $companyId)->get(['id', 'type']);

            if ($products) {
                foreach ($products as $product) {
                    $product_warehouse = [];
                    $Product_Variants = ProductVariant::where('product_id', $product->id)
                        ->where('deleted_at', null)
                        ->get();

                    if ($Product_Variants->isNotEmpty()) {
                        foreach ($Product_Variants as $product_variant) {

                            $product_warehouse[] = [
                                'product_id' => $product->id,
                                'warehouse_id' => $Warehouse->id,
                                'product_variant_id' => $product_variant->id,
                                'manage_stock' => $product->type == 'is_service' ? 0 : 1,
                            ];
                        }
                    } else {
                        $product_warehouse[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $Warehouse->id,
                            'product_variant_id' => null,
                            'manage_stock' => $product->type == 'is_service' ? 0 : 1,
                        ];
                    }

                    product_warehouse::insert($product_warehouse);
                }
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -----------Update Warehouse --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        // Company boundary: a non-SuperAdmin can't update a warehouse from
        // a different company.
        $user = $request->user('api');
        if ($user->type !== 'SuperAdmin') {
            $targetCompanyId = Warehouse::where('id', $id)->value('company_id');
            abort_unless((int) $targetCompanyId === (int) $user->company_id, 403);
        }

        request()->validate([
            'name' => 'required',
        ]);

        Warehouse::whereId($id)->update([
            'name' => $request['name'],
            'mobile' => $request['mobile'],
            'country' => $request['country'],
            'city' => $request['city'],
            'zip' => $request['zip'],
            'email' => $request['email'],
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  Warehouse --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        // Company boundary: a non-SuperAdmin can't delete a warehouse from
        // a different company.
        $user = $request->user('api');
        if ($user->type !== 'SuperAdmin') {
            $targetCompanyId = Warehouse::where('id', $id)->value('company_id');
            abort_unless((int) $targetCompanyId === (int) $user->company_id, 403);
        }

        \DB::transaction(function () use ($id) {

            Warehouse::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            product_warehouse::where('warehouse_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        $selectedIds = $request->selectedIds;

        // Company boundary: a non-SuperAdmin can only bulk-delete
        // warehouses belonging to their own company.
        $user = $request->user('api');
        if ($user->type !== 'SuperAdmin') {
            $selectedIds = Warehouse::whereIn('id', $selectedIds)->where('company_id', $user->company_id)->pluck('id')->all();
        }

        \DB::transaction(function () use ($selectedIds) {
            foreach ($selectedIds as $warehouse_id) {
                Warehouse::whereId($warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);

                product_warehouse::where('warehouse_id', $warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ----------- GET ALL  Warehouse --------------\\

    public function Get_Warehouses(Request $request)
    {
        $user = $request->user('api');

        $query = Warehouse::where('deleted_at', '=', null);
        if ($user->type !== 'SuperAdmin') {
            $query->where('company_id', $user->company_id);
        }

        return response()->json($query->get());
    }
}
