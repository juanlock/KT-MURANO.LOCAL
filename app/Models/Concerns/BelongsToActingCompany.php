<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Multicompany for a model (2026-10-20, same rule as App\Models\Account): every
 * query only sees the acting company's rows -- an Admin their own company, a
 * SuperAdmin the company picked in the switcher (999 / none = all) -- and a new
 * row is stamped with the acting company. Outside an API request (console,
 * queue, migrations, demo generation) nothing is filtered.
 * Used by the Hospital Management models.
 */
trait BelongsToActingCompany
{
    protected static function bootBelongsToActingCompany(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            $cid = static::actingCompanyId($q->getModel()->getTable());
            if ($cid !== null) {
                $q->where($q->getModel()->getTable().'.company_id', $cid);
            }
        });
        static::creating(function ($model) {
            if (! $model->company_id && ($cid = static::actingCompanyId($model->getTable())) !== null) {
                $model->company_id = $cid;
            }
        });
    }

    /** The company to scope to, or null for "all" (SuperAdmin on 999 / no request user / no column yet). */
    public static function actingCompanyId(?string $table = null): ?int
    {
        static $hasColumn = [];
        $table ??= (new static)->getTable();
        $hasColumn[$table] ??= Schema::hasColumn($table, 'company_id');
        if (! $hasColumn[$table] || app()->runningInConsole()) {
            return null;
        }
        $user = request()->user('api');
        if (! $user) {
            return null;
        }
        if ($user->type === 'SuperAdmin') {
            $cid = (int) request()->input('company_id');

            return $cid && $cid !== 999 ? $cid : null;
        }

        return (int) ($user->company_id ?? 1);
    }
}
