<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\FitmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle Fitment admin API: the make/model catalog, per-product fitment
 * rows, and the lookup/compatibility endpoints used by the POS.
 * Authorization piggybacks on the Product policy — fitment is product data.
 */
class VehicleFitmentController extends Controller
{
    public function __construct(private FitmentService $fitment)
    {
    }

    /** GET vehicles/catalog — makes with their models, for the admin page. */
    public function catalog(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $makes = VehicleMake::with(['models' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'active' => $m->active,
                'models' => $m->models->map(fn ($mo) => [
                    'id' => $mo->id,
                    'name' => $mo->name,
                    'active' => $mo->active,
                ])->values(),
            ]);

        return response()->json([
            'makes' => $makes,
            'enabled' => $this->fitment->enabled(),
        ]);
    }

    /** GET vehicles/lookup — active makes + models for cascading selects.
     *  Read-only, non-sensitive; any authenticated user (e.g. a POS cashier
     *  without products_view) may call it. */
    public function lookup(Request $request)
    {
        abort_unless($request->user('api'), 401);

        return response()->json([
            'enabled' => $this->fitment->enabled(),
            'makes' => VehicleMake::where('active', true)->orderBy('name')->get(['id', 'name']),
            'models' => VehicleModel::where('active', true)->orderBy('name')
                ->get(['id', 'vehicle_make_id', 'name']),
        ]);
    }

    public function storeMake(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $data = $request->validate(['name' => 'required|string|max:100|unique:vehicle_makes,name']);

        return response()->json(VehicleMake::create(['name' => trim($data['name'])]), 201);
    }

    public function updateMake(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $make = VehicleMake::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_makes,name,'.$make->id,
            'active' => 'nullable|boolean',
        ]);

        $make->update([
            'name' => trim($data['name']),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : $make->active,
        ]);

        return response()->json($make);
    }

    public function destroyMake(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $make = VehicleMake::findOrFail($id);

        DB::transaction(function () use ($make) {
            $modelIds = VehicleModel::where('vehicle_make_id', $make->id)->pluck('id');
            ProductFitment::where('vehicle_make_id', $make->id)->delete();
            \App\Models\CustomerVehicle::where('vehicle_make_id', $make->id)->delete();
            VehicleModel::whereIn('id', $modelIds)->delete();
            $make->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function storeModel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $data = $request->validate([
            'vehicle_make_id' => 'required|integer|exists:vehicle_makes,id',
            'name' => 'required|string|max:100',
        ]);

        $exists = VehicleModel::where('vehicle_make_id', $data['vehicle_make_id'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->exists();
        if ($exists) {
            return response()->json(['errors' => ['name' => [__('messages.AlreadyExists') ?: 'Already exists.']]], 422);
        }

        return response()->json(VehicleModel::create([
            'vehicle_make_id' => (int) $data['vehicle_make_id'],
            'name' => trim($data['name']),
        ]), 201);
    }

    public function updateModel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $model = VehicleModel::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'active' => 'nullable|boolean',
        ]);

        $model->update([
            'name' => trim($data['name']),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : $model->active,
        ]);

        return response()->json($model);
    }

    public function destroyModel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $model = VehicleModel::findOrFail($id);

        DB::transaction(function () use ($model) {
            ProductFitment::where('vehicle_model_id', $model->id)->delete();
            \App\Models\CustomerVehicle::where('vehicle_model_id', $model->id)->delete();
            $model->delete();
        });

        return response()->json(['ok' => true]);
    }

    /** GET products/{id}/fitments — rows + labels for the drawer. */
    public function productFitments(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        $product = Product::whereNull('deleted_at')->findOrFail($id);

        $rows = ProductFitment::with(['make:id,name', 'model:id,name'])
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'vehicle_make_id' => $f->vehicle_make_id,
                'vehicle_model_id' => $f->vehicle_model_id,
                'make_name' => $f->make?->name,
                'model_name' => $f->model?->name,
                'year_from' => $f->year_from,
                'year_to' => $f->year_to,
                'engine' => $f->engine,
                'notes' => $f->notes,
            ]);

        return response()->json([
            'product' => ['id' => $product->id, 'name' => $product->name, 'code' => $product->code],
            'fitments' => $rows,
        ]);
    }

    /** PUT products/{id}/fitments — replace-all save of the fitment rows. */
    public function saveProductFitments(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        $product = Product::whereNull('deleted_at')->findOrFail($id);

        $data = $request->validate([
            'fitments' => 'nullable|array|max:200',
            'fitments.*.vehicle_make_id' => 'required|integer|exists:vehicle_makes,id',
            'fitments.*.vehicle_model_id' => 'nullable|integer|exists:vehicle_models,id',
            'fitments.*.year_from' => 'nullable|integer|min:1900|max:2100',
            'fitments.*.year_to' => 'nullable|integer|min:1900|max:2100',
            'fitments.*.engine' => 'nullable|string|max:100',
            'fitments.*.notes' => 'nullable|string|max:255',
        ]);

        $rows = [];
        foreach (($data['fitments'] ?? []) as $f) {
            $makeId = (int) $f['vehicle_make_id'];
            $modelId = ! empty($f['vehicle_model_id']) ? (int) $f['vehicle_model_id'] : null;

            // A model must belong to its make.
            if ($modelId && ! VehicleModel::where('id', $modelId)->where('vehicle_make_id', $makeId)->exists()) {
                return response()->json(['error' => __('messages.InvalidData') ?: 'Invalid model for make.'], 422);
            }

            $from = ! empty($f['year_from']) ? (int) $f['year_from'] : null;
            $to = ! empty($f['year_to']) ? (int) $f['year_to'] : null;
            if ($from && $to && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            $rows[] = [
                'product_id' => $product->id,
                'vehicle_make_id' => $makeId,
                'vehicle_model_id' => $modelId,
                'year_from' => $from,
                'year_to' => $to,
                'engine' => isset($f['engine']) && trim((string) $f['engine']) !== '' ? trim($f['engine']) : null,
                'notes' => isset($f['notes']) && trim((string) $f['notes']) !== '' ? trim($f['notes']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($product, $rows) {
            ProductFitment::where('product_id', $product->id)->delete();
            if ($rows) {
                ProductFitment::insert($rows);
            }
        });

        return $this->productFitments($request, $product->id);
    }

    /**
     * POST vehicles/incompatible_ids {make_id, model_id, year?, engine?}
     * → ids of products that have fitment data and do NOT fit the vehicle.
     * The POS hides these from its preloaded grid (universal products are
     * never in the list, so they stay visible).
     */
    public function incompatibleIds(Request $request)
    {
        // Read-only compatibility data for the POS grid — any authenticated
        // user (cashiers included) may call it.
        abort_unless($request->user('api'), 401);

        $vehicle = $this->fitment->normalizeVehicle($request->all());
        if (! $vehicle) {
            return response()->json(['error' => __('messages.InvalidData') ?: 'Invalid vehicle.'], 422);
        }

        $fitted = ProductFitment::query()
            ->select('product_id')->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $compatible = $this->fitment->compatibleIdsAmong($fitted, $vehicle);
        $incompatible = array_values(array_diff($fitted, $compatible));

        return response()->json([
            'vehicle' => $vehicle,
            'incompatible_ids' => $incompatible,
        ]);
    }
}
