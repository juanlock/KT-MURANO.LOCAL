<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'mobile', 'country', 'city', 'email', 'zip',
    ];

    /**
     * Multicompany (2026-11-05): a signed-in API request only sees the acting company's warehouses, as Account does -- an
     * Admin their own, a SuperAdmin the company picked (999 / none = all). About 230 queries read this model and many had no
     * company filter, so forms and report totals reached other companies. Only routes behind auth:api: public pages (store,
     * booking, pay links) and console commands choose their company themselves. Relations to a warehouse drop this scope:
     * a row already in scope may point elsewhere (old product_warehouse rows do), and must not come back null.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            $cid = static::actingCompanyId();
            if ($cid !== null) {
                $q->where($q->getModel()->getTable().'.company_id', $cid);
            }
        });
    }

    /** The company to scope to, or null for no restriction. */
    public static function actingCompanyId(): ?int
    {
        $route = request()->route();
        if (! $route || ! in_array('auth:api', $route->gatherMiddleware(), true)) {
            return null;
        }
        $user = request()->user('api');
        if (! $user) {
            return null;
        }
        if ($user->type === 'SuperAdmin') {
            $cid = (int) request()->input('company_id');

            return $cid && $cid !== Setting::TEMPLATE_COMPANY_ID ? $cid : null;
        }

        return (int) ($user->company_id ?? 1);
    }

    public function assignedUsers()
    {
        return $this->belongsToMany('App\Models\User');
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }
}
