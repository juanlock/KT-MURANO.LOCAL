<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bridge between an ERP record and its counterpart in one Shopify shop.
 *
 * Deliberately a table rather than a column on each local table: the same
 * product published to three shops has three different Shopify ids, which a
 * single column cannot hold. See the migration for the full reasoning.
 */
class ShopifyLink extends Model
{
    protected $table = 'shopify_links';

    protected $fillable = [
        'store_id',
        'entity_type',
        'local_id',
        'shopify_id',
        'shopify_handle',
        'secondary_id',
        'push_hash',
        'last_synced_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'store_id');
    }

    /** Record (or refresh) the mapping for one record. */
    public static function link(int $storeId, string $entity, int $localId, $shopifyId, array $extra = []): self
    {
        $link = static::firstOrNew([
            'store_id' => $storeId,
            'entity_type' => $entity,
            'local_id' => $localId,
        ]);

        $link->shopify_id = (string) $shopifyId;
        $link->shopify_handle = $extra['handle'] ?? $link->shopify_handle;
        $link->secondary_id = $extra['secondary_id'] ?? $link->secondary_id;
        $link->push_hash = $extra['push_hash'] ?? $link->push_hash;
        $link->last_synced_at = now();
        $link->save();

        return $link;
    }

    public static function findLocal(int $storeId, string $entity, int $localId): ?self
    {
        return static::where('store_id', $storeId)
            ->where('entity_type', $entity)
            ->where('local_id', $localId)
            ->first();
    }

    public static function findRemote(int $storeId, string $entity, $shopifyId): ?self
    {
        return static::where('store_id', $storeId)
            ->where('entity_type', $entity)
            ->where('shopify_id', (string) $shopifyId)
            ->first();
    }

    /** Local id for a remote id, or null when the record is not linked yet. */
    public static function localIdFor(int $storeId, string $entity, $shopifyId): ?int
    {
        $link = static::findRemote($storeId, $entity, $shopifyId);

        return $link ? (int) $link->local_id : null;
    }
}
