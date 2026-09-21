<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Canonical country data for address forms and region matching.
 *
 * The stable key is the ISO 3166-1 alpha-2 code; display names come from ICU
 * (ext-intl), so a dropdown reads "Mexico" in English and "México" in Spanish
 * without shipping a translated name list.
 *
 * toCode() is the important part: shipping regions and tax rates used to be
 * compared as raw strings, so a customer typing "mexico" would not match an
 * admin's "México" and checkout answered "No shipping method is available for
 * your region". Everything normalizes to a code before comparing now, which
 * also rescues values typed before the dropdowns existed.
 */
class CountryService
{
    /** ISO 3166-1 alpha-2. */
    public const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
        'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
        'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC',
        'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
        'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG',
        'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
        'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
        'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /**
     * First-level subdivisions for the countries where a state/province is
     * normally part of an address. Countries absent from this map keep a plain
     * text field — add a list here to turn it into a picker.
     */
    public const SUBDIVISIONS = [
        'US' => [
            'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut',
            'Delaware', 'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois',
            'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts',
            'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
            'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota',
            'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina',
            'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington',
            'West Virginia', 'Wisconsin', 'Wyoming',
        ],
        'CA' => [
            'Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland and Labrador',
            'Northwest Territories', 'Nova Scotia', 'Nunavut', 'Ontario', 'Prince Edward Island',
            'Quebec', 'Saskatchewan', 'Yukon',
        ],
        'MX' => [
            'Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas',
            'Chihuahua', 'Ciudad de México', 'Coahuila', 'Colima', 'Durango', 'Estado de México',
            'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'Michoacán', 'Morelos', 'Nayarit',
            'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo', 'San Luis Potosí',
            'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatán', 'Zacatecas',
        ],
        'ES' => [
            'Andalucía', 'Aragón', 'Asturias', 'Islas Baleares', 'Canarias', 'Cantabria',
            'Castilla-La Mancha', 'Castilla y León', 'Cataluña', 'Ceuta', 'Comunidad Valenciana',
            'Extremadura', 'Galicia', 'La Rioja', 'Madrid', 'Melilla', 'Murcia', 'Navarra', 'País Vasco',
        ],
        'AU' => [
            'Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland',
            'South Australia', 'Tasmania', 'Victoria', 'Western Australia',
        ],
        'DE' => [
            'Baden-Württemberg', 'Bayern', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hessen',
            'Mecklenburg-Vorpommern', 'Niedersachsen', 'Nordrhein-Westfalen', 'Rheinland-Pfalz',
            'Saarland', 'Sachsen', 'Sachsen-Anhalt', 'Schleswig-Holstein', 'Thüringen',
        ],
        'IT' => [
            'Abruzzo', 'Basilicata', 'Calabria', 'Campania', 'Emilia-Romagna', 'Friuli-Venezia Giulia',
            'Lazio', 'Liguria', 'Lombardia', 'Marche', 'Molise', 'Piemonte', 'Puglia', 'Sardegna',
            'Sicilia', 'Toscana', 'Trentino-Alto Adige', 'Umbria', "Valle d'Aosta", 'Veneto',
        ],
        'GB' => ['England', 'Northern Ireland', 'Scotland', 'Wales'],
        'BR' => [
            'Acre', 'Alagoas', 'Amapá', 'Amazonas', 'Bahia', 'Ceará', 'Distrito Federal',
            'Espírito Santo', 'Goiás', 'Maranhão', 'Mato Grosso', 'Mato Grosso do Sul',
            'Minas Gerais', 'Pará', 'Paraíba', 'Paraná', 'Pernambuco', 'Piauí', 'Rio de Janeiro',
            'Rio Grande do Norte', 'Rio Grande do Sul', 'Rondônia', 'Roraima', 'Santa Catarina',
            'São Paulo', 'Sergipe', 'Tocantins',
        ],
        'AR' => [
            'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Ciudad Autónoma de Buenos Aires',
            'Córdoba', 'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja',
            'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis',
            'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
        ],
        'ZA' => [
            'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga',
            'North West', 'Northern Cape', 'Western Cape',
        ],
        'AE' => [
            'Abu Dhabi', 'Ajman', 'Dubai', 'Fujairah', 'Ras Al Khaimah', 'Sharjah', 'Umm Al Quwain',
        ],
        'SA' => [
            'Al Bahah', 'Al Jawf', 'Al Madinah', 'Al Qassim', 'Aseer', 'Eastern Province', "Ha'il",
            'Jazan', 'Makkah', 'Najran', 'Northern Borders', 'Riyadh', 'Tabuk',
        ],
        'MA' => [
            'Béni Mellal-Khénifra', 'Casablanca-Settat', 'Dakhla-Oued Ed-Dahab', 'Drâa-Tafilalet',
            'Fès-Meknès', 'Guelmim-Oued Noun', 'Laâyoune-Sakia El Hamra', 'Marrakech-Safi',
            'Oriental', 'Rabat-Salé-Kénitra', 'Souss-Massa', 'Tanger-Tétouan-Al Hoceïma',
        ],
    ];

