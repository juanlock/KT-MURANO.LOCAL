<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Countries (user's rule, 2026-11-21): a company picks its country from a list; every phone field starts with that
 * country's calling code and every country field with that country. The reference data is the `countries` table
 * (name in English, iso2, calling_code). Stored values stay the English name (companies.country, employees.country...).
 */
class Countries
{
    /** [name, iso2, calling_code] of every country, cached. */
    public static function all(): array
    {
        return Cache::remember('countries:list:v1', 86400, fn () => DB::table('countries')->orderBy('name')
            ->get(['name', 'iso2', 'calling_code'])->map(fn ($c) => (array) $c)->all());
    }

    /** The country a free text names ("Peru", "perú", "United States", "... NJ, US - 07420", "PE"), or null. */
    public static function match(?string $text): ?array
    {
        $t = trim((string) $text);
        if ($t === '') {
            return null;
        }
        $low = mb_strtolower($t);
        $alias = ['perú' => 'peru', 'usa' => 'united states', 'eeuu' => 'united states', 'ee.uu.' => 'united states', 'estados unidos' => 'united states',
            'méxico' => 'mexico', 'españa' => 'spain', 'brasil' => 'brazil', 'arabia saudita' => 'saudi arabia'];
        $low = $alias[$low] ?? $low;
        foreach (self::all() as $c) {
            if (mb_strtolower($c['name']) === $low || mb_strtolower($c['iso2']) === $low) {
                return $c;
            }
        }
        // An address typed in the country field: the US by its marks.
        if (preg_match('/\b(united states|usa|u\.s\.a?\.?|us)\b/iu', $t)) {
            return collect(self::all())->firstWhere('iso2', 'US');
        }

        return null;
    }

    /** The company's country, or null. */
    public static function ofCompany(?int $companyId): ?array
    {
        if (! $companyId) {
            return null;
        }

        return self::match(DB::table('companies')->where('id', $companyId)->value('country'));
    }
}
