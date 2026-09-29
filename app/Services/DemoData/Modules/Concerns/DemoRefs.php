<?php

namespace App\Services\DemoData\Modules\Concerns;

use App\Services\DemoData\DemoContext;
use Illuminate\Support\Facades\DB;

/**
 * Shared lookups for add-on demo modules that need customers / products /
 * sales to hang their examples on. Preference order: the company's demo rows
 * (CoreModule), then the company's live rows (read-only references), and only
 * if the company has none at all, a minimal demo row created here.
 */
trait DemoRefs
{
    /** @return int[] up to $limit client ids for this company */
    protected function clientIds(DemoContext $ctx, int $limit = 6): array
    {
        $ids = $this->liveIds('clients', $ctx->demoIds('clients'), $ctx, $limit);
        if (! $ids) {
            $ids = $this->liveIds('clients', null, $ctx, $limit);
        }
        if (! $ids) {
            for ($i = 0; $i < 3; $i++) {
                $ids[] = $this->createDemoClient($ctx);
            }
        }

        return $ids;
    }

    /** @return int[] up to $limit product ids for this company */
    protected function productIds(DemoContext $ctx, int $limit = 6, ?string $type = null): array
    {
        $ids = $this->liveIds('products', $ctx->demoIds('products'), $ctx, $limit, $type);
        if (! $ids) {
            $ids = $this->liveIds('products', null, $ctx, $limit, $type);
        }
        if (! $ids && $type !== null) {
            return $this->productIds($ctx, $limit);
        }
        if (! $ids) {
            foreach (['Standard Service Package', 'Premium Support Plan', 'Basic Maintenance'] as $name) {
                $ids[] = $this->createDemoProduct($ctx, $name);
            }
        }

        return $ids;
    }

    /** @return int[] sale ids for this company (demo first, then live); never creates. */
    protected function saleIds(DemoContext $ctx, int $limit = 6): array
    {
        $ids = $this->liveIds('sales', $ctx->demoIds('sales'), $ctx, $limit);

        return $ids ?: $this->liveIds('sales', null, $ctx, $limit);
    }

    /** Non-deleted ids of this company in $table (optionally limited to $only). */
    private function liveIds(string $table, ?array $only, DemoContext $ctx, int $limit, ?string $type = null): array
    {
        if ($only !== null && ! $only) {
            return [];
        }
        $q = DB::table($table)->where('company_id', $ctx->companyId);
        if ($ctx->hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($only !== null) {
            $q->whereIn('id', $only);
        }
        if ($type !== null) {
            $q->where('type', $type);
        }

        return $q->orderBy('id')->limit($limit)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    protected function createDemoClient(DemoContext $ctx): int
    {
        [$first, $last] = $ctx->personName();
        $code = (int) DB::table('clients')->max('code') + 1;

        return $ctx->insert('clients', [
            'name' => "$first $last",
            'firstname' => $first,
            'lastname' => $last,
            'code' => $code,
            'email' => strtolower($first.'.'.$last).'.'.random_int(1000, 9999).'@example.com',
            'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
            'city' => $ctx->pick(['Springfield', 'Riverside', 'Fairview', 'Madison']),
            'country' => 'United States',
        ]);
    }

    protected function createDemoProduct(DemoContext $ctx, string $name): int
    {
        $categoryIds = $ctx->demoIds('categories');
        $categoryId = $categoryIds ? $categoryIds[0] : (int) DB::table('categories')
            ->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('id');
        if (! $categoryId) {
            $categoryId = $ctx->insert('categories', ['code' => 'SRV'.random_int(100, 999), 'name' => 'Services']);
        }
        $price = random_int(40, 250);

        return $ctx->insert('products', [
            'type' => 'is_service',
            'code' => (string) random_int(10000000, 99999999),
            'Type_barcode' => 'CODE128',
            'name' => $name,
            'cost' => 0,
            'price' => $price,
            'wholesale_price' => $price,
            'min_price' => 0,
            'category_id' => $categoryId,
            'TaxNet' => 0,
            'tax_method' => '1',
            'discount_method' => '1',
            'image' => 'no-image.png',
            'is_active' => 1,
        ]);
    }

    /** Next "PREFIX_n" reference, continuing the controllers' own sequence. */
    protected function nextRef(string $table, string $prefix, int $start = 1111): int
    {
        $last = DB::table($table)->orderByDesc('id')->value('Ref');
        if ($last) {
            $parts = explode('_', (string) $last);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                return (int) $parts[1] + 1;
            }
        }

        return $start;
    }

    /** A value for a globally-unique column not already taken. */
    protected function uniqueValue(string $table, string $column, string $value): string
    {
        $candidate = $value;
        while (DB::table($table)->where($column, $candidate)->exists()) {
            $candidate = $value.'-'.random_int(100, 999);
        }

        return $candidate;
    }
}