    /** The country's name in a given locale (falls back to the code itself). */
    public static function name(string $code, ?string $locale = null): string
    {
        $code = strtoupper(trim($code));
        $locale = $locale ?: app()->getLocale();

        $name = \Locale::getDisplayRegion('-'.$code, $locale);

        return ($name && $name !== $code) ? $name : $code;
    }

    /**
     * [['code' => 'MX', 'name' => 'México', 'canonical' => 'Mexico'], …]
     * sorted by the localized name.
     *
     * `name` is what the dropdown shows; `canonical` is the English name that
     * gets stored, so an address still reads "Mexico" on an invoice no matter
     * which language the customer checked out in.
     */
    public static function options(?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();

        return Cache::remember('country_options_'.$locale, 86400, function () use ($locale) {
            $out = [];
            foreach (self::CODES as $code) {
                $out[] = [
                    'code' => $code,
                    'name' => self::name($code, $locale),
                    'canonical' => self::name($code, 'en'),
                ];
            }
            usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

            return $out;
        });
    }

    /** Subdivisions for the countries we ship a list for; [] otherwise. */
    public static function subdivisions(?string $country): array
    {
        $code = self::toCode($country);

        return $code ? (self::SUBDIVISIONS[$code] ?? []) : [];
    }

    /** Every subdivision list, keyed by country code — for client-side use. */
    public static function subdivisionMap(): array
    {
        return self::SUBDIVISIONS;
    }

    /**
     * Resolve anything a human or an old record might hold — "MX", "Mexico",
     * "méxico", "MEXICO " — to an ISO alpha-2 code, or null.
     */
    public static function toCode(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $upper = strtoupper($value);
        if (strlen($upper) === 2 && in_array($upper, self::CODES, true)) {
            return $upper;
        }

        $index = self::nameIndex();

        return $index[self::normalize($value)]
            ?? $index[self::normalizeUnicode($value)]
            ?? null;
    }

    /** True when two country values mean the same place. */
    public static function sameCountry(?string $a, ?string $b): bool
    {
        $ca = self::toCode($a);
        $cb = self::toCode($b);

        if ($ca !== null && $cb !== null) {
            return $ca === $cb;
        }

        // Neither resolves (a custom region name) — fall back to a loose compare.
        $na = self::normalize((string) $a);

        return $na !== '' && $na === self::normalize((string) $b);
    }

    /** Normalized name => code, across every locale the storefront renders. */
    private static function nameIndex(): array
    {
        return Cache::remember('country_name_index', 86400, function () {
            $locales = array_keys(store_locales());
            $locales[] = 'en';

            $index = [];
            foreach (self::CODES as $code) {
                foreach (array_unique($locales) as $locale) {
                    $name = self::name($code, $locale);
                    // Two keys per name: ASCII-folded (so "México" matches
                    // "mexico") and Unicode-only (so Arabic/Chinese names,
                    // which ASCII folding erases, still resolve).
                    foreach ([self::normalize($name), self::normalizeUnicode($name)] as $key) {
                        if ($key !== '') {
                            $index[$key] = $index[$key] ?? $code;
                        }
                    }
                }
            }

            // Spellings ICU does not return but people still type.
            $aliases = [
                'usa' => 'US', 'unitedstatesofamerica' => 'US', 'uk' => 'GB',
                'greatbritain' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
                'uae' => 'AE', 'southkorea' => 'KR', 'northkorea' => 'KP',
                'russia' => 'RU', 'vietnam' => 'VN', 'ivorycoast' => 'CI', 'holland' => 'NL',
            ];
            foreach ($aliases as $alias => $code) {
                $index[$alias] = $index[$alias] ?? $code;
            }

            return $index;
        });
    }

    /** Lowercase, strip accents and anything that is not a letter or digit. */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false && $ascii !== '') {
            $value = $ascii;
        }

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }

    /** Lowercase and drop separators, but keep non-Latin letters intact. */
    private static function normalizeUnicode(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? '';
    }
}
