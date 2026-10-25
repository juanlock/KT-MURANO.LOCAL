<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Dates follow the company's country (user's rule, 2026-10-25): Peru and the
 * other Spanish-speaking countries write day-month-year (25-10-2026), the
 * United States month-day-year (10-25-2026). Stored in settings.date_format
 * (dayjs tokens), used by every date shown and typed in the app.
 */
class DateFormats
{
    public const DMY = 'DD-MM-YYYY';

    public const MDY = 'MM-DD-YYYY';

    /** The format for a country name (free text: "Peru", "United States", "... NJ, US - 07420"), or null if unknown. */
    public static function forCountry(?string $country): ?string
    {
        $c = mb_strtolower(trim((string) $country));
        if ($c === '') {
            return null;
        }
        if (preg_match('/\b(united states|usa|u\.s\.a?\.?|us)\b/u', $c)) {
            return self::MDY;
        }
        $dmy = ['peru', 'perú', 'mexico', 'méxico', 'colombia', 'ecuador', 'bolivia', 'chile', 'argentina', 'uruguay', 'paraguay', 'venezuela',
            'spain', 'españa', 'guatemala', 'honduras', 'el salvador', 'nicaragua', 'costa rica', 'panama', 'panamá', 'dominican', 'dominicana',
            'cuba', 'puerto rico', 'united kingdom', 'france', 'italy', 'germany', 'brazil', 'brasil', 'portugal'];
        foreach ($dmy as $name) {
            if (str_contains($c, $name)) {
                return self::DMY;
            }
        }

        return null;
    }

    /** Sets the company's date format from its country (when the country says which). */
    public static function applyCountry(int $companyId, ?string $country): void
    {
        if ($fmt = self::forCountry($country)) {
            DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->update(['date_format' => $fmt]);
        }
    }
}
