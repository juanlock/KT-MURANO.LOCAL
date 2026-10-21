<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real Estate examples (2026-10-21: a real estate company had no demo for its
 * own module): property categories (when the company has none), 14 listings
 * for sale and for rent (available / sold / rented, some featured) and 10
 * inquiries from interested buyers in every status. Texts follow the company's
 * language (Spanish or English).
 */
class RealEstateModule extends DemoModule
{
    /** [es, en] */
    private const CATEGORIES = [['Departamento', 'Apartment'], ['Casa', 'House'], ['Terreno', 'Land'], ['Oficina', 'Office'], ['Local comercial', 'Commercial'], ['Casa de playa', 'Beach house']];

    /** [category index, es title, en title, purpose, bedrooms, bathrooms, area m2, price] */
    private const LISTINGS = [
        [0, 'Departamento con vista al mar', 'Sea-view apartment', 'sale', 3, 2, 120, 245000],
        [0, 'Departamento moderno cerca al parque', 'Modern apartment near the park', 'rent', 2, 2, 85, 1400],
        [0, 'Dúplex con terraza', 'Duplex with terrace', 'sale', 4, 3, 180, 320000],
        [0, 'Mini departamento amoblado', 'Furnished studio', 'rent', 1, 1, 42, 850],
        [1, 'Casa familiar con jardín', 'Family house with garden', 'sale', 4, 3, 260, 410000],
        [1, 'Casa de dos pisos en condominio', 'Two-storey house in a gated community', 'sale', 3, 3, 190, 289000],
        [1, 'Casa para alquilar con cochera', 'House for rent with garage', 'rent', 3, 2, 160, 2100],
        [2, 'Terreno esquina para proyecto', 'Corner lot for development', 'sale', null, null, 450, 180000],
        [2, 'Terreno de campo con agua', 'Country lot with water', 'sale', null, null, 1200, 65000],
        [3, 'Oficina en edificio corporativo', 'Office in a corporate tower', 'rent', null, 1, 95, 1800],
        [3, 'Oficina equipada en el centro', 'Furnished downtown office', 'sale', null, 2, 140, 198000],
        [4, 'Local comercial en avenida principal', 'Retail space on the main avenue', 'rent', null, 1, 110, 2600],
        [4, 'Local esquinero con vitrina', 'Corner shop with display window', 'sale', null, 1, 75, 156000],
        [5, 'Casa de playa frente al mar', 'Beachfront house', 'sale', 5, 4, 320, 590000],
    ];

    private const CITIES_ES = [['Miraflores', 'Lima'], ['San Isidro', 'Lima'], ['Surco', 'Lima'], ['La Molina', 'Lima'], ['Barranco', 'Lima'], ['Asia', 'Cañete']];
    private const CITIES_EN = [['Downtown', 'Springfield'], ['Riverside', 'Springfield'], ['Oak Park', 'Springfield'], ['Hillcrest', 'Springfield'], ['Bayview', 'Coastline'], ['Lakeside', 'Coastline']];

    public function key(): string
    {
        return 'realestate';
    }

    public function label(): string
    {
        return 'Real Estate (Property categories, Listings for sale and rent, Inquiries)';
    }

    public function dataTables(): array
    {
        return ['properties', 'property_inquiries'];
    }

    public function requiredTables(): array
    {
        return ['property_categories', 'properties', 'property_inquiries'];
    }

    /** SuperAdmin "Delete real data": inquiries and listings (categories are kept: they are setup). */
    public function wipePlan(): array
    {
        return [['table' => 'property_inquiries'], ['table' => 'properties']];
    }

    public function generate(DemoContext $ctx): array
    {
        $lang = DB::table('settings')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('default_language');
        $es = str_starts_with((string) $lang, 'es');
        $demo = $es ? 'Datos de ejemplo' : 'Demo data';

        // Categories: the company's own when it has them, else these.
        $existing = DB::table('property_categories')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->pluck('id')->all();
        $categories = [];
        $newCategories = 0;
        foreach (self::CATEGORIES as $i => [$nameEs, $nameEn]) {
            if ($existing) {
                $categories[$i] = $existing[$i % count($existing)];
                continue;
            }
            $name = $es ? $nameEs : $nameEn;
            $categories[$i] = $ctx->insert('property_categories', [
                'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'description' => $demo,
            ]);
            $newCategories++;
        }

        // Listings
        $properties = [];
        $cities = $es ? self::CITIES_ES : self::CITIES_EN;
        foreach (self::LISTINGS as $n => [$cat, $titleEs, $titleEn, $purpose, $beds, $baths, $area, $price]) {
            [$district, $city] = $cities[$n % count($cities)];
            $title = $es ? $titleEs : $titleEn;
            // A few already closed: sold / rented.
            $status = $n % 5 === 4 ? ($purpose === 'rent' ? 'rented' : 'sold') : 'available';
            [$first, $last] = $ctx->personName();
            $properties[] = $ctx->insert('properties', [
                'title' => $title,
                'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
                'property_category_id' => $categories[$cat],
                'description' => $es
                    ? "$title en $district. Bien ubicado, listo para visitar. $demo."
                    : "$title in $district. Great location, ready to visit. $demo.",
                'purpose' => $purpose,
                'status' => $status,
                'featured' => $n % 4 === 0 ? 1 : 0,
                'price' => $price,
                'area' => $area,
                'area_unit' => 'm2',
                'bedrooms' => $beds,
                'bathrooms' => $baths,
                'garage' => $beds ? random_int(0, 2) : null,
                'address' => ($es ? 'Calle ' : '').random_int(100, 999).($es ? ' '.$district : ' Main St'),
                'city' => $district,
                'region' => $city,
                'amenities' => json_encode($es ? ['Seguridad 24 h', 'Áreas verdes', 'Estacionamiento'] : ['24 h security', 'Green areas', 'Parking']),
                'agent_name' => "$first $last",
                'agent_phone' => '9'.random_int(10000000, 99999999),
                'agent_email' => strtolower(Str::ascii("$first.$last")).'@example.com',
                'views' => random_int(5, 400),
                'created_by' => $ctx->userId,
            ]);
        }

        // Inquiries on the available listings, in every status.
        $statuses = ['new', 'new', 'new', 'read', 'read', 'responded', 'responded', 'closed', 'new', 'responded'];
        $messages = $es
            ? ['¿Sigue disponible? Me gustaría visitarlo este fin de semana.', '¿Aceptan financiamiento bancario?', 'Quisiera saber el costo de mantenimiento.', '¿Se puede negociar el precio?', '¿Admiten mascotas?']
            : ['Is it still available? I would like to visit this weekend.', 'Do you accept bank financing?', 'What are the maintenance fees?', 'Is the price negotiable?', 'Are pets allowed?'];
        $inquiries = 0;
        foreach ($statuses as $i => $st) {
            [$first, $last] = $ctx->personName();
            $ctx->insert('property_inquiries', [
                'property_id' => $properties[$i % count($properties)],
                'name' => "$first $last",
                'phone' => '9'.random_int(10000000, 99999999),
                'email' => strtolower(Str::ascii("$first.$last")).random_int(10, 99).'@example.com',
                'message' => $messages[$i % count($messages)],
                'status' => $st,
                'created_at' => now()->subDays(random_int(0, 30)),
            ]);
            $inquiries++;
        }

        return array_filter([
            'Property categories' => $newCategories,
            'Properties' => count($properties),
            'Inquiries' => $inquiries,
        ]);
    }
}
