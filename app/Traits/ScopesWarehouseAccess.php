<?php

namespace App\Traits;

use App\Models\UserWarehouse;
use App\Models\Warehouse;

/**
 * Warehouse-level row scoping for tenant users.
 *
 * A user with `is_all_warehouses = 0` may only see and touch data belonging to
 * the warehouses assigned to them in `user_warehouse`. Controllers used to
 * re-derive that list inline (and several only applied it to the warehouse
 * dropdown, leaving the data query wide open), so the rules live here instead.
 *
 * Two shapes are offered because both are already in use across the codebase:
 *  - userWarehouseIds() always returns a concrete id list;
 *  - warehouseScopeIds() returns null for unrestricted users, so a query can
 *    skip the whereIn entirely.
 */
trait ScopesWarehouseAccess
{
    /** Per-request memo — the assignment cannot change mid request. */
    private ?array $warehouseAccessMemo = null;

    /**
     * Warehouse ids the authenticated user may access. Deleted warehouses are
     * never included. An unauthenticated request gets an empty list.
     */
    protected function userWarehouseIds(): array
    {
        if ($this->warehouseAccessMemo !== null) {
            return $this->warehouseAccessMemo;
        }

        $user = auth()->user();

        if (! $user) {
            return $this->warehouseAccessMemo = [];
        }

        if ($user->is_all_warehouses) {
            return $this->warehouseAccessMemo = Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->warehouseAccessMemo = UserWarehouse::where('user_id', $user->id)
            ->whereIn('warehouse_id', Warehouse::whereNull('deleted_at')->select('id'))
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Same list, but null when the user is unrestricted — lets a query do
     * ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids)).
     */
    protected function warehouseScopeIds(): ?array
    {
        $user = auth()->user();

        if ($user && $user->is_all_warehouses) {
            return null;
        }

        return $this->userWarehouseIds();
    }

    /**
     * Apply the warehouse scope to a query column.
     *
     * $includeGlobal keeps rows whose warehouse column is NULL — used by
     * records that may be defined once for the whole company (a BOM or work
     * center with no warehouse belongs to everyone, not to nobody).
     */
    protected function scopeToWarehouses($query, string $column, bool $includeGlobal = false)
    {
        $ids = $this->warehouseScopeIds();

        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $column, $includeGlobal) {
            $q->whereIn($column, $ids);
            if ($includeGlobal) {
                $q->orWhereNull($column);
            }
        });
    }

    /**
     * Scope a query on a record that spans two warehouses (a transfer): the
     * caller sees it when either end is theirs. A NULL end never matches on
     * its own, but does not hide a row whose other end is in scope.
     */
    protected function scopeToWarehousesEitherEnd($query, string $fromColumn, string $toColumn)
    {
        $ids = $this->warehouseScopeIds();

        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $fromColumn, $toColumn) {
            $q->whereIn($fromColumn, $ids)->orWhereIn($toColumn, $ids);
        });
    }

    /** Whether the user may access the given warehouse. */
    protected function canAccessWarehouse($warehouseId): bool
    {
        if (! $warehouseId) {
            return false;
        }

        return in_array((int) $warehouseId, $this->userWarehouseIds(), true);
    }

    /**
     * Normalise a client-supplied warehouse filter: returns the id when the
     * user may see it, null otherwise. A rejected filter falls back to the
     * user's full allowed scope rather than leaking another warehouse.
     */
    protected function filterWarehouseId($warehouseId): ?int
    {
        $warehouseId = (int) $warehouseId;

        return $warehouseId && $this->canAccessWarehouse($warehouseId) ? $warehouseId : null;
    }

    /**
     * Warehouse gate for a document opened by id (sale, purchase, quotation,
     * return, transfer, adjustment, damage, expense...).
     *
     * This is the other half of the `check_record` ownership test: record_view
     * decides WHOSE documents a user may open, this decides WHICH warehouses
     * they may come from, and both apply. Documents that carry no warehouse
     * column are left alone.
     */
    protected function abortIfDocumentWarehouseDenied($document): void
    {
        if (! $document) {
            return;
        }

        if (isset($document->from_warehouse_id) || isset($document->to_warehouse_id)) {
            $this->abortIfTransferDenied($document->from_warehouse_id ?? null, $document->to_warehouse_id ?? null);

            return;
        }

        if (isset($document->warehouse_id)) {
            $this->abortIfWarehouseDenied($document->warehouse_id);

            return;
        }

        // Payments carry no warehouse of their own — they inherit the one on
        // the document they settle.
        foreach (self::PARENT_DOCUMENT_KEYS as $foreignKey => $class) {
            if (empty($document->{$foreignKey}) || ! class_exists($class)) {
                continue;
            }

            $parent = $class::find($document->{$foreignKey});
            if ($parent && isset($parent->warehouse_id)) {
                $this->abortIfWarehouseDenied($parent->warehouse_id);
            }

            return;
        }
    }

    /** Documents a payment can belong to, by foreign key. */
    private const PARENT_DOCUMENT_KEYS = [
        'sale_id' => \App\Models\Sale::class,
        'purchase_id' => \App\Models\Purchase::class,
        'sale_return_id' => \App\Models\SaleReturn::class,
        'purchase_return_id' => \App\Models\PurchaseReturn::class,
        'quotation_id' => \App\Models\Quotation::class,
    ];

    /**
     * A transfer straddles two warehouses; being assigned to either end is
     * enough to see it, which matches how the transfer lists are scoped.
     */
    protected function abortIfTransferDenied($fromWarehouseId, $toWarehouseId): void
    {
        if (! $this->canAccessWarehouse($fromWarehouseId) && ! $this->canAccessWarehouse($toWarehouseId)) {
            abort(403, 'You are not assigned to either warehouse of this transfer.');
        }
    }

    /**
     * Hard stop for endpoints addressed by warehouse (a route segment or a
     * required parameter) where silently widening the scope would be wrong.
     */
    protected function abortIfWarehouseDenied($warehouseId): void
    {
        if (! $this->canAccessWarehouse($warehouseId)) {
            abort(403, 'You are not assigned to this warehouse.');
        }
    }
}
