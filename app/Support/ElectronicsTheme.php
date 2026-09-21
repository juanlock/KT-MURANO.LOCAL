<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Str;

/**
 * Electronics storefront theme — presentation options.
 *
 * Everything the admin can tune for this theme lives under
 * store_settings.theme_options['electronics']. Missing keys fall back to the
 * defaults below, so a fresh install renders a complete homepage before the
 * admin touches anything.
 */
class ElectronicsTheme
{
    public const KEY = 'electronics';

    public const LISTS = ['slides', 'banners', 'trust', 'stats'];

    public const SECTIONS = [
        'trust', 'categories', 'featured', 'banners', 'best_sellers',
        'new_arrivals', 'stats', 'testimonials', 'newsletter', 'brands',
    ];

    public const TRUST_ICONS = ['truck', 'shield-check', 'check-circle', 'clock', 'message', 'gift', 'refresh', 'credit-card', 'star', 'lightning', 'package', 'user', 'phone', 'mail'];

    public static function defaults(): array
    {
        return [
            'slides' => [
                [
                    'kicker' => 'New Arrivals '.date('Y'),
                    'title' => 'Upgrade Your Tech Lifestyle',
                    'subtitle' => 'Discover the latest electronics, smart devices, and accessories designed for performance and innovation.',
                    'image' => 'https://images.unsplash.com/photo-1491933382434-500287f9b54b?w=1400&q=80',
                    'primary_text' => 'Shop Now',
                    'primary_url' => '/shop',
                    'secondary_text' => 'Explore Deals',
                    'secondary_url' => '/shop?deals=1',
                ],
                [
                    'kicker' => 'Limited Time',
                    'title' => 'Sound That Moves You',
                    'subtitle' => 'Premium headphones and speakers from the brands you trust, with free shipping on every order.',
                    'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=1400&q=80',
                    'primary_text' => 'Shop Audio',
                    'primary_url' => '/shop',
                    'secondary_text' => 'View Deals',
                    'secondary_url' => '/shop?deals=1',
                ],
            ],
            'trust' => [
                ['icon' => 'truck', 'title' => 'Free Shipping', 'subtitle' => 'On orders over $49'],
                ['icon' => 'shield-check', 'title' => 'Secure Payments', 'subtitle' => '100% protected'],
                ['icon' => 'check-circle', 'title' => 'Official Warranty', 'subtitle' => 'Brand warranty'],
                ['icon' => 'clock', 'title' => 'Fast Delivery', 'subtitle' => '2–4 business days'],
                ['icon' => 'message', 'title' => '24/7 Support', 'subtitle' => 'Live chat & email'],
            ],
            'banners' => [
                [
                    'kicker' => 'Level Up Your Game',
                    'title' => 'Gaming Gear',
                    'subtitle' => 'Up to 35% Off',
                    'button_text' => 'Shop Gaming',
                    'url' => '/shop',
                    'image' => 'https://images.unsplash.com/photo-1598550476439-6847785fcea6?w=900&q=80',
                    'tone' => 'dark',
                ],
                [
                    'kicker' => 'Smarter Living',
                    'title' => 'Smart Home',
                    'subtitle' => 'Up to 30% Off',
                    'button_text' => 'Shop Now',
                    'url' => '/shop',
                    'image' => 'https://images.unsplash.com/photo-1558002038-1055907df827?w=900&q=80',
                    'tone' => 'light',
                ],
                [
                    'kicker' => 'Immersive Sound',
                    'title' => 'Audio Deals',
                    'subtitle' => 'Up to 40% Off',
                    'button_text' => 'Shop Audio',
                    'url' => '/shop',
                    'image' => 'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=900&q=80',
                    'tone' => 'dark',
                ],
            ],
            'stats' => [
                ['icon' => 'package', 'value' => '{products}+', 'label' => 'Products Available'],
                ['icon' => 'user', 'value' => '{customers}+', 'label' => 'Happy Customers'],
                ['icon' => 'star', 'value' => '{rating}/5', 'label' => 'Customer Rating'],
                ['icon' => 'shield-check', 'value' => '100%', 'label' => 'Secure Shopping'],
                ['icon' => 'check-circle', 'value' => 'Official', 'label' => 'Brand Warranty'],
            ],
            'sections' => array_fill_keys(self::SECTIONS, true),
            'limits' => [
                'featured' => 5,
                'best_sellers' => 4,
                'new_arrivals' => 4,
                'categories' => 8,
            ],
            'testimonials_title' => 'What Our Customers Say',
            'newsletter_title' => 'Stay Ahead with Tech',
            'newsletter_subtitle' => 'Get exclusive deals, new arrivals & tech updates straight to your inbox.',
            'payment_icons' => true,
            'show_theme_toggle' => false,
        ];
    }

