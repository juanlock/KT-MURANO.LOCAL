<?php

namespace App\Support;

use App\Models\StoreSetting;

/**
 * Registry of "skin" storefront themes: those that reuse layouts/store.blade.php
 * and only swap the head / header / footer partials plus their own homepage.
 * (Real Estate is a separate layout and is not listed here.)
 */
class StoreThemes
{
    /** Theme keys that have resources/views/store/<key>/partials/{head,header,footer}. */
    public const SKINS = ['electronics', 'toys', 'grocery'];

    /** @return array<string, class-string> */
    public static function classes(): array
    {
        return [
            ElectronicsTheme::KEY => ElectronicsTheme::class,
            ToysTheme::KEY => ToysTheme::class,
            GroceryTheme::KEY => GroceryTheme::class,
        ];
    }

    public static function options(string $theme, ?StoreSetting $s): array
    {
        $class = self::classes()[$theme] ?? null;

        return $class ? $class::options($s) : [];
    }

    public static function sanitize(string $theme, $input): array
    {
        $class = self::classes()[$theme] ?? null;

        return $class ? $class::sanitize($input) : [];
    }
}
