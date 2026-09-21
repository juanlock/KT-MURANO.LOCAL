<?php

namespace App\Services;

use App\Models\ShippingZone;
use Illuminate\Support\Collection;

/**
 * Resolves which shipping zone covers a destination.
 *
 * Specificity wins: a zone naming the exact country + state beats one naming
 * the country, which beats the catch-all ("Rest of the world", stored as a
 * location row with a NULL country). Countries are compared through
 * CountryService, so "mexico", "México" and "MX" all resolve to the same zone.
 */
class ShippingZoneService
{
    /** Any zones configured at all? Until there are, shipping stays legacy. */
    public static function inUse(): bool
    {
        return ShippingZone::query()->exists();
    }

    /**
     * The zone covering a destination, or null when nothing matches (not even
     * a catch-all, which means the store does not ship there).
     */
    public static function forDestination(?string $country, ?string $state = null): ?ShippingZone
    {
        $zones = ShippingZone::with(['locations'])->orderBy('sort_order')->orderBy('id')->get();
        if ($zones->isEmpty()) {
            return null;
        }

        $stateNeedle = self::normalize($state);

        $countryMatch = null;
        $catchAll = null;

        foreach ($zones as $zone) {
            foreach ($zone->locations as $location) {
                $locCountry = trim((string) $location->country);

                if ($locCountry === '') {
                    $catchAll = $catchAll ?: $zone;

                    continue;
                }

                if (! CountryService::sameCountry($locCountry, $country)) {
                    continue;
                }

                $locState = self::normalize($location->state);

                // Exact country + state — the most specific answer there is.
                if ($locState !== '' && $locState === $stateNeedle) {
                    return $zone;
                }

                // Country-wide row: remember it, but keep looking for a
                // state-specific zone that might be a better fit.
                if ($locState === '') {
                    $countryMatch = $countryMatch ?: $zone;
                }
            }
        }

        return $countryMatch ?: $catchAll;
    }

    /**
     * Rates offered for a destination and cart, cheapest first.
     *
     * @return Collection<int, \App\Models\ShippingMethod>
     */
    public static function ratesFor(?string $country, ?string $state, float $subtotal, float $weight): Collection
    {
        $zone = self::forDestination($country, $state);
        if (! $zone) {
            return collect();
        }

        return $zone->rates()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get()
            ->filter(fn ($rate) => $rate->appliesToCart($subtotal, $weight))
            ->values();
    }

    private static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
