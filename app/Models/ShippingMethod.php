<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingMethod extends Model
{
    use SoftDeletes;

    /** What min_value/max_value are measured in. */
    public const RATE_TYPES = ['flat', 'price', 'weight'];

    protected $fillable = [
        'name', 'price', 'active', 'sort_order',
        'shipping_zone_id', 'rate_type', 'min_value', 'max_value',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'shipping_zone_id' => 'integer',
        'min_value' => 'float',
        'max_value' => 'float',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function zone()
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * Whether this rate applies to a cart, given its subtotal and total
     * weight. A 'flat' rate has no condition; 'price' and 'weight' rates
     * apply when the cart falls inside [min_value, max_value] (either end
     * may be open).
     */
    public function appliesToCart(float $subtotal, float $weight): bool
    {
        $type = $this->rate_type ?: 'flat';
        if ($type === 'flat') {
            return true;
        }

        $value = $type === 'weight' ? $weight : $subtotal;

        if ($this->min_value !== null && $value < (float) $this->min_value) {
            return false;
        }
        if ($this->max_value !== null && $value > (float) $this->max_value) {
            return false;
        }

        return true;
    }

    public function regions(): HasMany
    {
        return $this->hasMany(ShippingMethodRegion::class);
    }

    /**
     * A method is available for a country when it has no region rows
     * (available everywhere) or one of its region rows matches.
     */
    public function availableForCountry(?string $country): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->regions->isEmpty()) {
            return true;
        }

        if (! $country) {
            return false;
        }

        // Compare normalized ISO codes, not raw strings: the shopper's
        // "mexico" and the admin's "México" are the same region.
        return $this->regions->contains(
            fn ($r) => \App\Services\CountryService::sameCountry($r->country, $country)
        );
    }
}
