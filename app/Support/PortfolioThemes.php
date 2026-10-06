<?php

namespace App\Support;

/**
 * Portfolio module -- the website themes (Portfolio -> Settings -> Theme) and
 * the website menu (Portfolio -> Settings -> Menu).
 *
 * A theme = its own layout (header / footer / CSS) and gallery; the page
 * contents (Home sections, About, Contact, a work) are shared views that use
 * the same class names, which every theme styles.
 *  minimal  Theme 1 -- the KT-TOOLS/apps/Porfolio example (corner borders, centered logo).
 *  grid     Theme 2 -- Adobe Portfolio photography style ("Thomas": name left, menu right, photo grid).
 *  showcase Theme 3 -- Adobe Portfolio "Marta" (menu left, name centered, full-width works).
 */
class PortfolioThemes
{
    public const ALL = [
        'minimal' => [
            'name' => 'Theme 1 — Minimal',
            'description' => 'Centered logo, corner borders, filter buttons and a 3-column gallery. Dark or light.',
            'layout' => 'portfolio.layout',
            'gallery' => 'portfolio.partials.gallery',
            'default_look' => 'dark',
        ],
        // Themes 2 and 3: Adobe Portfolio photography examples (portfolio.adobe.com/examples/photography),
        // one layout with two variants (body class t-grid / t-showcase, public/portfolio-theme/adobe/adobe.css).
        'grid' => [
            'name' => 'Theme 2 — Grid',
            'description' => 'Adobe Portfolio "Thomas": name on the left, menu on the right, big 2-column photo grid, title on hover. Light or dark.',
            'layout' => 'portfolio.themes.adobe.layout',
            'gallery' => 'portfolio.themes.adobe.gallery',
            'default_look' => 'light',
        ],
        'showcase' => [
            'name' => 'Theme 3 — Showcase',
            'description' => 'Adobe Portfolio "Marta": menu on the left, name in the center, social icons on the right, every work as a full-width photo. Light or dark.',
            'layout' => 'portfolio.themes.adobe.layout',
            'gallery' => 'portfolio.themes.adobe.gallery',
            'default_look' => 'light',
        ],
    ];

    public const PAGES = ['home', 'about', 'work', 'contact'];

    public static function get(?string $key): array
    {
        return self::ALL[$key] ?? self::ALL['minimal'];
    }

    public static function key(?string $key): string
    {
        return isset(self::ALL[$key]) ? $key : 'minimal';
    }

    /** For the back office: [{key, name, description, default_look}]. */
    public static function options(): array
    {
        return collect(self::ALL)->map(fn ($t, $k) => ['key' => $k] + array_intersect_key($t, array_flip(['name', 'description', 'default_look'])))->values()->all();
    }

    /** The menu used when the company never edited it: Home / About / Portfolio / Contact. */
    public static function defaultMenu(array $pages, ?string $aboutTitle = null): array
    {
        $m = [['label' => 'Home', 'type' => 'page', 'page' => 'home']];
        if ($pages['about'] ?? true) {
            $m[] = ['label' => $aboutTitle ?: 'About', 'type' => 'page', 'page' => 'about'];
        }
        $m[] = ['label' => 'Portfolio', 'type' => 'page', 'page' => 'work'];
        if ($pages['contact'] ?? true) {
            $m[] = ['label' => 'Contact', 'type' => 'page', 'page' => 'contact'];
        }

        return array_map(fn ($i) => $i + ['url' => null, 'new_tab' => false, 'visible' => true, 'children' => []], $m);
    }

    /**
     * Clean a menu sent by the back office: known types only, groups one level
     * deep (a group's children are pages / links), at most 12 items x 15 children.
     */
    public static function cleanMenu(?array $menu): ?array
    {
        if ($menu === null) {
            return null;
        }
        $one = function ($i, bool $child) use (&$one) {
            $type = in_array($i['type'] ?? '', ['page', 'link', 'group'], true) ? $i['type'] : 'page';
            if ($child && $type === 'group') {
                $type = 'page';
            }
            $label = trim(mb_substr(strip_tags((string) ($i['label'] ?? '')), 0, 60));
            $page = (string) ($i['page'] ?? '');
            $url = trim((string) ($i['url'] ?? ''));
            if ($type === 'page' && ! (in_array($page, self::PAGES, true) || preg_match('/^(category|project):[a-z0-9-]{1,120}$/', $page))) {
                return null;
            }
            if ($type === 'link' && ! preg_match('#^(https?://|mailto:|tel:)#i', $url)) {
                return null;
            }
            if ($label === '') {
                return null;
            }

            return [
                'label' => $label, 'type' => $type,
                'page' => $type === 'page' ? $page : null,
                'url' => $type === 'link' ? mb_substr($url, 0, 500) : null,
                'new_tab' => $type === 'link' ? (bool) ($i['new_tab'] ?? true) : false,
                'visible' => (bool) ($i['visible'] ?? true),
                'children' => $type === 'group'
                    ? array_values(array_filter(array_map(fn ($c) => $one($c, true), array_slice((array) ($i['children'] ?? []), 0, 15))))
                    : [],
            ];
        };

        return array_values(array_filter(array_map(fn ($i) => $one($i, false), array_slice($menu, 0, 12))));
    }
}
