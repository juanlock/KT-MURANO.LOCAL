<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * System Settings -> Defaults -> Product: auto-generated product Code,
 * <prefix><next number> zero-padded to <digits>, per company (e.g. PPI-042).
 *
 * "Next" = highest number already used with this prefix by this company's
 * products or variants (deleted ones included, so a code is never handed out
 * twice) + 1 -- derived from the data rather than a stored counter, so it
 * can't drift when products are added by import or typed in by hand.
 */
class ProductCode
{
    public static function enabled(int $companyId): bool
    {
        return (bool) (Setting::forCompany($companyId)->product_code_auto ?? true);
    }

    public static function next(int $companyId): string
    {
        $setting = Setting::forCompany($companyId);
        $prefix = (string) ($setting->product_code_prefix ?? '');
        $digits = max(1, min(12, (int) ($setting->product_code_digits ?? 4)));

        $productIds = DB::table('products')->where('company_id', $companyId)->pluck('id');
        $codes = DB::table('products')->where('company_id', $companyId)->where('code', 'like', self::like($prefix))->pluck('code')
            ->merge(DB::table('product_variants')->whereIn('product_id', $productIds)->where('code', 'like', self::like($prefix))->pluck('code'));

        $max = 0;
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';
        foreach ($codes as $code) {
            if (preg_match($pattern, (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix.str_pad((string) ($max + 1), $digits, '0', STR_PAD_LEFT);
    }

    private static function like(string $prefix): string
    {
        return addcslashes($prefix, '%_\\').'%';
    }
}
