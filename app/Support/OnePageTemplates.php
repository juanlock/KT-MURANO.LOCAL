<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website Management -> One-page template (user's request, 2026-10-20): a
 * one-page website (sections + a menu that scrolls to them), five designs
 * chosen by business type, layouts after the Bootstrap examples (MIT;
 * rendered with the bundled Bootstrap 5.3, resources/views/website/onepage.blade.php):
 *
 *   services  -- "Carousel":  image hero, icon columns, alternating featurettes
 *   food      -- "Cover":     full-screen dark hero, menu list with prices
 *   beauty    -- "Album":     light jumbotron, card grid, big gallery
 *   cleaning  -- "Pricing":   centered hero, pricing cards
 *   corporate -- "Product":   split hero, feature boxes, dark bands
 *
 * Stored in website_settings.onepage (JSON):
 *   {template, color, show_prices, links: [{label: {en, es}, url}],
 *    sections: [{id, type, on, menu: {en, es}, title: {..}, text: {..},
 *                button: {..}, link, image, items: [{title: {..}, text: {..}, price, image, name}]}]}
 * Texts are plain text (escaped on render), one value per language (en / es).
 * Contact details, gallery and portfolio are read live from the company.
 */
class OnePageTemplates
{
    public const LOCALES = ['en', 'es'];

    public const TYPES = ['hero', 'about', 'services', 'gallery', 'portfolio', 'testimonials', 'cta', 'contact'];

    /** key => [name, Bootstrap example, default colour, description EN, description ES]. */
    public const TEMPLATES = [
        'services' => ['Services', 'Carousel', '#0d6efd', 'Image hero, service columns with icons and alternating feature rows.', 'Portada con imagen, columnas de servicios con iconos y filas destacadas alternadas.'],
        'food' => ['Restaurant', 'Cover', '#c0392b', 'Full-screen dark hero and a menu with prices.', 'Portada oscura a pantalla completa y un menú con precios.'],
        'beauty' => ['Beauty & style', 'Album', '#c2185b', 'Soft, elegant layout with service cards and a large gallery.', 'Diseño suave y elegante con tarjetas de servicios y una galería grande.'],
        'cleaning' => ['Cleaning & laundry', 'Pricing', '#0aa2c0', 'Clean centered hero and pricing cards for each service.', 'Portada centrada y limpia con tarjetas de precios por servicio.'],
        'corporate' => ['Corporate', 'Product', '#1f3a5f', 'Split hero, feature boxes and dark bands for a professional look.', 'Portada dividida, cuadros de características y franjas oscuras, aspecto profesional.'],
    ];

    /** Business type (App\Support\BusinessTypes) => template. */
    public const BY_TYPE = [
        'general' => 'services', 'it_services' => 'services', 'landscaping' => 'services', 'delivery' => 'services',
        'factory' => 'services', 'call_center' => 'services', 'retail' => 'services',
        'restaurant' => 'food', 'catering' => 'food',
        'salon' => 'beauty', 'barber' => 'beauty',
        'laundry' => 'cleaning',
        'professional' => 'corporate', 'legal' => 'corporate', 'real_estate' => 'corporate', 'hospital' => 'corporate', 'school' => 'corporate',
    ];

    /** Default section order per template. */
    private const ORDER = [
        'services' => ['hero', 'services', 'about', 'portfolio', 'gallery', 'testimonials', 'cta', 'contact'],
        'food' => ['hero', 'about', 'services', 'gallery', 'testimonials', 'cta', 'contact'],
        'beauty' => ['hero', 'services', 'gallery', 'about', 'portfolio', 'testimonials', 'cta', 'contact'],
        'cleaning' => ['hero', 'services', 'about', 'gallery', 'testimonials', 'cta', 'contact'],
        'corporate' => ['hero', 'about', 'services', 'portfolio', 'testimonials', 'cta', 'contact'],
    ];

    /** Menu labels per section type. */
    private const MENU = [
        'hero' => ['Home', 'Inicio'], 'about' => ['About', 'Nosotros'], 'services' => ['Services', 'Servicios'],
        'gallery' => ['Gallery', 'Galería'], 'portfolio' => ['Our work', 'Trabajos'], 'testimonials' => ['Reviews', 'Opiniones'],
        'cta' => ['Book', 'Reservar'], 'contact' => ['Contact', 'Contacto'],
    ];

    /** Starting texts per template: type => [title EN, title ES, text EN, text ES, button EN, button ES]. {name} = the company. */
    private const TEXTS = [
        'services' => [
            'hero' => ['{name}', '{name}', 'Reliable service, done right. On time, every time.', 'Servicio confiable y bien hecho. A tiempo, siempre.', 'Get in touch', 'Contáctenos'],
            'about' => ['Why choose us', 'Por qué elegirnos', 'We are a local team that cares about every job. Clear prices, quick answers and work we stand behind.', 'Somos un equipo local que cuida cada trabajo. Precios claros, respuestas rápidas y trabajo que respaldamos.', '', ''],
            'services' => ['What we do', 'Lo que hacemos', 'Services for homes and businesses.', 'Servicios para hogares y empresas.', '', ''],
        ],
        'food' => [
            'hero' => ['{name}', '{name}', 'Fresh food, made with love. Come hungry.', 'Comida fresca, hecha con cariño. Ven con hambre.', 'See the menu', 'Ver el menú'],
            'about' => ['Our kitchen', 'Nuestra cocina', 'Recipes we grew up with, fresh ingredients every day and a table waiting for you.', 'Recetas de toda la vida, ingredientes frescos cada día y una mesa esperándote.', '', ''],
            'services' => ['Menu', 'Menú', 'A taste of what we serve.', 'Una muestra de lo que servimos.', '', ''],
        ],
        'beauty' => [
            'hero' => ['{name}', '{name}', 'Look good, feel better. Your style, our passion.', 'Luce bien, siéntete mejor. Tu estilo, nuestra pasión.', 'Book now', 'Reserva ahora'],
            'about' => ['About us', 'Sobre nosotros', 'A relaxed place, a talented team and the care you deserve on every visit.', 'Un lugar relajado, un equipo talentoso y la atención que mereces en cada visita.', '', ''],
            'services' => ['Services', 'Servicios', 'Pick your service and leave the rest to us.', 'Elige tu servicio y déjanos el resto.', '', ''],
        ],
        'cleaning' => [
            'hero' => ['{name}', '{name}', 'Clean clothes, free time. We pick up and deliver.', 'Ropa limpia, tiempo libre. Recogemos y entregamos.', 'See prices', 'Ver precios'],
            'about' => ['How it works', 'Cómo funciona', 'Schedule a pickup, we wash, dry and fold with care, and bring everything back fresh.', 'Programa una recogida, lavamos, secamos y doblamos con cuidado, y te lo devolvemos todo fresco.', '', ''],
            'services' => ['Prices', 'Precios', 'Simple prices, no surprises.', 'Precios simples, sin sorpresas.', '', ''],
        ],
        'corporate' => [
            'hero' => ['{name}', '{name}', 'Experience and trust to move your plans forward.', 'Experiencia y confianza para hacer avanzar sus planes.', 'Talk to us', 'Hable con nosotros'],
            'about' => ['Who we are', 'Quiénes somos', 'A team of professionals focused on results, clear communication and long-term relationships.', 'Un equipo de profesionales enfocado en resultados, comunicación clara y relaciones a largo plazo.', '', ''],
            'services' => ['Practice areas', 'Áreas de servicio', 'How we can help you.', 'Cómo podemos ayudarle.', '', ''],
        ],
    ];

    /** Shared texts (every template). */
    private const COMMON = [
        'gallery' => ['Gallery', 'Galería', '', ''],
        'portfolio' => ['Our work', 'Nuestros trabajos', 'Some recent projects.', 'Algunos proyectos recientes.'],
        'testimonials' => ['What our customers say', 'Lo que dicen nuestros clientes', '', ''],
        'cta' => ['Ready when you are', 'Listos cuando usted lo esté', 'Book online in a minute.', 'Reserve en línea en un minuto.', 'Book online', 'Reservar en línea'],
        'contact' => ['Contact us', 'Contáctenos', 'Call, write or visit us.', 'Llámenos, escríbanos o visítenos.'],
    ];

    /** Placeholder services when the company has no products yet. */
    private const PLACEHOLDER_ITEMS = [
        ['Service one', 'Servicio uno', 'Describe this service in one or two lines.', 'Describa este servicio en una o dos líneas.'],
        ['Service two', 'Servicio dos', 'Describe this service in one or two lines.', 'Describa este servicio en una o dos líneas.'],
        ['Service three', 'Servicio tres', 'Describe this service in one or two lines.', 'Describa este servicio en una o dos líneas.'],
    ];

    public static function forType(?string $businessType): string
    {
        return self::BY_TYPE[BusinessTypes::valid($businessType)] ?? 'services';
    }

    /** Templates for the editor. */
    public static function list(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $k => [$name, $example, $color, $en, $es]) {
            $out[] = ['key' => $k, 'name' => $name, 'example' => $example, 'color' => $color, 'description' => ['en' => $en, 'es' => $es]];
        }

        return $out;
    }

    private static function tr(string $en, string $es, string $name = ''): array
    {
        return ['en' => str_replace('{name}', $name, $en), 'es' => str_replace('{name}', $name, $es)];
    }

    /** A new one-page website for the company, with the template's sections and texts and the company's own data. */
    public static function build(int $companyId, ?string $template = null): array
    {
        $template = isset(self::TEMPLATES[$template]) ? $template : self::forType(BusinessTypes::of($companyId));
        $name = self::companyName($companyId);
        $bookings = ModuleFlags::enabled($companyId, 'bookings');
        $sections = [];
        foreach (self::ORDER[$template] as $type) {
            $sections[] = self::section($companyId, $template, $type, $name, $bookings);
        }

        return [
            'template' => $template,
            'color' => self::TEMPLATES[$template][2],
            'show_prices' => in_array($template, ['food', 'beauty', 'cleaning'], true),
            'links' => [],
            'sections' => $sections,
        ];
    }

    /** Switch template: the new design and section order; texts, images and items the admin already has are kept. */
    public static function switchTo(array $page, string $template, int $companyId): array
    {
        if (! isset(self::TEMPLATES[$template])) {
            return $page;
        }
        $fresh = self::build($companyId, $template);
        $old = collect($page['sections'] ?? [])->keyBy('type');
        $fresh['sections'] = array_map(fn ($s) => $old->has($s['type']) ? $old[$s['type']] : $s, $fresh['sections']);
        // Sections the admin added that the new template doesn't list stay, at the end.
        foreach ($page['sections'] ?? [] as $s) {
            if (! collect($fresh['sections'])->contains('id', $s['id'])) {
                $fresh['sections'][] = $s;
            }
        }
        $fresh['links'] = $page['links'] ?? [];

        return $fresh;
    }

    private static function section(int $companyId, string $template, string $type, string $name, bool $bookings): array
    {
        $t = self::TEXTS[$template][$type] ?? self::COMMON[$type] ?? ['', '', '', ''];
        $s = [
            'id' => $type,
            'type' => $type,
            'on' => match ($type) {
                'testimonials' => false,                                     // no invented reviews: the admin adds real ones
                'cta' => $bookings,
                'gallery' => self::hasGallery($companyId),
                'portfolio' => ModuleFlags::enabled($companyId, 'portfolio') && self::hasPortfolio($companyId),
                default => true,
            },
            'menu' => self::tr(...self::MENU[$type]),
            'title' => self::tr($t[0], $t[1], $name),
            'text' => self::tr($t[2], $t[3], $name),
            'button' => self::tr($t[4] ?? '', $t[5] ?? ''),
            'link' => match ($type) {
                'hero' => $template === 'food' || $template === 'cleaning' || $template === 'beauty' ? '#services' : '#contact',
                'cta' => '/book?company='.$companyId,
                default => '',
            },
            'image' => null,
            'items' => [],
        ];
        if ($type === 'hero') {
            $s['image'] = self::firstGalleryImage($companyId);
            if ($template === 'beauty' && $bookings) {
                $s['link'] = '/book?company='.$companyId;
            }
        }
        if ($type === 'services') {
            $s['items'] = self::serviceItems($companyId);
        }
        if ($type === 'cta') {
            $s['menu'] = self::tr(...self::MENU['cta']);
        }

        return $s;
    }

    private static function companyName(int $companyId): string
    {
        return (string) (DB::table('website_settings')->where('company_id', $companyId)->value('site_name')
            ?: DB::table('companies')->where('id', $companyId)->value('name') ?: '');
    }

    private static function hasGallery(int $companyId): bool
    {
        return Schema::hasTable('store_gallery_images')
            && DB::table('store_gallery_images')->where('company_id', $companyId)->where('active', 1)->exists();
    }

    private static function hasPortfolio(int $companyId): bool
    {
        return Schema::hasTable('portfolio_projects')
            && DB::table('portfolio_projects')->where('company_id', $companyId)->where('published', 1)->exists();
    }

    private static function firstGalleryImage(int $companyId): ?string
    {
        return self::hasGallery($companyId)
            ? DB::table('store_gallery_images')->where('company_id', $companyId)->where('active', 1)->orderBy('sort_order')->orderBy('id')->value('image')
            : null;
    }

    /** Up to 6 of the company's services / products (services first), else placeholders. */
    private static function serviceItems(int $companyId): array
    {
        $symbol = DB::table('currencies')->where('id', DB::table('settings')->where('company_id', $companyId)->value('currency_id'))->value('symbol') ?: '$';
        $rows = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', 1))
            ->orderByRaw("type = 'is_service' desc")->orderByDesc('show_on_booking_form')->orderBy('name')
            ->limit(6)->get(['name', 'price', 'note']);
        if ($rows->isEmpty()) {
            return array_map(fn ($p) => ['title' => self::tr($p[0], $p[1]), 'text' => self::tr($p[2], $p[3]), 'price' => '', 'image' => null], self::PLACEHOLDER_ITEMS);
        }

        return $rows->map(fn ($p) => [
            'title' => ['en' => $p->name, 'es' => $p->name],
            'text' => ['en' => '', 'es' => ''],
            'price' => (float) $p->price > 0 ? $symbol.number_format((float) $p->price, 2) : '',
            'image' => null,
        ])->all();
    }

    /** The saved website, cleaned: known template / types only, plain text, safe links, this company's images only. */
    public static function sanitize(array $in, int $companyId): array
    {
        $template = isset(self::TEMPLATES[$in['template'] ?? '']) ? $in['template'] : 'services';
        $text = function ($v, int $max) {
            $out = [];
            foreach (self::LOCALES as $l) {
                $out[$l] = mb_substr(trim(strip_tags((string) (is_array($v) ? ($v[$l] ?? '') : ($l === 'en' ? $v : '')))), 0, $max);
            }

            return $out;
        };
        $image = fn ($p) => is_string($p) && str_starts_with($p, 'files/company_'.$companyId.'/') && ! str_contains($p, '..') && is_file(public_path($p)) ? $p : null;
        $sections = [];
        $ids = [];
        foreach (array_slice((array) ($in['sections'] ?? []), 0, 20) as $s) {
            if (! is_array($s) || ! in_array($s['type'] ?? '', self::TYPES, true)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($s['id'] ?? $s['type']))) ?: $s['type'];
            while (in_array($id, $ids, true)) {
                $id .= '-2';
            }
            $ids[] = $id;
            $items = [];
            foreach (array_slice((array) ($s['items'] ?? []), 0, 24) as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $items[] = [
                    'title' => $text($it['title'] ?? '', 120),
                    'text' => $text($it['text'] ?? '', 600),
                    'price' => mb_substr(trim(strip_tags((string) ($it['price'] ?? ''))), 0, 40),
                    'image' => $image($it['image'] ?? null),
                ];
            }
            $sections[] = [
                'id' => $id,
                'type' => $s['type'],
                'on' => (bool) ($s['on'] ?? true),
                'menu' => $text($s['menu'] ?? '', 40),
                'title' => $text($s['title'] ?? '', 160),
                'text' => $text($s['text'] ?? '', 3000),
                'button' => $text($s['button'] ?? '', 60),
                'link' => self::link($s['link'] ?? ''),
                'image' => $image($s['image'] ?? null),
                'items' => $items,
            ];
        }
        $links = [];
        foreach (array_slice((array) ($in['links'] ?? []), 0, 8) as $l) {
            if (is_array($l) && ($url = self::link($l['url'] ?? ''))) {
                $links[] = ['label' => $text($l['label'] ?? '', 40), 'url' => $url];
            }
        }

        return [
            'template' => $template,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['color'] ?? '')) ? strtolower($in['color']) : self::TEMPLATES[$template][2],
            'show_prices' => (bool) ($in['show_prices'] ?? false),
            'links' => $links,
            'sections' => $sections,
        ];
    }

    /** Only #section, /path, http(s), mailto: and tel: links. */
    public static function link($v): string
    {
        $v = trim((string) $v);
        if ($v === '' || mb_strlen($v) > 500) {
            return '';
        }

        return preg_match('~^(#[A-Za-z0-9_-]+|/(?!/)[^\s]*|https?://[^\s]+|mailto:[^\s]+|tel:[+\d\s().-]+)$~', $v) ? $v : '';
    }
}
