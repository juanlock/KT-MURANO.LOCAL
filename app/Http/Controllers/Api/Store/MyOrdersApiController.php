<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MyOrdersApiController extends Controller
{
    /**
     * GET /store/my/orders
     * Query: page, per_page, q (ref), status (pending|confirmed|cancelled), from, to, sort, dir
     */
    public function index(Request $req)
    {
        $user = Auth::guard('store')->user();
        abort_unless($user, 401);
        // A store account with no linked client must never see any order.
        abort_unless($user->client_id, 403);

        $q = trim((string) $req->query('q', ''));
        $status = $req->query('status'); // pending|confirmed|shipped|delivered|cancelled
        $from = $req->query('from');
        $to = $req->query('to');
        $sort = $req->query('sort', 'created_at');
        $dir = $req->query('dir', 'desc');
        $per = (int) $req->query('per_page', 10);

        $allowedSort = ['created_at', 'total', 'ref', 'date'];

        $orders = OnlineOrder::query()
            ->with('warehouse:id,name')
            ->where('client_id', (int) $user->client_id)
            ->when($q !== '', fn ($qq) => $qq->where('ref', 'like', "%{$q}%"))
            ->when($status, fn ($qq) => $qq->where('status', $status))
            ->when($from, fn ($qq) => $qq->whereDate('date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('date', '<=', $to))
            ->when(in_array($sort, $allowedSort, true),
                fn ($qq) => $qq->orderBy($sort, $dir),
                fn ($qq) => $qq->latest())
            ->paginate($per);

        $rows = $orders->getCollection()->map(function (OnlineOrder $o) {
            return [
                'id' => $o->id,
                'code' => $o->ref,
                'status' => $o->status,
                'total' => (float) $o->total,
                'payment_method' => $o->payment_method ?? 'cod',
                'payment_status' => $o->payment_status ?? 'pending',
                'created_at' => optional($o->created_at)->toDateTimeString() ?: (string) $o->date,
                'warehouse_name' => optional($o->warehouse)->name,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $orders->total(),
                'page' => $orders->currentPage(),
                'pages' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * (Optional) GET /store/my/orders/{id}
     * Useful if you add an order drawer/page on the account side.
     */
    public function show($id, Request $request)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        // A store account with no linked client must never see any order.
        abort_unless($user->client_id, 403);

        // Only allow the customer to see their own order (404 otherwise).
        $order = OnlineOrder::with(['items.product', 'items.productVariant', 'client', 'warehouse', 'pickupBranch', 'paymentProofs'])
            ->where('id', $id)
            ->where('client_id', $user->client_id)
            ->firstOrFail();

        // Prefer the totals frozen on the order; fall back to line sum for legacy orders.
        $subtotal = (float) $order->subtotal > 0
            ? (float) $order->subtotal
            : (float) $order->items->reduce(function ($a, $i) {
                return $a + ((float) $i->price * (float) $i->qty);
            }, 0.0);

        // Normalize date/time to strings (works whether date is Carbon or string)
        $dateStr = method_exists($order->date, 'toDateString')
            ? $order->date->toDateString()
            : (string) $order->date;

        $timeStr = (string) $order->time;

        return response()->json([
            'id' => $order->id,
            'code' => $order->ref,
            'status' => $order->status,                 // pending|confirmed|cancelled
            'date' => $dateStr,
            'time' => $timeStr,

            'warehouse_id' => $order->warehouse_id,
            'warehouse_name' => optional($order->warehouse)->name,

            'customer_name' => $order->customer_name ?: optional($order->client)->name,
            'customer_email' => $order->customer_email ?: optional($order->client)->email,
            'customer_phone' => $order->customer_phone ?: optional($order->client)->phone,
            'shipping_address' => $order->shipping_address ?: optional($order->client)->adresse,
            'shipping_city' => $order->shipping_city,
            'shipping_state' => $order->shipping_state,
            'shipping_zip' => $order->shipping_zip,
            'shipping_country' => $order->shipping_country,

            'subtotal' => $subtotal,
            'tax' => (float) $order->tax,
            'tax_rate' => (float) $order->tax_rate,
            'shipping' => (float) $order->shipping_cost,
            'shipping_method_name' => $order->shipping_method_name,
            'discount' => 0.0,
            'total' => (float) $order->total,
            'payment_method' => $order->payment_method ?? 'cod',
            'payment_status' => $order->payment_status ?? 'pending',

            // Pickup orders are collected at a branch instead of shipped.
            'delivery_method' => $order->delivery_method ?? 'ship',
            'pickup_branch' => $order->isPickup() ? $this->pickupBranchInfo($order) : null,

            // Offline payment: the account details to pay to + what was sent.
            'payment_instructions' => \App\Services\StorePaymentMethodService::get($order->payment_method),
            'payment_proofs' => $order->paymentProofs->sortByDesc('id')->map(fn ($p) => [
                'id' => $p->id,
                'reference_number' => $p->reference_number,
                'amount' => (float) $p->amount,
                'paid_at' => optional($p->paid_at)->toDateString(),
                'status' => $p->status,
                'reject_reason' => $p->reject_reason,
                'file_url' => $p->fileUrl(),
                'submitted_at' => optional($p->created_at)->toDateTimeString(),
            ])->values(),
            'can_submit_proof' => \App\Services\StorePaymentMethodService::requiresProof($order->payment_method)
                && ($order->payment_status ?? 'pending') !== 'paid'
                && $order->status !== 'cancelled'
                && ! $order->paymentProofs->contains('status', 'pending'),

            'items' => $order->items->map(function ($d) {
                $name = optional($d->product)->name ?? ('#'.$d->product_id);
                $variant = optional($d->productVariant)->name;

                return [
                    'id' => $d->id,
                    'product_id' => $d->product_id,
                    'product_variant_id' => $d->product_variant_id,
                    'name' => $variant ? ($name.' - '.$variant) : $name,
                    'qty' => (float) $d->qty,
                    'price' => (float) $d->price,
                    'line_total' => (float) $d->line_total,
                ];
            })->values(),
        ]);
    }

    /** Branch address/hours the shopper needs in order to collect. */
    protected function pickupBranchInfo(OnlineOrder $order): array
    {
        $branch = \App\Models\StorePickupBranch::where('warehouse_id', $order->pickup_branch_id)->first();
        $warehouse = $order->pickupBranch;

        return [
            'id' => $order->pickup_branch_id,
            'name' => optional($warehouse)->name,
            'address' => $branch->address ?? trim(implode(', ', array_filter([optional($warehouse)->city, optional($warehouse)->country]))),
            'hours' => $branch->hours ?? null,
            'contact' => $branch->contact ?? optional($warehouse)->mobile,
            'notes' => $branch->notes ?? null,
        ];
    }
}
