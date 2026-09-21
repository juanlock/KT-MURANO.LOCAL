<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Toys & Baby storefront theme ("jouets") — presentation options, stored under
 * store_settings.theme_options['toys']. Same contract as ElectronicsTheme:
 * defaults() → options() (saved layered over defaults) → sanitize() on save.
 */
class ToysTheme
{
    public const KEY = 'toys';

    public const LISTS = ['slides', 'tiles', 'trust'];

    public const SECTIONS = [
        'categories', 'tiles', 'popular', 'new_arrivals', 'deals', 'trust', 'newsletter', 'testimonials',
    ];

    /** Pastel palette cycled over category circles and promo tiles. */
    public const PASTELS = [
        ['bg' => '#ece9fb', 'fg' => '#6c5ce7'], // lavender
        ['bg' => '#fde4e8', 'fg' => '#e0557a'], // pink
        ['bg' => '#dff3ea', 'fg' => '#2f9e6e'], // mint
        ['bg' => '#fff2cc', 'fg' => '#d99a06'], // yellow
        ['bg' => '#ffe6da', 'fg' => '#e2703a'], // peach
        ['bg' => '#dcedfb', 'fg' => '#2b7dc9'], // sky
        ['bg' => '#f4e6f9', 'fg' => '#9b4dcc'], // lilac
        ['bg' => '#e6f4e6', 'fg' => '#3f9a3f'], // green
    ];

    public const ICONS = ['stroller', 'blocks', 'puzzle', 'shirt', 'bed', 'baby-bottle', 'bath', 'book-open', 'trees', 'baby', 'gift', 'star', 'heart', 'smile', 'rocket', 'sun-icon', 'grid', 'truck', 'shield-check', 'refresh', 'headset', 'clock', 'check-circle', 'apple-icon'];

    public static function defaults(): array
    {
        return [
            'coupon_text' => 'Welcome Offer! Get 15% OFF on your first order',
            'coupon_code' => 'HELLO15',
            'slides' => [
                [
                    'kicker' => 'Everything for your',
                    'title' => "Little Ones'",
                    'title_accent' => 'Big Smiles',
                    'subtitle' => "Safe, quality products for every stage of your child's journey.",
                    'image' => 'https://images.unsplash.com/photo-1602734846297-9299fc2d4703?w=1200&q=80',
                    'badge' => 'Up to 40% OFF',
                    'primary_text' => 'Shop Now',
                    'primary_url' => '/shop',
                    'secondary_text' => 'Explore Deals',
                    'secondary_url' => '/shop?deals=1',
                ],
            ],
            'tiles' => [
                ['title' => 'New Arrivals', 'subtitle' => 'Fresh picks just for you', 'button_text' => 'Shop Now', 'url' => '/shop?sort=latest', 'image' => 'https://images.unsplash.com/photo-1545558014-8692077e9b5c?w=600&q=80', 'color' => 0],
                ['title' => 'Summer Fun', 'subtitle' => 'Outdoor toys & essentials', 'button_text' => 'Shop Now', 'url' => '/shop', 'image' => 'https://images.unsplash.com/photo-1596870230751-ebdfce98ec42?w=600&q=80', 'color' => 3],
                ['title' => 'Nursery Must-Haves', 'subtitle' => 'Create the perfect space for baby', 'button_text' => 'Shop Now', 'url' => '/shop', 'image' => 'https://images.unsplash.com/photo-1544126592-807ade215a0b?w=600&q=80', 'color' => 2],
                ['title' => 'Feeding Time', 'subtitle' => 'Smart choices for happy meals', 'button_text' => 'Shop Now', 'url' => '/shop', 'image' => 'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?w=600&q=80', 'color' => 1],
            ],
            'trust' => [
                ['icon' => 'truck', 'title' => 'Free Shipping', 'subtitle' => 'On orders over $75'],
                ['icon' => 'shield-check', 'title' => 'Safe Payments', 'subtitle' => '100% secure checkout'],
                ['icon' => 'refresh', 'title' => 'Easy Returns', 'subtitle' => '30 days return policy'],
                ['icon' => 'headset', 'title' => '24/7 Support', 'subtitle' => "We're here to help"],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true) + ['testimonials' => false],
            'limits' => ['popular' => 6, 'new_arrivals' => 6, 'deals' => 6, 'categories' => 9],
            'popular_title' => 'Popular Picks',
            'newsletter_title' => 'Join the LittleJoy Family!',
            'newsletter_subtitle' => 'Subscribe for exclusive offers, parenting tips and new arrivals.',
            'tagline' => 'for happy little ones',
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
        foreach (['coupon_text', 'coupon_code', 'popular_title', 'newsletter_title', 'newsletter_subtitle', 'tagline'] as $k) {
            if (array_key_exists($k, $saved) && is_string($saved[$k])) {
                // Empty coupon text/code intentionally hides the pill.
                if (in_array($k, ['coupon_text', 'coupon_code'], true) || trim($saved[$k]) !== '') {
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
            'slides' => ['kicker', 'title', 'title_accent', 'subtitle', 'image', 'badge', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'],
            'tiles' => ['title', 'subtitle', 'button_text', 'url', 'image', 'color'],
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
                    $r[$k] = $k === 'color'
                        ? (string) max(0, min(count(self::PASTELS) - 1, (int) ($row[$k] ?? 0)))
                        : $str($row[$k] ?? '', $k === 'subtitle' ? 1000 : 500);
                }
                $probe = $r;
                unset($probe['color']);
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
        foreach (['popular', 'new_arrivals', 'deals', 'categories'] as $k) {
            if (isset($input['limits'][$k]) && is_numeric($input['limits'][$k])) {
                $clean['limits'][$k] = max(1, min(24, (int) $input['limits'][$k]));
            }
        }
        foreach (['coupon_text', 'coupon_code', 'popular_title', 'newsletter_title', 'newsletter_subtitle', 'tagline'] as $k) {
            $clean[$k] = $str($input[$k] ?? '', 255);
        }
        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $clean;
    }

    public static function pastel($index): array
    {
        $n = count(self::PASTELS);

        return self::PASTELS[((int) $index % $n + $n) % $n];
    }

    /** Category name → icon guess when the admin set none. */
    public static function guessIcon(string $name): string
    {
        $n = Str::lower($name);
        $map = [
            'stroller' => ['gear', 'stroller', 'car seat', 'poussette'],
            'blocks' => ['toy', 'game', 'jouet', 'jeu', 'puzzle', 'block'],
            'shirt' => ['cloth', 'apparel', 'wear', 'vêtement', 'fashion', 'outfit'],
            'bed' => ['nursery', 'bed', 'crib', 'sleep', 'chambre', 'lit'],
            'baby-bottle' => ['feed', 'bottle', 'food', 'meal', 'biberon', 'repas'],
            'bath' => ['bath', 'care', 'hygiene', 'bain', 'soin'],
            'book-open' => ['book', 'read', 'livre', 'story'],
            'trees' => ['outdoor', 'garden', 'plein air', 'bike', 'ride', 'sport'],
            'baby' => ['baby', 'bébé', 'newborn', 'infant'],
            'gift' => ['gift', 'cadeau', 'party'],
        ];
        foreach ($map as $icon => $words) {
            foreach ($words as $w) {
                if (Str::contains($n, $w)) {
                    return $icon;
                }
            }
        }

        return 'star';
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
