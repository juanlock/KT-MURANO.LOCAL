<?php

namespace App\Services;

use App\Models\ProductFitment;
use App\Models\Setting;
use App\Models\VehicleMake;
use App\Models\VehicleModel;

/**
 * Vehicle Fitment — single source of truth for compatibility rules.
 *
 * Rules:
 *  - A product with NO fitment rows is universal and fits every vehicle.
 *  - A fitment row matches a vehicle {make_id, model_id, year?, engine?} when
 *    the make matches, the model matches (or the row covers all models),
 *    the year falls inside the row's optional [year_from, year_to] bounds,
 *    and the engine matches when BOTH sides specify one (lenient otherwise).
 *  - No vehicle selected = no filtering, everything is purchasable.
 */
class FitmentService
{
    public const SESSION_KEY = 'store_vehicle';

    /** Master switch: System Settings → Features. */
    public function enabled(): bool
    {
        return (bool) (Setting::query()->value('vehicle_fitment_enabled') ?? false);
    }

    /**
     * Normalize + validate a raw vehicle payload against the catalog.
     * Returns null when the payload doesn't resolve to a real make/model.
     *
     * @return array{make_id:int, model_id:int, year:?int, engine:?string, label:string}|null
     */
    public function normalizeVehicle($raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $makeId = (int) ($raw['make_id'] ?? 0);
        $modelId = (int) ($raw['model_id'] ?? 0);
        if (! $makeId || ! $modelId) {
            return null;
        }

        $make = VehicleMake::where('active', true)->find($makeId);
        $model = VehicleModel::where('active', true)
            ->where('vehicle_make_id', $makeId)
            ->find($modelId);
        if (! $make || ! $model) {
            return null;
        }

        $year = (int) ($raw['year'] ?? 0);
        $year = ($year >= 1900 && $year <= 2100) ? $year : null;
        $engine = trim((string) ($raw['engine'] ?? ''));
        $engine = $engine !== '' ? mb_substr($engine, 0, 100) : null;

        return [
            'make_id' => (int) $make->id,
            'model_id' => (int) $model->id,
            'year' => $year,
            'engine' => $engine,
            'label' => trim(implode(' ', array_filter([$year, $make->name, $model->name, $engine]))),
        ];
    }

    /** The storefront's currently selected vehicle (session), if any. */
    public function currentVehicle(): ?array
    {
        $v = session(self::SESSION_KEY);

        return is_array($v) && ! empty($v['make_id']) && ! empty($v['model_id']) ? $v : null;
    }

