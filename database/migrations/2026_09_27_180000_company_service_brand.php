<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brand "1" -> "Company service" (per company, multicompany-safe).
 *
 * For every live company:
 *  - If it already has a "Company service" brand, reuse it (idempotent).
 *  - Else, if it has a brand literally named "1":
 *      - no NON-service products use it -> rename it in place (keeps id);
 *      - otherwise -> create a new "Company service" brand and move only the
 *        service products off brand "1" onto it.
 *  - Else create a new "Company service" brand. It is created for every
 *    company (even with no services yet) so new service products always have
 *    a per-company default brand to fall back to (ProductsController::store).
 *  - Service products (type is_service, not deleted) with brand_id NULL or
 *    pointing to that company's "NoBrand" brand get the "Company service"
 *    brand.
 *
 * Every statement is filtered by company_id; no cross-company rows touched.
 */
return new class extends Migration
{
    private const NAME = 'Company service';

    public function up(): void
    {
        $now = now();
        $companyIds = DB::table('companies')->whereNull('deleted_at')->pluck('id');

        foreach ($companyIds as $companyId) {
            DB::transaction(function () use ($companyId, $now) {
                $brandId = DB::table('brands')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->where('name', self::NAME)
                    ->value('id');

                $path = 'existing';

                if (! $brandId) {
                    $oneId = DB::table('brands')
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->where('name', '1')
                        ->orderBy('id')
                        ->value('id');

                    if ($oneId) {
                        $nonService = DB::table('products')
                            ->where('company_id', $companyId)
                            ->where('brand_id', $oneId)
                            ->whereNull('deleted_at')
                            ->where('type', '!=', 'is_service')
                            ->count();

                        if ($nonService === 0) {
                            DB::table('brands')->where('id', $oneId)->where('company_id', $companyId)
                                ->update(['name' => self::NAME, 'updated_at' => $now]);
                            $brandId = $oneId;
                            $path = 'renamed brand "1" (id '.$oneId.')';
                        } else {
                            $brandId = $this->createBrand($companyId, $now);
                            $moved = DB::table('products')
                                ->where('company_id', $companyId)
                                ->where('brand_id', $oneId)
                                ->whereNull('deleted_at')
                                ->where('type', 'is_service')
                                ->update(['brand_id' => $brandId, 'updated_at' => $now]);
                            $path = 'created new (id '.$brandId.'), moved '.$moved.' services off brand "1" (id '.$oneId.'; '.$nonService.' non-service products keep it)';
                        }
                    } else {
                        $brandId = $this->createBrand($companyId, $now);
                        $path = 'created new (id '.$brandId.')';
                    }
                }

                $noBrandIds = DB::table('brands')
                    ->where('company_id', $companyId)
                    ->where('name', 'NoBrand')
                    ->pluck('id')
                    ->all();

                $assigned = DB::table('products')
                    ->where('company_id', $companyId)
                    ->where('type', 'is_service')
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($noBrandIds) {
                        $q->whereNull('brand_id');
                        if ($noBrandIds) {
                            $q->orWhereIn('brand_id', $noBrandIds);
                        }
                    })
                    ->update(['brand_id' => $brandId, 'updated_at' => $now]);

                echo "  company {$companyId}: {$path}; assigned {$assigned} service products (NULL/NoBrand)\n";
            });
        }
    }

    private function createBrand(int $companyId, $now): int
    {
        return (int) DB::table('brands')->insertGetId([
            'company_id' => $companyId,
            'name' => self::NAME,
            'description' => null,
            'image' => 'images/brands/no-image.png',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // No-op: data migration. The original brand name "1" and the previous
        // NULL/NoBrand assignments are not restored automatically.
    }
};
