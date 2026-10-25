<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portfolio tablet catalogs (2026-10-25): settings of a catalog and the data the
 * tablet page shows. Types: menu (restaurant / food) and models (salon / barber:
 * the customer first answers the catalog's steps). Dishes / services are the
 * company's Products (picture, name, price come from there).
 */
class PortfolioCatalog
{
    public const TYPES = ['menu', 'models'];

    public static function defaults(string $type, string $lang = 'es'): array
    {
        $es = $lang === 'es';

        return [
            'title' => '',
            'subtitle' => '',
            'logo' => '',
            'slides' => [],                       // picture paths (files/company_{id}/portfolio/...)
            'accent' => $type === 'menu' ? '#f5a623' : '#c9a227',
            'language' => $es ? 'es' : 'en',
            'show_prices' => true,
            'orders' => true,                     // the customer can send an order
            'ask_table' => true,                  // ask for the table / name before sending
            'table_label' => $type === 'menu' ? ($es ? 'Mesa' : 'Table') : ($es ? 'Nombre' : 'Name'),
            'send_kitchen' => $type === 'menu',   // the order goes to the Kitchen display
            'hold_in_pos' => true,                // and waits in the POS to be charged
            'thanks' => $es ? '¡Gracias! Su pedido fue enviado.' : 'Thank you! Your order was sent.',
            'steps' => $type === 'models' ? [
                ['id' => 's1', 'name' => $es ? '¿Para quién es?' : 'Who is it for?', 'options' => [
                    ['id' => 'o1', 'label' => $es ? 'Hombre' : 'Man', 'image' => ''],
                    ['id' => 'o2', 'label' => $es ? 'Mujer' : 'Woman', 'image' => ''],
                ]],
                ['id' => 's2', 'name' => $es ? 'Edad' : 'Age', 'options' => [
                    ['id' => 'o3', 'label' => $es ? 'Adulto' : 'Adult', 'image' => ''],
                    ['id' => 'o4', 'label' => $es ? 'Niño' : 'Child', 'image' => ''],
                ]],
            ] : [],
        ];
    }

    public static function settings(?array $saved, string $type): array
    {
        $d = self::defaults($type, ($saved['language'] ?? 'es') === 'en' ? 'en' : 'es');
        $s = array_merge($d, array_intersect_key($saved ?? [], $d));
        foreach (['title', 'subtitle', 'table_label', 'thanks'] as $k) {
            $s[$k] = mb_substr(strip_tags((string) $s[$k]), 0, 190);
        }
        $s['logo'] = self::path($s['logo']);
        $s['slides'] = array_values(array_filter(array_map([self::class, 'path'], (array) $s['slides'])));
        $s['accent'] = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $s['accent']) ? $s['accent'] : $d['accent'];
        $s['language'] = in_array($s['language'], ['es', 'en'], true) ? $s['language'] : 'es';
        foreach (['show_prices', 'orders', 'ask_table', 'send_kitchen', 'hold_in_pos'] as $k) {
            $s[$k] = (bool) $s[$k];
        }
        // Steps (models): [{id, name, options: [{id, label, image}]}]
        $steps = [];
        foreach ((array) $s['steps'] as $i => $st) {
            if (! is_array($st)) {
                continue;
            }
            $opts = [];
            foreach ((array) ($st['options'] ?? []) as $j => $o) {
                $label = mb_substr(trim(strip_tags((string) ($o['label'] ?? ''))), 0, 60);
                if ($label === '') {
                    continue;
                }
                $opts[] = ['id' => preg_match('/^[a-z0-9]{1,12}$/', (string) ($o['id'] ?? '')) ? $o['id'] : 'o'.Str::lower(Str::random(6)),
                    'label' => $label, 'image' => self::path($o['image'] ?? '')];
            }
            $name = mb_substr(trim(strip_tags((string) ($st['name'] ?? ''))), 0, 80);
            if ($name === '' || ! $opts) {
                continue;
            }
            $steps[] = ['id' => preg_match('/^[a-z0-9]{1,12}$/', (string) ($st['id'] ?? '')) ? $st['id'] : 's'.Str::lower(Str::random(6)), 'name' => $name, 'options' => $opts];
        }
        $s['steps'] = $type === 'models' ? array_slice($steps, 0, 6) : [];

        return $s;
    }

    /** A stored file path relative to public/ (our company files only), '' otherwise. */
    public static function path($p): string
    {
        $p = trim((string) $p);

        return ($p !== '' && ! str_contains($p, '..') && preg_match('~^files/company_\d+/[\w./-]+$~', $p)) ? $p : '';
    }

    public static function url(?string $p): ?string
    {
        $p = trim((string) $p);
        if ($p === '') {
            return null;
        }

        return Str::startsWith($p, ['http://', 'https://']) ? $p : '/'.implode('/', array_map('rawurlencode', explode('/', ltrim($p, '/'))));
    }

    /** A product's picture as a URL (files/company_.../products/... or a bare name in images/products/). */
    public static function productImage(?string $image): ?string
    {
        $image = trim((string) $image);
        if ($image === '' || str_contains($image, 'no-image')) {
            return null;
        }

        return self::url(str_contains($image, '/') ? $image : 'images/products/'.$image);
    }

    public static function currency(int $companyId): string
    {
        $cur = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('currency_id');

        return (string) ($cur ? DB::table('currencies')->where('id', $cur)->value('symbol') : '') ?: '$';
    }

    /**
     * Everything the tablet page needs: the catalog's look, its steps, groups and
     * items (product name / price / picture), only products still on sale.
     */
    public static function publicData(object $c): array
    {
        $s = self::settings(json_decode((string) $c->settings, true), $c->type);
        $groups = DB::table('portfolio_catalog_groups')->where('catalog_id', $c->id)->orderBy('sort_order')->orderBy('id')->get();
        $items = DB::table('portfolio_catalog_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.catalog_id', $c->id)->where('p.company_id', $c->company_id)->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->whereNull('p.is_active')->orWhere('p.is_active', 1))
            ->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['i.id', 'i.group_id', 'i.product_id', 'i.options', 'p.name', 'p.price', 'p.image', 'p.note']);
        $company = DB::table('companies')->where('id', $c->company_id)->value('name');

        return [
            'key' => $c->public_key, 'type' => $c->type, 'name' => $c->name, 'company' => $company,
            'title' => $s['title'] ?: $c->name, 'subtitle' => $s['subtitle'],
            'logo' => self::url($s['logo']), 'slides' => array_map([self::class, 'url'], $s['slides']),
            'accent' => $s['accent'], 'language' => $s['language'], 'currency' => self::currency((int) $c->company_id),
            'show_prices' => $s['show_prices'], 'orders' => $s['orders'], 'ask_table' => $s['ask_table'],
            'table_label' => $s['table_label'], 'thanks' => $s['thanks'],
            'steps' => array_map(fn ($st) => $st + ['options' => array_map(fn ($o) => ['id' => $o['id'], 'label' => $o['label'], 'image' => self::url($o['image'])], $st['options'])], $s['steps']),
            'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'image' => self::url($g->image)])->values(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id, 'group_id' => $i->group_id, 'product_id' => $i->product_id, 'name' => $i->name,
                'price' => round((float) $i->price, 2), 'image' => self::productImage($i->image),
                'note' => $i->note ? Str::limit(strip_tags((string) $i->note), 140) : null,
                'options' => (object) (json_decode((string) $i->options, true) ?: []),
            ])->values(),
        ];
    }
}
