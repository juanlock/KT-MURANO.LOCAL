<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\StoreSetting;
use App\Services\CountryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shipping zones and the rate rules inside them.
 *
 * A rate is a row of shipping_methods with a shipping_zone_id — the same table
 * an order's shipping_method_id points at, so a placed order keeps a valid
 * reference to the rate it was charged.
 */
class ShippingZonesController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $zones = ShippingZone::with(['locations', 'rates' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ShippingZone $z) => $this->present($z));

        return response()->json([
            'zones' => $zones,
            'countries' => CountryService::options(),
            'subdivisions' => CountryService::subdivisionMap(),
            'rate_types' => ShippingMethod::RATE_TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', StoreSetting::class);

        $data = $this->validateZone($request);

        $zone = DB::transaction(function () use ($data) {
            $zone = ShippingZone::create([
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $this->syncLocations($zone, $data['locations'] ?? []);

            return $zone;
        });

        return response()->json(['success' => true, 'id' => $zone->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StoreSetting::class);

        $zone = ShippingZone::findOrFail($id);
        $data = $this->validateZone($request);

        DB::transaction(function () use ($zone, $data) {
            $zone->update([
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $this->syncLocations($zone, $data['locations'] ?? []);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', StoreSetting::class);

        $zone = ShippingZone::findOrFail($id);

        // Rates are referenced by placed orders, so they are deactivated and
        // detached rather than deleted — order history must stay readable.
        $zone->rates()->update(['active' => false, 'shipping_zone_id' => null]);
        $zone->locations()->delete();
        $zone->delete();

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------- rates

    public function storeRate(Request $request, $zoneId)
    {
        $this->authorizeForUser($request->user('api'), 'update', StoreSetting::class);

        $zone = ShippingZone::findOrFail($zoneId);
        $data = $this->validateRate($request);

        $rate = ShippingMethod::create($data + ['shipping_zone_id' => $zone->id]);

        return response()->json(['success' => true, 'id' => $rate->id], 201);
    }

    public function updateRate(Request $request, $zoneId, $rateId)
    {
        $this->authorizeForUser($request->user('api'), 'update', StoreSetting::class);

        $rate = ShippingMethod::where('shipping_zone_id', $zoneId)->findOrFail($rateId);
        $rate->update($this->validateRate($request));

        return response()->json(['success' => true]);
    }

    public function destroyRate(Request $request, $zoneId, $rateId)
    {
        $this->authorizeForUser($request->user('api'), 'delete', StoreSetting::class);

        $rate = ShippingMethod::where('shipping_zone_id', $zoneId)->findOrFail($rateId);
        // Soft delete keeps any order that used this rate intact.
        $rate->delete();

        return response()->json(['success' => true]);
    }

    // -------------------------------------------------------------- helpers

    private function validateZone(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'locations' => ['nullable', 'array'],
            // A blank country marks the catch-all ("Rest of the world").
            'locations.*.country' => ['nullable', 'string', 'max:100'],
            'locations.*.state' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function validateRate(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'rate_type' => ['required', Rule::in(ShippingMethod::RATE_TYPES)],
            'min_value' => ['nullable', 'numeric', 'min:0'],
            'max_value' => ['nullable', 'numeric', 'min:0', 'gte:min_value'],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // A flat rate has no threshold; storing one would only mislead.
        if (($data['rate_type'] ?? 'flat') === 'flat') {
            $data['min_value'] = null;
            $data['max_value'] = null;
        }

        $data['active'] = $data['active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function syncLocations(ShippingZone $zone, array $locations): void
    {
        $zone->locations()->delete();

        $seen = [];
        foreach ($locations as $location) {
            $country = trim((string) ($location['country'] ?? ''));
            $state = trim((string) ($location['state'] ?? ''));

            // Store the canonical country name so the matcher and the admin
            // list agree; blank stays blank (the catch-all).
            if ($country !== '') {
                $code = CountryService::toCode($country);
                $country = $code ? CountryService::name($code, 'en') : $country;
            }

            $key = mb_strtolower($country.'|'.$state);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $zone->locations()->create([
                'country' => $country !== '' ? $country : null,
                'state' => $state !== '' ? $state : null,
            ]);
        }
    }

    private function present(ShippingZone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'sort_order' => (int) $zone->sort_order,
            'is_catch_all' => $zone->isCatchAll(),
            'locations' => $zone->locations->map(fn ($l) => [
                'country' => $l->country,
                'state' => $l->state,
            ])->values(),
            'rates' => $zone->rates->map(fn (ShippingMethod $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'price' => (float) $r->price,
                'rate_type' => $r->rate_type ?: 'flat',
                'min_value' => $r->min_value === null ? null : (float) $r->min_value,
                'max_value' => $r->max_value === null ? null : (float) $r->max_value,
                'active' => (bool) $r->active,
                'sort_order' => (int) $r->sort_order,
            ])->values(),
        ];
    }
}