    /** Effective options: saved values layered over the defaults. */
    public static function options(?StoreSetting $s): array
    {
        $defaults = self::defaults();
        $raw = $s ? ($s->theme_options ?? null) : null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $saved = is_array($raw) ? ($raw[self::KEY] ?? []) : [];
        if (! is_array($saved)) {
            $saved = [];
        }

        $out = $defaults;

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

        foreach (['testimonials_title', 'newsletter_title', 'newsletter_subtitle'] as $k) {
            if (isset($saved[$k]) && is_string($saved[$k]) && trim($saved[$k]) !== '') {
                $out[$k] = trim($saved[$k]);
            }
        }
        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = filter_var($saved[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /**
     * Normalize an admin-submitted options map before saving: keep only known
     * keys, trim strings, coerce booleans. Unknown keys are dropped.
     */
    public static function sanitize($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }

        $clean = [];
        $str = fn ($v, $max = 500) => Str::limit(trim((string) (is_scalar($v) ? $v : '')), $max, '');

        $fields = [
            'slides' => ['kicker', 'title', 'subtitle', 'image', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'],
            'banners' => ['kicker', 'title', 'subtitle', 'button_text', 'url', 'image', 'tone'],
            'trust' => ['icon', 'title', 'subtitle'],
            'stats' => ['icon', 'value', 'label'],
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
                if ($list === 'banners' && ! in_array($r['tone'], ['dark', 'light'], true)) {
                    $r['tone'] = 'dark';
                }
                // A row with nothing filled in is an empty editor line.
                if (implode('', $r) === '') {
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
        foreach (['featured', 'best_sellers', 'new_arrivals', 'categories'] as $k) {
            if (isset($input['limits'][$k]) && is_numeric($input['limits'][$k])) {
                $clean['limits'][$k] = max(1, min(24, (int) $input['limits'][$k]));
            }
        }

        foreach (['testimonials_title', 'newsletter_title', 'newsletter_subtitle'] as $k) {
            $clean[$k] = $str($input[$k] ?? '', 255);
        }
        foreach (['payment_icons', 'show_theme_toggle'] as $k) {
            if (array_key_exists($k, $input)) {
                $clean[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $clean;
    }

    /** Absolute URL for a slide/banner image (pasted link or uploaded path). */
    public static function imageUrl($value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $v) || $v[0] === '/') {
            return $v;
        }

        return asset($v);
    }

    /**
     * Storefront link: absolute URLs pass through, "/shop?x" style paths are
     * resolved against the configured store base path.
     */
    public static function link($value): string
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '/') {
            return route('store.index');
        }
        if (preg_match('#^(https?:)?//#i', $v) || Str::startsWith($v, ['mailto:', 'tel:', '#'])) {
            return $v;
        }

        return store_base_url(ltrim($v, '/'));
    }

    /** Replace {products} / {customers} / {rating} placeholders in stat values. */
    public static function fillStat(string $value, array $numbers): string
    {
        return strtr($value, [
            '{products}' => self::compact((int) ($numbers['products'] ?? 0)),
            '{customers}' => self::compact((int) ($numbers['customers'] ?? 0)),
            '{rating}' => number_format((float) ($numbers['rating'] ?? 0), 1),
        ]);
    }

    /** 1500 → 1.5K, 60000 → 60K, 2000000 → 2M. */
    public static function compact(int $n): string
    {
        if ($n >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.').'M';
        }
        if ($n >= 1000) {
            return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.').'K';
        }

        return (string) $n;
    }
}