    /**
     * Constrain a product query (base table `products`) to items compatible
     * with the vehicle: universal products OR products with a matching row.
     */
    public function scopeCompatible($query, array $vehicle)
    {
        return $query->where(function ($q) use ($vehicle) {
            $q->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('product_fitments')
                    ->whereColumn('product_fitments.product_id', 'products.id');
            })->orWhereExists(function ($sub) use ($vehicle) {
                $this->applyMatch($sub, $vehicle);
            });
        });
    }

    /** EXISTS subquery body shared by scopeCompatible / compatibleProductIds. */
    private function applyMatch($sub, array $vehicle, bool $column = true): void
    {
        $sub->selectRaw('1')->from('product_fitments');
        if ($column) {
            $sub->whereColumn('product_fitments.product_id', 'products.id');
        }
        $sub->where('product_fitments.vehicle_make_id', (int) $vehicle['make_id'])
            ->where(function ($q) use ($vehicle) {
                $q->whereNull('product_fitments.vehicle_model_id')
                    ->orWhere('product_fitments.vehicle_model_id', (int) $vehicle['model_id']);
            });

        if (! empty($vehicle['year'])) {
            $year = (int) $vehicle['year'];
            $sub->where(function ($q) use ($year) {
                $q->whereNull('product_fitments.year_from')->orWhere('product_fitments.year_from', '<=', $year);
            })->where(function ($q) use ($year) {
                $q->whereNull('product_fitments.year_to')->orWhere('product_fitments.year_to', '>=', $year);
            });
        }

        if (! empty($vehicle['engine'])) {
            $engine = mb_strtolower((string) $vehicle['engine']);
            $sub->where(function ($q) use ($engine) {
                $q->whereNull('product_fitments.engine')
                    ->orWhereRaw('LOWER(product_fitments.engine) = ?', [$engine]);
            });
        }
    }

    /** Whether one product fits the vehicle (universal counts as fitting). */
    public function productFits(int $productId, array $vehicle): bool
    {
        $rows = ProductFitment::where('product_id', $productId)
            ->get(['vehicle_make_id', 'vehicle_model_id', 'year_from', 'year_to', 'engine']);

        if ($rows->isEmpty()) {
            return true; // universal part
        }

        return $rows->contains(fn ($r) => $this->rowMatches($r, $vehicle));
    }

    /** Whether the product has any fitment data at all (false = universal). */
    public function productHasFitments(int $productId): bool
    {
        return ProductFitment::where('product_id', $productId)->exists();
    }

    private function rowMatches($row, array $vehicle): bool
    {
        if ((int) $row->vehicle_make_id !== (int) $vehicle['make_id']) {
            return false;
        }
        if ($row->vehicle_model_id !== null && (int) $row->vehicle_model_id !== (int) $vehicle['model_id']) {
            return false;
        }
        $year = (int) ($vehicle['year'] ?? 0);
        if ($year) {
            if ($row->year_from !== null && $year < (int) $row->year_from) {
                return false;
            }
            if ($row->year_to !== null && $year > (int) $row->year_to) {
                return false;
            }
        }
        $engine = trim((string) ($vehicle['engine'] ?? ''));
        if ($engine !== '' && $row->engine !== null && $row->engine !== '') {
            if (mb_strtolower($row->engine) !== mb_strtolower($engine)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter cart-style items [{product_id, ...}] down to the ones that do
     * NOT fit the vehicle. Each returned entry carries the product name so
     * callers can render a useful error.
     *
     * @return array<int,array{product_id:int, name:string}>
     */
    public function incompatibleItems(array $items, array $vehicle): array
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $withFitments = ProductFitment::whereIn('product_id', $ids)
            ->get(['product_id', 'vehicle_make_id', 'vehicle_model_id', 'year_from', 'year_to', 'engine'])
            ->groupBy('product_id');

        $bad = [];
        foreach ($ids as $pid) {
            $rows = $withFitments->get($pid);
            if (! $rows || $rows->isEmpty()) {
                continue; // universal
            }
            if (! $rows->contains(fn ($r) => $this->rowMatches($r, $vehicle))) {
                $bad[] = $pid;
            }
        }

        if (! $bad) {
            return [];
        }

        $names = \App\Models\Product::whereIn('id', $bad)->pluck('name', 'id');

        return array_map(fn ($pid) => [
            'product_id' => $pid,
            'name' => (string) ($names[$pid] ?? ('#'.$pid)),
        ], $bad);
    }

    /**
     * Product ids (from the given set) compatible with the vehicle.
     * Used by the POS, which filters its preloaded grid client-side.
     *
     * @return int[]
     */
    public function compatibleIdsAmong(array $productIds, array $vehicle): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (! $productIds) {
            return [];
        }

        $withFitments = ProductFitment::whereIn('product_id', $productIds)
            ->get(['product_id', 'vehicle_make_id', 'vehicle_model_id', 'year_from', 'year_to', 'engine'])
            ->groupBy('product_id');

        return array_values(array_filter($productIds, function ($pid) use ($withFitments, $vehicle) {
            $rows = $withFitments->get($pid);

            return ! $rows || $rows->isEmpty() || $rows->contains(fn ($r) => $this->rowMatches($r, $vehicle));
        }));
    }
}
