<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A warehouse the storefront offers for order collection. Stock and
 * fulfilment stay on the warehouse; this row only adds the shopper-facing
 * address / hours / contact and the on-off switch.
 */
class StorePickupBranch extends Model
{
    use SoftDeletes;

    protected $table = 'store_pickup_branches';

    protected $fillable = [
        'warehouse_id', 'address', 'hours', 'contact', 'notes', 'active', 'sort_order',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Branches a shopper may pick: active, not deleted, and still part of the
     * warehouse set the online store sells from.
     */
    public static function selectable()
    {
        $storeIds = StoreSetting::storeWarehouseIds();

        return static::with('warehouse')
            ->where('active', true)
            ->whereIn('warehouse_id', $storeIds)
            ->whereHas('warehouse', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
