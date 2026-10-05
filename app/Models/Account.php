<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Account extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id', 'account_num', 'account_name', 'initial_balance', 'balance', 'note', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'initial_balance' => 'double',
        'balance' => 'double',
    ];

    /**
     * Multicompany: every Account query (lists, dropdowns, findOrFail) only
     * sees the acting company's accounts -- an Admin their own company, a
     * SuperAdmin the company picked in the switcher (999 / none = all).
     * New accounts are stamped with the acting company.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            $cid = static::actingCompanyId();
            if ($cid !== null) {
                $q->where($q->getModel()->getTable().'.company_id', $cid);
            }
        });
        static::creating(function (Account $account) {
            if (! $account->company_id) {
                $account->company_id = static::actingCompanyId();
            }
        });
    }

    /** The company to scope to, or null for "all" (SuperAdmin on 999 / no request user). */
    public static function actingCompanyId(): ?int
    {
        static $hasColumn = null;
        $hasColumn ??= Schema::hasColumn('accounts', 'company_id');
        $user = $hasColumn ? request()->user('api') : null;
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
