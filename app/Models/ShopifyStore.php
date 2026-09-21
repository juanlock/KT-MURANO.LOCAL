<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected Shopify shop. Several may exist at once; every sync, link and log
 * row is scoped to one of these.
 */
class ShopifyStore extends Model
{
    protected $table = 'shopify_stores';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name',
        'shop_domain',
        'access_token',
        'api_version',
        'webhook_secret',
        'warehouse_id',
        'location_id',
        'currency',
        'shop_name',
        'shop_email',
        'status',
        'last_error',
        'last_connected_at',
        'price_field',
        'create_missing_products',
        'create_missing_customers',
        'auto_sync',
        'sync_interval_minutes',
        'sync_products',
        'sync_inventory',
        'sync_customers',
        'sync_orders',
        'sync_collections',
        'sync_fulfillments',
        'last_sync_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The token never leaves the server. Anything that serialises a store for
     * the UI goes through toPublicArray(), which omits it entirely.
     */
    protected $hidden = ['access_token', 'webhook_secret'];

    protected $casts = [
        'create_missing_products' => 'boolean',
        'create_missing_customers' => 'boolean',
        'auto_sync' => 'boolean',
        'sync_products' => 'boolean',
        'sync_inventory' => 'boolean',
        'sync_customers' => 'boolean',
        'sync_orders' => 'boolean',
        'sync_collections' => 'boolean',
        'sync_fulfillments' => 'boolean',
        'sync_interval_minutes' => 'integer',
        'last_sync_at' => 'datetime',
        'last_connected_at' => 'datetime',
    ];

    /** The entities this module knows how to move, in dependency order. */
    public const ENTITIES = ['products', 'inventory', 'customers', 'orders', 'collections', 'fulfillments'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function links()
    {
        return $this->hasMany(ShopifyLink::class, 'store_id');
    }

    public function runs()
    {
        return $this->hasMany(ShopifySyncRun::class, 'store_id');
    }

    /** Whether this store is allowed to sync the given entity at all. */
    public function syncs(string $entity): bool
    {
        $flag = 'sync_'.$entity;

        return (bool) ($this->$flag ?? false);
    }

    /**
     * Normalise whatever the user typed into a bare myshopify domain:
     * "https://acme.myshopify.com/admin" and "acme" both become
     * "acme.myshopify.com".
     */
    public static function normaliseDomain(?string $input): string
    {
        $value = trim((string) $input);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value);
        $value = explode('/', $value)[0];
        $value = strtolower(trim($value));

        if ($value !== '' && ! str_contains($value, '.')) {
            $value .= '.myshopify.com';
        }

        return $value;
    }

    /** Safe representation for the API: no token, no secret. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shop_domain' => $this->shop_domain,
            'api_version' => $this->api_version,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse ? $this->warehouse->name : null,
            'location_id' => $this->location_id,
            'currency' => $this->currency,
            'shop_name' => $this->shop_name,
            'shop_email' => $this->shop_email,
            'status' => $this->status,
            'last_error' => $this->last_error,
            'last_connected_at' => $this->last_connected_at ? $this->last_connected_at->toDateTimeString() : null,
            'last_sync_at' => $this->last_sync_at ? $this->last_sync_at->toDateTimeString() : null,
            'price_field' => $this->price_field,
            'create_missing_products' => (bool) $this->create_missing_products,
            'create_missing_customers' => (bool) $this->create_missing_customers,
            'auto_sync' => (bool) $this->auto_sync,
            'sync_interval_minutes' => (int) $this->sync_interval_minutes,
            'sync_products' => (bool) $this->sync_products,
            'sync_inventory' => (bool) $this->sync_inventory,
            'sync_customers' => (bool) $this->sync_customers,
            'sync_orders' => (bool) $this->sync_orders,
            'sync_collections' => (bool) $this->sync_collections,
            'sync_fulfillments' => (bool) $this->sync_fulfillments,
            // So the UI can tell "no token yet" from "token saved" without ever
            // receiving the token.
            'has_token' => ! empty($this->access_token),
            'has_webhook_secret' => ! empty($this->webhook_secret),
        ];
    }
}
