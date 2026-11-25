<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * The public storefront shows only its own company's catalogue (2026-11-25: /online_store/shop listed 651 products of
 * every company -- prices of Hordex, Ruba... -- because the storefront queries had no company filter). The store
 * middleware (EnsureStoreEnabled) and a company website's middleware (WebsiteCompany) bind 'store.company_id' for the
 * request; while it is bound, every query of the model is limited to that company. The admin panel, the API, the queue
 * and the console never bind it, so nothing changes there.
 * Used by Product, Category, SubCategory and Brand.
 */
trait ScopedToStoreCompany
{
    protected static function bootScopedToStoreCompany(): void
    {
        static::addGlobalScope('store_company', function (Builder $q) {
            if (app()->bound('store.company_id') && ($cid = (int) app('store.company_id'))) {
                $q->where($q->getModel()->getTable().'.company_id', $cid);
            }
        });
    }
}
