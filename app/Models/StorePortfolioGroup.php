<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePortfolioGroup extends Model
{
    protected $fillable = ['company_id', 'name', 'slug', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function items()
    {
        return $this->hasMany(StorePortfolioItem::class, 'group_id');
    }
}
