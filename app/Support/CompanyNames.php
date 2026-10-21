<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Setting;

/**
 * superadmin/AdminSystem: every company (2026-10-21) -- a list that can hold
 * several companies' records says whose each row is (`company_name`, read
 * from the row's `company_id`; 999 = AdminSystem). One query for the page.
 */
class CompanyNames
{
    /** id => name for the given company ids (999 -> 'AdminSystem'). */
    public static function map(iterable $companyIds): array
    {
        $ids = collect($companyIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $names = $ids->isEmpty() ? [] : Company::whereIn('id', $ids)->pluck('name', 'id')->all();
        $names[Setting::TEMPLATE_COMPANY_ID] = 'AdminSystem';

        return $names;
    }

    /**
     * Adds company_name to each row (arrays or objects with company_id) and
     * returns the rows (same type: array or Collection).
     */
    public static function attach($rows, string $column = 'company_id')
    {
        $get = fn ($r) => is_array($r) ? ($r[$column] ?? null) : ($r->{$column} ?? null);
        $names = self::map(collect($rows)->map($get));
        $set = function ($r) use ($get, $names) {
            $name = $names[(int) $get($r)] ?? null;
            if (is_array($r)) {
                $r['company_name'] = $name;
            } else {
                $r->company_name = $name;
            }

            return $r;
        };

        return is_array($rows) ? array_map($set, $rows) : collect($rows)->map($set);
    }
}
