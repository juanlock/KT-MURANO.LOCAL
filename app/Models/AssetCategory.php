<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    use HasFactory;

    protected $table = 'asset_categories';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function assets()
    {
        return $this->hasMany(Asset::class, 'asset_category_id');
    }

    /**
     * The company a request works in: an Admin's own; a SuperAdmin's picked
     * company (header switcher, company_id) -- null = all companies.
     */
    public static function actingCompanyId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->type === 'SuperAdmin') {
            $cid = (int) request()->input('company_id');

            return $cid && $cid !== 999 ? $cid : null;
        }

        return $user ? (int) $user->company_id : null;
    }

    /** Multicompany: this company's categories (+ any old shared ones without a company). */
    public function scopeForCompany($query, ?int $companyId = null)
    {
        return self::constrain($query, $companyId);
    }

    /** Same condition on any query over asset_categories (e.g. an exists rule). */
    public static function constrain($query, ?int $companyId = null)
    {
        $companyId ??= self::actingCompanyId();

        return $companyId === null && optional(auth()->user())->type === 'SuperAdmin'
            ? $query
            : $query->where(fn ($q) => $q->where('asset_categories.company_id', $companyId)->orWhereNull('asset_categories.company_id'));
    }
}





