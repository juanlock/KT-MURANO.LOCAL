<?php

namespace App\Http\Controllers\Concerns;

use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Illuminate\Http\Request;

/**
 * Multicompany scope for REPORTS (CLAUDE.md "multicompany" rule):
 *  - an Admin / employee is always locked to their own company_id;
 *  - a SuperAdmin sees the company picked in the header switcher (the SPA
 *    sends company_id on every request), or ALL companies when none is picked.
 *
 * Almost every report number is keyed by warehouse (sales, purchases,
 * returns, quotations, stock, transfers, adjustments, expenses...), and the
 * reports already restrict "not all warehouses" users to their assigned
 * warehouses. The helpers below make that warehouse list company-aware:
 *  - reportAllWarehouses(): true ONLY for a SuperAdmin with no company picked
 *    (the one case with no restriction at all);
 *  - reportWarehouseIdList(): the acting company's warehouses (intersected
 *    with the user's assigned ones when the user is restricted).
 * warehouseScopeIds() / canAccessWarehouse() (ScopesWarehouseAccess) are
 * overridden with the same rule, so helper-based reports follow it too.
 * Records WITHOUT a warehouse (customers, suppliers, products, users,
 * brands, categories) use reportCompanyId() explicitly.
 */
trait ScopesReportsToCompany
{
    use ScopesToCompany;

    private ?array $reportWarehouseMemo = null;

    /** Company to report on; null = SuperAdmin with no company picked (all). */
    protected function reportCompanyId(?Request $request = null): ?int
    {
        $request ??= request();
        $user = $request->user('api') ?? auth()->user();
        // superadmin/AdminSystem (2026-10-21): no company or AdminSystem (999) picked = every company.
        if ($user && $user->type === 'SuperAdmin'
            && (! $request->filled('company_id') || (int) $request->input('company_id') === \App\Models\Setting::TEMPLATE_COMPANY_ID)) {
            return null;
        }

        return $this->resolveActingCompanyId($request);
    }

    /** ->where("$column", company) when a company applies. */
    protected function scopeReportCompany($query, ?int $companyId, string $column)
    {
        return $companyId ? $query->where($column, $companyId) : $query;
    }

    /** Short form used across the reports: scope $query's $column to the report company. */
    protected function rc($query, string $column)
    {
        return $this->scopeReportCompany($query, $this->reportCompanyId(), $column);
    }

    /** No warehouse restriction at all: only a SuperAdmin with no company picked. */
    protected function reportAllWarehouses(): bool
    {
        return $this->reportCompanyId() === null;
    }

    /** Warehouse ids the report may use: the company's (and the user's assigned ones if restricted). */
    protected function reportWarehouseIdList(): array
    {
        if ($this->reportWarehouseMemo !== null) {
            return $this->reportWarehouseMemo;
        }
        $user = auth()->user();
        $companyId = $this->reportCompanyId();
        $q = Warehouse::whereNull('deleted_at')->when($companyId, fn ($w) => $w->where('company_id', $companyId));
        if ($user && $user->type !== 'SuperAdmin' && ! $user->is_all_warehouses) {
            $q->whereIn('id', UserWarehouse::where('user_id', $user->id)->select('warehouse_id'));
        }

        return $this->reportWarehouseMemo = $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** ScopesWarehouseAccess override: null = unrestricted, else the report's warehouse list. */
    protected function warehouseScopeIds(): ?array
    {
        return $this->reportAllWarehouses() ? null : $this->reportWarehouseIdList();
    }

    /** ScopesWarehouseAccess override: a warehouse filter must be one of the report's warehouses. */
    protected function canAccessWarehouse($warehouseId): bool
    {
        if (! $warehouseId) {
            return false;
        }
        if ($this->reportAllWarehouses()) {
            return Warehouse::whereKey((int) $warehouseId)->whereNull('deleted_at')->exists();
        }

        return in_array((int) $warehouseId, $this->reportWarehouseIdList(), true);
    }
}
