<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named set of destinations sharing a list of shipping rates.
 * A location row with a NULL country makes the zone the catch-all.
 */
class ShippingZone extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function locations(): HasMany
    {
        return $this->hasMany(ShippingZoneLocation::class);
    }

    /** The rates offered inside this zone (rows of shipping_methods). */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'shipping_zone_id');
    }

    public function isCatchAll(): bool
    {
        return $this->locations->contains(fn ($l) => trim((string) $l->country) === '');
    }
}
