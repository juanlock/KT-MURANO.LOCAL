<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreCoupon;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreCouponsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = StoreCoupon::withCount('redemptions')->orderByDesc('id');

        if ($request->filled('search')) {
            $query->where('code', 'like', '%'.$request->search.'%');
        }

        $coupons = $query->get()->map(fn (StoreCoupon $c) => $this->present($c));

        return response()->json(['coupons' => $coupons]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $this->validateData($request, null);
        $coupon = StoreCoupon::create($data);

        return response()->json(['success' => true, 'id' => $coupon->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $coupon = StoreCoupon::findOrFail($id);
        $coupon->update($this->validateData($request, $coupon->id));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        StoreCoupon::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request, ?int $id): array
    {
        if ($request->has('enabled')) {
            $request->merge(['enabled' => (int) filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN)]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('store_coupons', 'code')->ignore($id)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:190'],
            'type' => ['required', Rule::in(StoreCoupon::TYPES)],
            'value' => ['required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'enabled' => ['nullable', 'in:0,1'],
        ]);

        // Percentage values are a 0–100 rate.
        if ($data['type'] === 'percentage' && $data['value'] > 100) {
            $data['value'] = 100;
        }

        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }

    private function present(StoreCoupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'description' => $c->description,
            'type' => $c->type,
            'value' => (float) $c->value,
            'min_order_amount' => $c->min_order_amount !== null ? (float) $c->min_order_amount : null,
            'max_discount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'usage_limit' => $c->usage_limit,
            'used_count' => (int) $c->used_count,
            'redemptions_count' => (int) $c->redemptions_count,
            'per_customer_limit' => $c->per_customer_limit,
            'starts_at' => optional($c->starts_at)->format('Y-m-d\TH:i'),
            'ends_at' => optional($c->ends_at)->format('Y-m-d\TH:i'),
            'enabled' => (bool) $c->enabled,
        ];
    }
}
