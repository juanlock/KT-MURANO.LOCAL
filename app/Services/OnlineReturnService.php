<?php

namespace App\Services;

use App\Models\OnlineOrder;
use App\Models\OnlineOrderReturn;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancellations, returns and refunds for online orders.
 *
 * Eligibility is enforced here (order state + return window + returnable items).
 * Customers may only *request*; a refund is only ever performed by an admin
 * through approve(), never by the customer.
 */
class OnlineReturnService
{
    public function __construct(private WalletService $wallets)
    {
    }

    /** A product is returnable unless flagged non-returnable or it is a service. */
    public function isProductReturnable(?Product $product): bool
    {
        if (! $product) {
            return false;
        }
        if ((string) $product->type === 'is_service') {
            return false;
        }

        return (bool) ($product->is_returnable ?? true);
    }

    /** Can this order be cancelled by the customer right now? */
    public function cancelEligibility(OnlineOrder $order): array
    {
        $s = StoreSetting::first();

        if (! ($s->allow_cancellations ?? true)) {
            return ['ok' => false, 'reason' => 'cancellations_disabled'];
        }
        if ($order->status !== 'pending') {
            return ['ok' => false, 'reason' => 'not_cancellable_status'];
        }
        if ($order->returns()->whereIn('status', ['requested', 'approved'])->exists()) {
            return ['ok' => false, 'reason' => 'request_already_open'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** Can this order be returned by the customer right now? */
    public function returnEligibility(OnlineOrder $order): array
    {
        $s = StoreSetting::first();
        $window = (int) ($s->return_window_days ?? 0);

        if ($window <= 0) {
            return ['ok' => false, 'reason' => 'returns_disabled'];
        }
        if ($order->status !== 'delivered') {
            return ['ok' => false, 'reason' => 'not_delivered'];
        }

        $deliveredAt = $order->delivered_at ?: $order->updated_at;
        if ($deliveredAt && $deliveredAt->copy()->addDays($window)->isPast()) {
            return ['ok' => false, 'reason' => 'window_expired'];
        }
        if ($order->returns()->whereIn('status', ['requested', 'approved'])->exists()) {
            return ['ok' => false, 'reason' => 'request_already_open'];
        }
        if (empty($this->returnableItems($order))) {
            return ['ok' => false, 'reason' => 'no_returnable_items'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Items on the order that may still be returned (returnable product, not a
     * service, and not already covered by a prior approved/refunded return).
     */
    public function returnableItems(OnlineOrder $order): array
    {
        $order->loadMissing('items.product');

        // qty already returned per order-item across settled returns
        $already = DB::table('online_order_return_items as ri')
            ->join('online_order_returns as r', 'r.id', '=', 'ri.return_id')
            ->where('r.order_id', $order->id)
            ->whereIn('r.status', ['requested', 'approved', 'refunded'])
            ->groupBy('ri.online_order_item_id')
            ->select('ri.online_order_item_id', DB::raw('SUM(ri.qty) as qty'))
            ->pluck('qty', 'online_order_item_id');

        $out = [];
        foreach ($order->items as $it) {
            if (! $this->isProductReturnable($it->product)) {
                continue;
            }
            $returned = (float) ($already[$it->id] ?? 0);
            $remaining = (float) $it->qty - $returned;
            if ($remaining <= 0) {
                continue;
            }
            $out[] = [
                'online_order_item_id' => $it->id,
                'product_id' => $it->product_id,
                'product_variant_id' => $it->product_variant_id,
                'name' => optional($it->product)->name ?? ('#'.$it->product_id),
                'price' => (float) $it->price,
                'qty' => $remaining,
            ];
        }

        return $out;
    }

    /**
     * Create a cancellation request. If the order is unpaid it is cancelled
     * immediately; if it was paid, a refund request is opened for admin approval.
     */
    public function requestCancellation(OnlineOrder $order, ?string $reason): OnlineOrderReturn
    {
        $elig = $this->cancelEligibility($order);
        if (! $elig['ok']) {
            throw new CheckoutException(__('messages.CancelNotAllowed'), 422, ['reason' => $elig['reason']]);
        }

        $isPaid = ($order->payment_status ?? '') === 'paid';

        return DB::transaction(function () use ($order, $reason, $isPaid) {
            $return = OnlineOrderReturn::create([
                'order_id' => $order->id,
                'client_id' => $order->client_id,
                'type' => 'cancellation',
                'status' => $isPaid ? 'requested' : 'approved',
                'reason' => $reason,
                'refund_amount' => $isPaid ? (float) $order->total : 0,
            ]);

            // Unpaid → cancel now, nothing to refund.
            if (! $isPaid) {
                $order->status = 'cancelled';
                $order->save();
                $return->status = 'refunded'; // nothing to refund; closed
                $return->refunded_at = now();
                $return->save();
            }

            return $return;
        });
    }

    /**
     * Create a return request for the given items (validated as returnable).
     *
     * @param  array  $items  list of ['online_order_item_id'=>int,'qty'=>float]
     */
    public function requestReturn(OnlineOrder $order, array $items, ?string $reason): OnlineOrderReturn
    {
        $elig = $this->returnEligibility($order);
        if (! $elig['ok']) {
            throw new CheckoutException(__('messages.ReturnNotAllowed'), 422, ['reason' => $elig['reason']]);
        }

        $allowed = collect($this->returnableItems($order))->keyBy('online_order_item_id');

        $rows = [];
        $refund = 0.0;
        foreach ($items as $req) {
            $itemId = (int) ($req['online_order_item_id'] ?? 0);
            $qty = (float) ($req['qty'] ?? 0);
            $ref = $allowed->get($itemId);

            if (! $ref || $qty <= 0) {
                continue;
            }
            if ($qty > $ref['qty']) {
                throw new CheckoutException(__('messages.ReturnQtyExceedsPurchased'), 422, [
                    'online_order_item_id' => $itemId,
                    'max' => $ref['qty'],
                ]);
            }

            $amount = round($qty * $ref['price'], 2);
            $refund += $amount;
            $rows[] = [
                'online_order_item_id' => $itemId,
                'product_id' => $ref['product_id'],
                'product_variant_id' => $ref['product_variant_id'],
                'qty' => $qty,
                'amount' => $amount,
            ];
        }

        if (empty($rows)) {
            throw new CheckoutException(__('messages.NoReturnableItemsSelected'), 422);
        }

        return DB::transaction(function () use ($order, $reason, $rows, $refund) {
            $return = OnlineOrderReturn::create([
                'order_id' => $order->id,
                'client_id' => $order->client_id,
                'type' => 'return',
                'status' => 'requested',
                'reason' => $reason,
                'refund_amount' => round($refund, 2),
            ]);
            foreach ($rows as $r) {
                $return->items()->create($r);
            }

            return $return;
        });
    }

    /**
     * Admin approval → performs the refund (money), restocks returned items,
     * and closes the request. THIS is the only place a refund happens.
     */
    public function approve(OnlineOrderReturn $return, $adminUserId, ?string $adminNote, ?float $refundOverride = null): OnlineOrderReturn
    {
        if (! $return->isOpen()) {
            throw new CheckoutException(__('messages.ReturnNotOpen'), 422);
        }

        $order = $return->order;
        $amount = $refundOverride !== null ? round(max(0, $refundOverride), 2) : (float) $return->refund_amount;

        return DB::transaction(function () use ($return, $order, $adminUserId, $adminNote, $amount) {
            $reference = $this->processRefund($order, $amount);

            // Restock physically-returned items (returns only; cancellations
            // never decremented stock because they are pending-only).
            if ($return->type === 'return') {
                $this->restockReturnItems($return, $order);
            }

            // Cancellation of a paid order → mark the order cancelled.
            if ($return->type === 'cancellation') {
                $order->status = 'cancelled';
                $order->save();
            }

            if ($amount > 0) {
                $order->payment_status = 'refunded';
                $order->save();
            }

            $return->status = 'refunded';
            $return->admin_note = $adminNote;
            $return->processed_by = $adminUserId;
            $return->refund_amount = $amount;
            $return->refunded_at = now();
            $return->refund_reference = $reference;
            $return->save();

            return $return;
        });
    }

    public function reject(OnlineOrderReturn $return, $adminUserId, ?string $adminNote): OnlineOrderReturn
    {
        if (! $return->isOpen()) {
            throw new CheckoutException(__('messages.ReturnNotOpen'), 422);
        }

        $return->status = 'rejected';
        $return->admin_note = $adminNote;
        $return->processed_by = $adminUserId;
        $return->save();

        return $return;
    }

    /**
     * Perform the actual refund. For card orders a Stripe refund is attempted;
     * for COD/mobile it is a manual/recorded refund. Returns a reference string.
     */
    private function processRefund(OnlineOrder $order, float $amount): ?string
    {
        if ($amount <= 0) {
            return null;
        }

        // Refund to the customer's wallet when configured, or always for orders
        // that were themselves paid from the wallet (a wallet debit can't be
        // reversed to a card).
        $walletEnabled = (bool) (StoreSetting::query()->value('wallet_enabled') ?? false);
        $refundDestination = (string) (StoreSetting::query()->value('wallet_refund_destination') ?: 'wallet');
        if ($order->client_id
            && ($order->payment_method === 'wallet' || ($walletEnabled && $refundDestination === 'wallet'))) {
            $wallet = $this->wallets->walletFor((int) $order->client_id);
            $this->wallets->credit($wallet, $amount, 'refund', [
                'reference_type' => OnlineOrder::class,
                'reference_id' => $order->id,
                'note' => __('messages.WalletRefundNote', ['ref' => $order->ref]),
            ]);

            return 'wallet';
        }

        if ($order->payment_method === 'credit_card' && $order->stripe_payment_intent_id && config('services.stripe.secret')) {
            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $refund = \Stripe\Refund::create([
                    'payment_intent' => $order->stripe_payment_intent_id,
                    'amount' => (int) round($amount * 100),
                ]);

                return 'stripe:'.$refund->id;
            } catch (\Throwable $e) {
                Log::warning('Stripe refund failed for order '.$order->ref.': '.$e->getMessage());
                throw new CheckoutException(__('messages.RefundGatewayFailed'), 502);
            }
        }

        return 'manual';
    }

    /** Return the returned quantities back into the order's warehouse. */
    private function restockReturnItems(OnlineOrderReturn $return, OnlineOrder $order): void
    {
        foreach ($return->items as $ri) {
            $query = product_warehouse::where('warehouse_id', $order->warehouse_id)
                ->where('product_id', $ri->product_id)
                ->whereNull('deleted_at');

            if ($ri->product_variant_id) {
                $query->where('product_variant_id', $ri->product_variant_id);
            } else {
                $query->whereNull('product_variant_id');
            }

            $row = $query->lockForUpdate()->first();
            if ($row) {
                $row->qte = (float) $row->qte + (float) $ri->qty;
                $row->save();
            }
        }
    }
}
