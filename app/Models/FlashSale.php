<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FlashSale extends Model
{
    use HasTranslations;

    /** Customer-facing copy that can be translated per locale. */
    public const TRANSLATABLE = ['name'];

    protected $table = 'flash_sales';

    protected $fillable = [
        'name', 'name_translations', 'is_active', 'starts_at', 'ends_at', 'sort_order',
    ];

    protected $casts = [
        'name_translations' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'flash_sale_product')
            ->withPivot(['discount_type', 'discount_value', 'sort_order'])
            ->withTimestamps()
            ->orderBy('flash_sale_product.sort_order');
    }

    /**
     * The campaign title in the visitor's language, falling back to `name`
     * when that locale was left blank in the admin.
     */
    public function localizedName(?string $locale = null): string
    {
        return (string) $this->localized('name', $locale);
    }

    /** Active and within its (optional) start/end window. */
    public function scopeRunning(Builder $q): Builder
    {
        $now = now();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function isRunning(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $now = now();
        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    /** Apply a flash discount rule to a base price, never below zero. */
    public static function applyDiscount(float $price, string $type, float $value): float
    {
        if ($type === 'fixed') {
            return round(max(0, $price - $value), 2);
        }

        // percent
        $value = max(0, min(100, $value));

        return round($price * (1 - $value / 100), 2);
    }
}
