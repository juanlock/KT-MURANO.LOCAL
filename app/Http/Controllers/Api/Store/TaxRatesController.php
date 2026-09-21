<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Models\TaxRate;
use Illuminate\Http\Request;

class TaxRatesController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = TaxRate::orderBy('country')->orderBy('state');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('country', 'like', "%{$s}%")
                  ->orWhere('state', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%");
            });
        }

        $rates = $query->get()->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'country' => $r->country,
            'state' => $r->state,
            'rate' => (float) $r->rate,
            'active' => (bool) $r->active,
        ]);

        return response()->json([
            'rates' => $rates,
            // The canonical list + subdivisions the location matcher understands.
            'country_options' => \App\Services\CountryService::options(),
            'subdivisions' => \App\Services\CountryService::subdivisionMap(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $this->validateData($request);
        $rate = TaxRate::create($data);

        return response()->json(['success' => true, 'id' => $rate->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $rate = TaxRate::findOrFail($id);
        $rate->update($this->validateData($request));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        TaxRate::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
