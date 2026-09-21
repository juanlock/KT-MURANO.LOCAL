<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingMethodsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = ShippingMethod::with('regions')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        $methods = $query->get()->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'price' => (float) $m->price,
            'active' => (bool) $m->active,
            'sort_order' => (int) $m->sort_order,
            'countries' => $m->regions->pluck('country')->values(),
        ]);

        return response()->json([
            'methods' => $methods,
            // The canonical list the region matcher understands, so the picker
            // cannot produce a country checkout will fail to recognise.
            'country_options' => \App\Services\CountryService::options(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $this->validateData($request);

        $method = DB::transaction(function () use ($data) {
            $method = ShippingMethod::create([
                'name' => $data['name'],
                'price' => $data['price'],
                'active' => $data['active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $this->syncRegions($method, $data['countries'] ?? []);

            return $method;
        });

        return response()->json(['success' => true, 'id' => $method->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $method = ShippingMethod::findOrFail($id);
        $data = $this->validateData($request);

        DB::transaction(function () use ($method, $data) {
            $method->update([
                'name' => $data['name'],
                'price' => $data['price'],
                'active' => $data['active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $this->syncRegions($method, $data['countries'] ?? []);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        ShippingMethod::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:100'],
        ]);
    }

    private function syncRegions(ShippingMethod $method, array $countries): void
    {
        $method->regions()->delete();

        $clean = collect($countries)
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->unique()
            ->values();

        foreach ($clean as $country) {
            $method->regions()->create(['country' => $country]);
        }
    }
}
