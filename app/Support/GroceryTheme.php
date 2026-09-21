<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Grocery & Supermarket storefront theme — presentation options, stored under
 * store_settings.theme_options['grocery']. Same contract as the other skin
 * themes: defaults() → options() → sanitize().
 */
class GroceryTheme
{
    public const KEY = 'grocery';

    public const LISTS = ['slides', 'tiles', 'trust'];

    public const SECTIONS = [
        'categories', 'deals', 'popular', 'tiles', 'aisles', 'buy_again', 'trust', 'brands', 'newsletter',
    ];

    public const ICONS = ['truck', 'leaf', 'refresh', 'shield-check', 'clock', 'check-circle', 'gift', 'star', 'headset', 'package', 'phone', 'mail', 'apple-icon', 'basket'];

    public static function defaults(): array
    {
        return [
            'delivery_text' => 'Free delivery on orders over $49 · Same-day delivery before 2pm',
            'delivery_time' => 'Delivery in 30–60 min',
            'slides' => [
                [
                    'kicker' => 'Fresh every day',
                    'title' => 'Farm-fresh groceries delivered to your door',
                    'subtitle' => 'Fruit, vegetables, dairy and pantry staples at supermarket prices — picked fresh, delivered fast.',
                    'image' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=1400&q=80',
                    'badge' => 'Up to 30% off this week',
                    'primary_text' => 'Shop Now',
                    'primary_url' => '/shop',
                    'secondary_text' => 'Weekly Deals',
                    'secondary_url' => '/shop?deals=1',
                ],
            ],
            'tiles' => [
                ['title' => 'Fresh Fruits', 'subtitle' => 'Picked this morning', 'button_text' => 'Shop Fruits', 'url' => '/shop', 'image' => 'https://images.unsplash.com/photo-1610832958506-aa56368176cf?w=800&q=80', 'tone' => 'green'],
                ['title' => 'Bakery', 'subtitle' => 'Baked fresh daily', 'button_text' => 'Shop Bakery', 'url' => '/shop', 'image' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=800&q=80', 'tone' => 'orange'],
                ['title' => 'Dairy & Eggs', 'subtitle' => 'From local farms', 'button_text' => 'Shop Dairy', 'url' => '/shop', 'image' => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?w=800&q=80', 'tone' => 'blue'],
            ],
            'trust' => [
                ['icon' => 'truck', 'title' => 'Fast Delivery', 'subtitle' => 'Same-day slots available'],
                ['icon' => 'leaf', 'title' => 'Fresh Guarantee', 'subtitle' => 'Not fresh? Money back'],
                ['icon' => 'refresh', 'title' => 'Easy Returns', 'subtitle' => 'Hassle-free within 7 days'],
                ['icon' => 'shield-check', 'title' => 'Secure Payment', 'subtitle' => 'Cards, wallets & cash on delivery'],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true),
            'limits' => ['deals' => 6, 'popular' => 6, 'aisles' => 3, 'aisle_products' => 6, 'categories' => 10],
            'deals_title' => 'Weekly Deals',
            'popular_title' => 'Popular Right Now',
            'newsletter_title' => 'Get the weekly flyer',
            'newsletter_subtitle' => 'Deals, new arrivals and recipes straight to your inbox every week.',
            'payment_icons' => true,
            'show_theme_toggle' => false,
        ];
    }

    public static function options(?StoreSetting $s): array
    {
        $out = self::defaults();
        $raw = $s ? ($s->theme_options ?? null) : null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $saved = is_array($raw) ? ($raw[self::KEY] ?? []) : [];
        if (! is_array($saved)) {
            return $out;
        }
        foreach (self::LISTS as $list) {
            if (isset($saved[$list]) && is_array($saved[$list])) {
                $rows = array_values(array_filter($saved[$list], 'is_array'));
                if ($rows !== []) {
                    $out[$list] = $rows;
                }
            }
        }
        if (isset($saved['sections']) && is_array($saved['sections'])) {
            foreach (self::SECTIONS as $sec) {
                if (array_key_exists($sec, $saved['sections'])) {
                    $out['sections'][$sec] = filter_var($saved['sections'][$sec], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        if (isset($saved['limits']) && is_array($saved['limits'])) {
            foreach ($out['limits'] as $k => $v) {
                if (isset($saved['limits'][$k]) && is_numeric($saved['limits'][$k])) {
                    $out['limits'][$k] = max(1, min(24, (int) $saved['limits'][$k]));
                }
            }
        }
        foreach (['delivery_text', 'delivery_time', 'deals_title', 'popular_title', 'newsletter_title', 'newsletter_subtitle'] as $k) {
            if (array_key_exists($k, $saved) && is_string($saved[$k])) {
                if (in_array($k, ['delivery_text', 'delivery_time'], true) || trim($saved[$k]) !== '') {
                    $out[$k] = trim($saved[$k]);
                }
            }
        }
        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = filter_var($saved[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    public static function sanitize($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }
        $str = fn ($v, $max = 500) => Str::limit(trim((string) (is_scalar($v) ? $v : '')), $max, '');
        $clean = [];
        $fields = [
            'slides' => ['kicker', 'title', 'subtitle', 'image', 'badge', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'],
            'tiles' => ['title', 'subtitle', 'button_text', 'url', 'image', 'tone'],
            'trust' => ['icon', 'title', 'subtitle'],
        ];
        foreach ($fields as $list => $keys) {
            $rows = [];
            foreach ((array) ($input[$list] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $r = [];
                foreach ($keys as $k) {
                    $r[$k] = $str($row[$k] ?? '', $k === 'subtitle' ? 1000 : 500);
                }
                if ($list === 'tiles' && ! in_array($r['tone'], ['green', 'orange', 'blue', 'red', 'yellow'], true)) {
                    $r['tone'] = 'green';
                }
                $probe = $r;
                unset($probe['tone']);
                if (implode('', $probe) === '') {
                    continue;
                }
                $rows[] = $r;
            }
            $clean[$list] = $rows;
        }
        $clean['sections'] = [];
        foreach (self::SECTIONS as $sec) {
            if (isset($input['sections']) && is_array($input['sections']) && array_key_exists($sec, $input['sections'])) {
                $clean['sections'][$sec] = filter_var($input['sections'][$sec], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $clean['limits'] = [];
        foreach (['deals', 'popular', 'aisles', 'aisle_products', 'categories'] as $k) {
            if (isset($input['limits'][$k]) && is_numeric($input['limits'][$k])) {
                $clean['limits'][$k] = max(1, min(24, (int) $input['limits'][$k]));
            }
        }
        foreach (['delivery_text', 'delivery_time', 'deals_title', 'popular_title', 'newsletter_title', 'newsletter_subtitle'] as $k) {
            $clean[$k] = $str($input[$k] ?? '', 255);
        }
        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $clean;
    }

    /** Tile tone → background/foreground colours. */
    public static function tone(string $tone): array
    {
        return match ($tone) {
            'orange' => ['bg' => '#fff1e6', 'fg' => '#c2410c'],
            'blue' => ['bg' => '#e6f1fb', 'fg' => '#1d4ed8'],
            'red' => ['bg' => '#fde8e8', 'fg' => '#b91c1c'],
            'yellow' => ['bg' => '#fff8dc', 'fg' => '#a16207'],
            default => ['bg' => '#e8f7ec', 'fg' => '#15803d'],
        };
    }

    /** Category name → icon guess when the admin set none. */
    public static function guessIcon(string $name): string
    {
        $n = Str::lower($name);
        $map = [
            'apple-icon' => ['fruit', 'vegetable', 'produce', 'fresh', 'légume', 'fruta'],
            'milk' => ['dairy', 'milk', 'egg', 'cheese', 'lait'],
            'bread' => ['bakery', 'bread', 'pain', 'pan'],
            'fish' => ['meat', 'fish', 'seafood', 'poultry', 'viande', 'carne'],
            'cup' => ['beverage', 'drink', 'juice', 'coffee', 'tea', 'boisson', 'bebida'],
            'cookie' => ['snack', 'sweet', 'candy', 'chocolate', 'biscuit'],
            'package' => ['pantry', 'grocery', 'canned', 'pasta', 'rice', 'cereal'],
            'snowflake' => ['frozen', 'ice', 'surgelé', 'congelado'],
            'home' => ['household', 'cleaning', 'home', 'ménage', 'hogar'],
            'heart' => ['care', 'beauty', 'health', 'baby', 'pharmacy'],
        ];
        foreach ($map as $icon => $words) {
            foreach ($words as $w) {
                if (Str::contains($n, $w)) {
                    return $icon;
                }
            }
        }

        return 'basket';
    }

    public static function imageUrl($value): ?string
    {
        return ElectronicsTheme::imageUrl($value);
    }

    public static function link($value): string
    {
        return ElectronicsTheme::link($value);
    }
}
