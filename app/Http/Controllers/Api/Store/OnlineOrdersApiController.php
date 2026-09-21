<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\StoreSetting;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\CheckoutException;
use App\Services\OnlineOrderInvoiceService;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OnlineOrdersApiController extends Controller
{
    /**
     * GET /store/orders/{id}/invoice — admin downloads an order invoice.
     */
    public function invoice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $order = OnlineOrder::findOrFail($id);

        try {
            return app(OnlineOrderInvoiceService::class)
                ->pdf($order)
                ->download('invoice-'.$order->ref.'.pdf');
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }
    }

    /**
     * GET /store/orders
     * Query: page, per_page, q (Ref or customer), from, to, sort, dir
     * Note: online orders don’t have per-row status yet — we return "ordered".
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');            // YYYY-MM-DD
        $to = $request->query('to');              // YYYY-MM-DD
        $statusFilter = trim((string) $request->query('status', ''));
        $preorderFilter = $request->query('preorder', '');
        $sort = $request->query('sort', 'created_at');
        $dir = $request->query('dir', 'desc');
        $per = max(1, min(200, (int) $request->query('per_page', 10)));

        $allowedSort = ['date', 'created_at', 'total', 'ref', 'id'];

        $proofFilter = $request->query('proof', '');

        $orders = OnlineOrder::query()
            ->with('client')
            ->withCount(['paymentProofs as pending_proofs_count' => fn ($p) => $p->where('status', 'pending')])
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('ref', 'like', "%{$q}%")
                        ->orWhereHas('client', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                                ->orWhere('email', 'like', "%{$q}%")
                                ->orWhere('phone', 'like', "%{$q}%");
                        });
                });
            })
            ->when($statusFilter !== '' && in_array($statusFilter, ['pending', 'confirmed', 'cancelled'], true),
                fn ($qq) => $qq->where('status', $statusFilter))
            ->when($preorderFilter === 'yes', fn ($qq) => $qq->where('has_preorder_items', true))
            ->when($preorderFilter === 'no', fn ($qq) => $qq->where(function ($w) {
                $w->where('has_preorder_items', false)->orWhereNull('has_preorder_items');
            }))
            ->when($proofFilter === 'pending', fn ($qq) => $qq->whereHas('paymentProofs', fn ($p) => $p->where('status', 'pending')))
            ->when($from, fn ($qq) => $qq->whereDate('date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('date', '<=', $to))
            ->when(in_array($sort, $allowedSort, true),
                fn ($qq) => $qq->orderBy($sort, $dir),
                fn ($qq) => $qq->latest())
            ->paginate($per);

        $data = $orders->getCollection()->map(function (OnlineOrder $o) {
            return [
                'id' => $o->id,
                'code' => $o->ref,
                'customer_name' => $o->customer_name ?: optional($o->client)->name,
                'status' => $o->status,
                'has_preorder_items' => (bool) $o->has_preorder_items,
                'total' => (float) $o->total,
                'payment_method' => $o->payment_method ?? 'cod',
                'payment_status' => $o->payment_status ?? 'pending',
                'delivery_method' => $o->delivery_method ?? 'ship',
                'has_pending_proof' => (int) ($o->pending_proofs_count ?? 0) > 0,
                'is_flagged' => (bool) $o->is_flagged,
                'flag_reason' => $o->flag_reason,
                'created_at' => optional($o->created_at)->toDateTimeString() ?? (string) $o->date,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $orders->total(),
                'page' => $orders->currentPage(),
                'pages' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * GET /store/orders/{id}
     * Returns header + items for details view
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $order = OnlineOrder::with(['items.product', 'items.productVariant', 'client', 'warehouse', 'pickupBranch', 'paymentProofs'])
            ->findOrFail($id);

        $subtotal = $order->subtotal !== null && (float) $order->subtotal > 0
            ? (float) $order->subtotal
            : (float) $order->items->sum(function ($i) {
                return (float) $i->price * (float) $i->qty;
            });

        return response()->json([
            'id' => $order->id,
            'code' => $order->ref,
            'status' => $order->status,
            'has_preorder_items' => (bool) $order->has_preorder_items,
            'shipping_status' => null,
            'customer_name' => $order->customer_name ?: optional($order->client)->name,
            'customer_email' => $order->customer_email ?: optional($order->client)->email,
            'customer_phone' => $order->customer_phone ?: optional($order->client)->phone,
            'customer_address' => $order->shipping_address ?: optional($order->client)->adresse,
            'shipping_city' => $order->shipping_city,
            'shipping_state' => $order->shipping_state,
            'shipping_zip' => $order->shipping_zip,
            'shipping_country' => $order->shipping_country,

            // NEW
            'warehouse_id' => $order->warehouse_id,
            'warehouse_name' => optional($order->warehouse)->name,

            'subtotal' => $subtotal,
            'tax' => (float) $order->tax,
            'tax_rate' => (float) $order->tax_rate,
            'shipping' => (float) $order->shipping_cost,
            'shipping_method_name' => $order->shipping_method_name,
            'discount' => 0.0,
            'total' => (float) $order->total,

            'is_flagged' => (bool) $order->is_flagged,
            'flag_reason' => $order->flag_reason,

            'payment_method' => $order->payment_method ?? 'cod',
            'payment_status' => $order->payment_status ?? 'pending',

            // Pickup (collected at a branch) vs shipped
            'delivery_method' => $order->delivery_method ?? 'ship',
            'pickup_branch_id' => $order->pickup_branch_id,
            'pickup_branch_name' => optional($order->pickupBranch)->name,

            'payment_proofs' => $order->paymentProofs->sortByDesc('id')->map(fn ($p) => [
                'id' => $p->id,
                'payment_method' => $p->payment_method,
                'reference_number' => $p->reference_number,
                'amount' => (float) $p->amount,
                'paid_at' => optional($p->paid_at)->toDateString(),
                'note' => $p->note,
                'status' => $p->status,
                'reject_reason' => $p->reject_reason,
                'file_url' => $p->fileUrl(),
                'submitted_at' => optional($p->created_at)->toDateTimeString(),
                'reviewed_at' => optional($p->reviewed_at)->toDateTimeString(),
            ])->values(),

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
                    'is_preorder' => (bool) $d->is_preorder,
                ];
            })->values(),
        ]);
    }

    /**
     * GET /store/orders/{id}/confirm-options — warehouses the admin can book
     * the sale under, each with its availability for this order's items
     * (pre-order lines excluded). Store-enabled warehouses are listed first.
     * Powers the confirm dialog.
     */
    public function confirmOptions(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $order = OnlineOrder::with(['items.product', 'items.productVariant'])->findOrFail($id);

        // Base quantities needed per line (sale-unit aware).
        $lines = [];
        foreach ($order->items as $it) {
            if ($it->is_preorder) {
                continue;
            }
            $product = $it->product;
            $unit = $this->saleUnitForProduct($product);
            $name = $product?->name ?? ('#'.$it->product_id);
            if ($it->productVariant?->name) {
                $name .= ' - '.$it->productVariant->name;
            }
            $lines[] = [
                'product_id' => (int) $it->product_id,
                'product_variant_id' => $it->product_variant_id ? (int) $it->product_variant_id : null,
                'name' => $name,
                'required' => $this->requiredBaseQty($it->qty, $unit),
            ];
        }

        $storeIds = StoreSetting::storeWarehouseIds();
        $warehouses = Warehouse::whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->sortBy(fn ($w) => in_array((int) $w->id, $storeIds, true) ? 0 : 1)
            ->values();

        // qte keyed by warehouse:product:variant (summed across duplicate rows).
        $stock = [];
        if ($lines) {
            product_warehouse::where('deleted_at', '=', null)
                ->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->whereIn('product_id', array_column($lines, 'product_id'))
                ->get(['warehouse_id', 'product_id', 'product_variant_id', 'qte'])
                ->each(function ($r) use (&$stock) {
                    $key = $r->warehouse_id.':'.$r->product_id.':'.($r->product_variant_id ?: 'p');
                    $stock[$key] = ($stock[$key] ?? 0.0) + (float) $r->qte;
                });
        }

        $options = $warehouses->map(function ($w) use ($lines, $stock, $storeIds) {
            $shortages = [];
            foreach ($lines as $l) {
                $key = $w->id.':'.$l['product_id'].':'.($l['product_variant_id'] ?: 'p');
                $available = $stock[$key] ?? 0.0;
                if ($available < $l['required']) {
                    $shortages[] = [
                        'name' => $l['name'],
                        'required' => $l['required'],
                        'available' => $available,
                    ];
                }
            }

            return [
                'id' => (int) $w->id,
                'name' => $w->name,
                'store_enabled' => in_array((int) $w->id, $storeIds, true),
                'can_fulfil' => empty($shortages),
                'shortages' => $shortages,
            ];
        })->values();

        return response()->json([
            'order_warehouse_id' => $order->warehouse_id ? (int) $order->warehouse_id : null,
            'warehouses' => $options,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $data = $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::in(OnlineOrder::STATUSES)],
            // Confirm only: the warehouse the admin books the sale under.
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $order = OnlineOrder::with(['items'])->findOrFail($id);
        $newStatus = $data['status'];

        // Fast path: no-op
        if ($order->status === $newStatus) {
            return response()->json(['ok' => true, 'status' => $order->status]);
        }

        // Reject any transition that is not part of the defined lifecycle.
        if (! OnlineOrder::canTransition($order->status, $newStatus)) {
            return response()->json([
                'error' => __('messages.InvalidStatusTransition', [
                    'from' => $order->status,
                    'to' => $newStatus,
                ]),
            ], 422);
        }

        // ---- SHIP / DELIVER (fulfilment; stock already committed at confirm) ----
        if (in_array($newStatus, ['shipped', 'delivered'], true)) {
            $order->status = $newStatus;
            if ($newStatus === 'delivered') {
                $order->delivered_at = now(); // starts the return window
            }
            $order->save();

            return response()->json(['ok' => true, 'status' => $newStatus]);
        }

        // ---- CANCEL (only from pending) ----
        if ($newStatus === 'cancelled') {
            $order->status = 'cancelled';
            $order->save();

            return response()->json(['ok' => true, 'status' => 'cancelled']);
        }

        // ---- CONFIRM (only from pending) → create Sale, decrement stock ----
        if ($newStatus === 'confirmed') {
            if ($order->status !== 'pending') {
                return response()->json(['error' => 'Only pending orders can be confirmed.'], 422);
            }

            // Warehouse the sale is booked under and stock is drawn from:
            // the admin's explicit choice wins, then the order's booked
            // warehouse, then settings.default_warehouse_id → first warehouse.
            $requestedWh = (int) ($data['warehouse_id'] ?? 0);
            if ($requestedWh && ! Warehouse::whereKey($requestedWh)->whereNull('deleted_at')->exists()) {
                return response()->json(['error' => 'Selected warehouse not found.'], 422);
            }

            $warehouseId = $requestedWh ?: (int) ($order->warehouse_id ?: (DB::table('store_settings')->value('default_warehouse_id') ?: 0));
            if (! $warehouseId) {
                $warehouseId = (int) Warehouse::value('id');
            }
            if (! $warehouseId) {
                return response()->json(['error' => 'No warehouse configured.'], 422);
            }

            // Pre-check stock in the selected warehouse (outside tx just to
            // compile the list; final check in tx w/ locks).
            // Pre-order items are exempt from stock validation
            $insufficient = [];
            foreach ($order->items as $it) {
                if ($it->is_preorder) {
                    continue;
                }

                $product = Product::find($it->product_id);
                $unit = $this->saleUnitForProduct($product); // may be null
                $need = $this->requiredBaseQty($it->qty, $unit);

                $pw = product_warehouse::where('deleted_at', '=', null)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $it->product_id)
                    ->when($it->product_variant_id, function ($q) use ($it) {
                        $q->where('product_variant_id', $it->product_variant_id);
                    })
                    ->first();

                $have = $pw ? (float) $pw->qte : 0.0;
                if ($have < $need) {
                    $name = $product ? $product->name : ('#'.$it->product_id);
                    $insufficient[] = [
                        'product_id' => $it->product_id,
                        'product_variant_id' => $it->product_variant_id,
                        'name' => $name,
                        'required' => $need,
                        'available' => $have,
                    ];
                }
            }

            if (! empty($insufficient)) {
                return response()->json([
                    'error' => 'Insufficient stock for one or more items.',
                    'items' => $insufficient,
                ], 422);
            }

            // Transaction: final check WITH LOCKS, create Sale, details, decrement stock
            $sale = DB::transaction(function () use ($order, $warehouseId) {

                // Final stock check with row locks in the selected warehouse
                // (skip pre-order items)
                foreach ($order->items as $it) {
                    if ($it->is_preorder) {
                        continue;
                    }

                    $product = Product::find($it->product_id);
                    $unit = $this->saleUnitForProduct($product);
                    $need = $this->requiredBaseQty($it->qty, $unit);

                    $pw = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $it->product_id)
                        ->when($it->product_variant_id, function ($q) use ($it) {
                            $q->where('product_variant_id', $it->product_variant_id);
                        })
                        ->lockForUpdate()
                        ->first();

                    $have = $pw ? (float) $pw->qte : 0.0;
                    if ($have < $need) {
                        $name = $product ? $product->name : ('#'.$it->product_id);
                        throw new \RuntimeException("Insufficient stock for {$name} (need {$need}, have {$have}).");
                    }
                }

                $alreadyPaid = ($order->payment_status ?? '') === 'paid';
                $grandTotal  = (float) $order->total;

                // Create Sale header
                $sale = Sale::create([
                    'date' => $order->date ?: now()->toDateString(),
                    'time' => $order->time ?: now()->format('H:i:s'),
                    'Ref' => $this->getNumberOrder(),
                    'is_pos' => 0,
                    'client_id' => $order->client_id,
                    'warehouse_id' => $warehouseId,
                    'statut' => 'completed',
                    'shipping_status' => null,

                    'discount' => (float) ($order->discount ?? 0),
                    'shipping' => (float) ($order->shipping_cost ?? 0),
                    'TaxNet' => (float) ($order->tax ?? 0),
                    'tax_rate' => (float) ($order->tax_rate ?? 0),
                    'GrandTotal' => $grandTotal,

                    'paid_amount' => $alreadyPaid ? $grandTotal : 0,
                    'payment_statut' => $alreadyPaid ? 'paid' : 'unpaid',
                    'notes' => null,
                    'user_id' => optional(Auth::user())->id,
                    // Multi-Currency: inherit the order's snapshot so the
                    // admin invoice prints in the customer's checkout currency
                    'currency_id' => $order->currency_id,
                    'exchange_rate' => $order->exchange_rate,
                ]);

                // If the online order was already paid, create a payment_sales record
                if ($alreadyPaid) {
                    $paymentMethodId = $this->resolvePaymentMethodId($order->payment_method ?? 'cod');

                    $paymentRef = $this->generatePaymentRef();

                    DB::table('payment_sales')->insert([
                        'user_id'           => optional(Auth::user())->id ?? 0,
                        'date'              => $sale->date,
                        'Ref'               => $paymentRef,
                        'sale_id'           => $sale->id,
                        'account_id'        => null,
                        'montant'           => $grandTotal,
                        'change'            => 0,
                        'payment_method_id' => $paymentMethodId,
                        'notes'             => 'Auto-created from online order ' . ($order->ref ?? '#' . $order->id),
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }

                // Create details + decrement stock (skip decrement for pre-order items)
                foreach ($order->items as $it) {
                    $product = Product::find($it->product_id);
                    $unit = $this->saleUnitForProduct($product);
                    $need = $this->requiredBaseQty($it->qty, $unit);

                    $taxNet = isset($it->TaxNet) ? (float) $it->TaxNet : (float) ($product->TaxNet ?? 0);
                    $taxMethod = $it->tax_method ?? ($product->tax_method ?? null);
                    $discount = isset($it->discount) ? (float) $it->discount : (float) ($product->discount ?? 0);
                    $discountMethod = $it->discount_method ?? ($product->discount_method ?? null);

                    $unitPrice = (float) $it->price;
                    $qty = (float) $it->qty;

                    SaleDetail::create([
                        'date' => $sale->date,
                        'sale_id' => $sale->id,
                        'sale_unit_id' => $unit ? $unit->id : null,
                        'quantity' => $qty,
                        'price' => $unitPrice,
                        'TaxNet' => $taxNet,
                        'tax_method' => $taxMethod,
                        'discount' => $discount,
                        'discount_method' => $discountMethod,
                        'product_id' => (int) $it->product_id,
                        'product_variant_id' => $it->product_variant_id ?: null,
                        'total' => round($unitPrice * $qty, 2),
                    ]);

                    // Pre-order items: no stock to decrement
                    if ($it->is_preorder) {
                        continue;
                    }

                    $pw = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $it->product_id)
                        ->when($it->product_variant_id, function ($q) use ($it) {
                            $q->where('product_variant_id', $it->product_variant_id);
                        })
                        ->lockForUpdate()
                        ->first();

                    if ($pw) {
                        $pw->qte = max(0, (float) $pw->qte - $need);
                        $pw->save();
                    }
                }

                // Flip online order status; keep the warehouse the admin
                // actually fulfilled from on the order record.
                $order->status = 'confirmed';
                $order->warehouse_id = $warehouseId;
                $order->save();

                return $sale;
            });

            // Auto-route confirmed online orders to the Kitchen Display when both the
            // module and the auto-send option are on. Best-effort: a kitchen failure
            // must never roll back an already-confirmed order.
            try {
                $settings = \App\Models\Setting::whereNull('deleted_at')->first();
                if ($settings && $settings->enable_kitchen_display && $settings->kitchen_auto_online_orders) {
                    \App\Http\Controllers\KitchenOrderController::createForSale(
                        $sale->id,
                        trim(($order->ref ? $order->ref.' — ' : '').($order->isPickup() ? 'Pickup' : 'Delivery')),
                        'online'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Kitchen auto-routing failed for online order '.$order->id.': '.$e->getMessage());
            }

            return response()->json([
                'ok' => true,
                'status' => 'confirmed',
                'sale_id' => $sale->id,
                'sale_ref' => $sale->Ref,
            ]);
        }

        // Fallback (shouldn’t hit)
        return response()->json(['error' => 'Unsupported transition.'], 422);
    }

    /**
     * Convert a sold quantity into "base" stock quantity by applying the sale unit operator.
     * Mirrors your normal sale logic:
     * - if unit.operator == '/' → qty / unit.operator_value
     * - else                   → qty * unit.operator_value
     */
    protected function requiredBaseQty($qty, ?Unit $unit): float
    {
        $q = (float) $qty;
        if (! $unit) {
            return $q;
        }
        $op = $unit->operator ?? '*';
        $v = (float) ($unit->operator_value ?: 1);
        if ($v <= 0) {
            $v = 1;
        }

        return $op === '/' ? ($q / $v) : ($q * $v);
    }

    /**
     * Best-effort: pick a sale unit from the product if present.
     * Tries common attribute names safely; returns null if none.
     */
    protected function saleUnitForProduct(?Product $p): ?Unit
    {
        if (! $p) {
            return null;
        }
        $unitId = $p->sale_unit_id ?? $p->unit_sale_id ?? $p->unit_id ?? null;

        return $unitId ? Unit::find($unitId) : null;
    }

    /**
     * Map the online order payment_method string to a payment_methods.id.
     * Credit Card is always id=1 (seeded). For others, look up by name or create on the fly.
     */
    /**
     * POST /store/orders/{id}/payment-proofs/{proofId}/review
     * Verify a shopper-submitted GCash / bank-transfer payment. Approving is
     * what marks the order paid, so a later confirm records the payment
     * against the generated Sale.
     */
    public function reviewProof(Request $request, $id, $proofId)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reject_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = OnlineOrder::findOrFail($id);
        $proof = $order->paymentProofs()->findOrFail($proofId);

        if ($proof->status !== 'pending') {
            return response()->json(['error' => __('messages.PaymentProofAlreadyReviewed')], 422);
        }

        DB::transaction(function () use ($proof, $order, $data, $request) {
            $proof->update([
                'status' => $data['status'],
                'reject_reason' => $data['status'] === 'rejected' ? ($data['reject_reason'] ?? null) : null,
                'reviewed_by' => optional($request->user('api'))->id,
                'reviewed_at' => now(),
            ]);

            if ($data['status'] === 'approved' && ($order->payment_status ?? 'pending') !== 'paid') {
                $order->update(['payment_status' => 'paid']);
            }
        });

        // Let the customer know either way.
        try {
            $to = $order->customer_email ?: optional($order->client)->email;
            if ($to) {
                Mail::to($to)->send(new \App\Mail\PaymentProofReviewed($order->fresh(), $proof->fresh()));
            }
        } catch (\Throwable $e) {
            Log::warning('Payment proof review email failed: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'status' => $proof->status,
            'payment_status' => $order->fresh()->payment_status,
        ]);
    }

    protected function resolvePaymentMethodId(string $method): int
    {
        $nameMap = [
            'credit_card'    => 'Credit Card',
            'mobile_money'   => 'Mobile Money',
            'cod'            => 'Cash on Delivery',
            'gcash'          => 'GCash',
            'bank_transfer'  => 'Bank Transfer',
            'cash_on_pickup' => 'Cash on Pickup',
            'bkash'          => 'bKash',
            'sslcommerz'     => 'SSLCommerz',
        ];

        $name = $nameMap[$method] ?? $nameMap['cod'];

        // Credit Card is guaranteed id=1 from the original seed
        if ($method === 'credit_card') {
            return 1;
        }

        // Look up existing row (case-insensitive) or create it
        $row = DB::table('payment_methods')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($row) {
            return (int) $row->id;
        }

        return (int) DB::table('payment_methods')->insertGetId([
            'name'       => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function generatePaymentRef(): string
    {
        $prefix = 'INV/SL';

        $last = DB::table('payment_sales')
            ->where('Ref', 'like', $prefix . '_%')
            ->where('deleted_at', null)
            ->orderByDesc('id')
            ->first();

        if ($last) {
            $parts = explode('_', $last->Ref);
            $seq = isset($parts[1]) && is_numeric($parts[1]) ? ((int) $parts[1] + 1) : 1;
        } else {
            $seq = 1;
        }

        return $prefix . '_' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'SL' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->sale_prefix) ? $setting->sale_prefix : 'SL';
        
        // Get the last sale with a reference that starts with the prefix
        $last = DB::table('sales')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);

            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].'_'.str_pad($inMsg, 4, '0', STR_PAD_LEFT);
            } else {
                $code = $prefix.'_0001'; // Fallback if reference is corrupted
            }
        } else {
            $code = $prefix.'_0001';
        }

        return $code;
    }
}
