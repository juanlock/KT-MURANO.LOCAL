<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineOrder extends Model
{
    protected $table = 'online_orders';

    /** The full lifecycle of fulfilment statuses an order may hold. */
    public const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

    /**
     * Allowed forward transitions. (Payment "paid" is tracked in payment_status.)
     * Cancel is only from pending: a confirmed order has already created a Sale
     * and decremented stock, so cancelling it would need stock/Sale reversal.
     */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['shipped'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public static function canTransition(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    protected $fillable = [
        'date', 'time', 'ref',
        'client_id', 'warehouse_id',
        'total', 'subtotal', 'discount', 'coupon_code', 'tax', 'tax_rate', 'shipping_cost',
        'shipping_method_id', 'shipping_method_name',
        'delivery_method', 'pickup_branch_id',
        'customer_name', 'customer_email', 'customer_phone',
        'shipping_address', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
        'payment_method', 'payment_status', 'stripe_payment_intent_id',
        'paypal_order_id', 'paypal_capture_id',
        'paystack_reference', 'paystack_transaction_id',
        'flutterwave_tx_ref', 'flutterwave_transaction_id',
        'razorpay_payment_link_id', 'razorpay_payment_id',
        'bkash_payment_id', 'bkash_trx_id',
        'sslcommerz_tran_id', 'sslcommerz_val_id',
        'status', 'delivered_at',
        'has_preorder_items',
        'is_flagged', 'flag_reason',
        // Multi-Currency snapshot chosen by the shopper; NULL = base currency
        'currency_id', 'exchange_rate',
    ];

    protected $casts = [
        'date' => 'date',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'tax_rate' => 'decimal:3',
        'shipping_cost' => 'decimal:2',
        'shipping_method_id' => 'integer',
        'pickup_branch_id' => 'integer',
        'has_preorder_items' => 'boolean',
        'is_flagged' => 'boolean',
        'delivered_at' => 'datetime',
        'currency_id' => 'integer',
        'exchange_rate' => 'float',
    ];

    // Relationships
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderItem::class, 'order_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'pickup_branch_id');
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(OnlineOrderPaymentProof::class, 'order_id');
    }

    /** True when the shopper collects the order at a branch. */
    public function isPickup(): bool
    {
        return ($this->delivery_method ?? 'ship') === 'pickup';
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OnlineOrderReturn::class, 'order_id');
    }

    // Auto-fill date, time, ref
    protected static function boot()
    {
        parent::boot();

        static::creating(function (OnlineOrder $order) {
            if (empty($order->date)) {
                $order->date = now()->toDateString();
            }
            if (empty($order->time)) {
                $order->time = now()->format('H:i:s');
            }
            if (empty($order->ref)) {
                $order->ref = static::generateRef();
            }
        });
    }

    public static function generateRef(): string
    {
        $prefix = 'SO-'.now()->format('Ymd').'-';
        // Simple daily sequence based on max id seen today
        $maxIdToday = static::whereDate('created_at', now()->toDateString())->max('id');
        $seq = ($maxIdToday ? $maxIdToday + 1 : 1);

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
