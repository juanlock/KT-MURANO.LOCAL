<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlashSalesController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = FlashSale::withCount('products')->orderBy('sort_order')->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        $sales = $query->get()->map(fn (FlashSale $f) => [
            'id' => $f->id,
            'name' => $f->name,
            'name_translations' => (object) ($f->name_translations ?? []),
            'is_active' => (bool) $f->is_active,
            'starts_at' => optional($f->starts_at)->toDateTimeString(),
            'ends_at' => optional($f->ends_at)->toDateTimeString(),
            'sort_order' => (int) $f->sort_order,
            'products_count' => $f->products_count,
            'is_running' => $f->isRunning(),
        ]);

        return response()->json([
            'flash_sales' => $sales,
            'locales' => store_locales(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $sale = FlashSale::with('products:id,name,code,price')->findOrFail($id);

        return response()->json([
            'id' => $sale->id,
            'name' => $sale->name,
            'name_translations' => (object) ($sale->name_translations ?? []),
            'locales' => store_locales(),
            'is_active' => (bool) $sale->is_active,
            'starts_at' => optional($sale->starts_at)->toDateTimeString(),
            'ends_at' => optional($sale->ends_at)->toDateTimeString(),
            'sort_order' => (int) $sale->sort_order,
            'products' => $sale->products->map(fn ($p) => [
                'product_id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'price' => (float) $p->price,
                'discount_type' => $p->pivot->discount_type,
                'discount_value' => (float) $p->pivot->discount_value,
                'flash_price' => FlashSale::applyDiscount((float) $p->price, $p->pivot->discount_type, (float) $p->pivot->discount_value),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $this->validateData($request);

        $sale = DB::transaction(function () use ($data) {
            $sale = FlashSale::create([
                'name' => $data['name'],
                'name_translations' => $data['name_translations'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $this->syncProducts($sale, $data['products'] ?? []);

            return $sale;
        });

        return response()->json(['success' => true, 'id' => $sale->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $sale = FlashSale::findOrFail($id);
        $data = $this->validateData($request);

        DB::transaction(function () use ($sale, $data) {
            $sale->update([
                'name' => $data['name'],
                'name_translations' => $data['name_translations'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $this->syncProducts($sale, $data['products'] ?? []);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        FlashSale::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /** Product typeahead for the admin builder. */
    public function searchProducts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $s = trim((string) $request->input('q', ''));

        $products = Product::query()
            ->when($s !== '', fn ($q) => $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%");
            }))
            ->limit(20)
            ->get(['id', 'name', 'code', 'price']);

        return response()->json(['products' => $products]);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'name_translations' => ['nullable', 'array'],
            'name_translations.*' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.discount_type' => ['required', 'in:percent,fixed'],
            'products.*.discount_value' => ['required', 'numeric', 'min:0'],
        ]);

        // Keep only locales the storefront can render, and drop blanks so the
        // model falls back to `name` for those languages.
        $translations = [];
        foreach ($data['name_translations'] ?? [] as $locale => $value) {
            if (isset(store_locales()[$locale]) && trim((string) $value) !== '') {
                $translations[$locale] = trim((string) $value);
            }
        }
        $data['name_translations'] = $translations ?: null;

        return $data;
    }

    private function syncProducts(FlashSale $sale, array $products): void
    {
        $sync = [];
        $i = 0;
        foreach ($products as $p) {
            $sync[(int) $p['product_id']] = [
                'discount_type' => $p['discount_type'],
                'discount_value' => $p['discount_value'],
                'sort_order' => $i++,
            ];
        }
        $sale->products()->sync($sync);
    }
}
