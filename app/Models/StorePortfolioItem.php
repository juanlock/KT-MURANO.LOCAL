<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePortfolioItem extends Model
{
    protected $fillable = ['company_id', 'group_id', 'title', 'subtitle', 'image', 'link', 'width', 'height', 'sort_order', 'active', 'source', 'source_path'];

    protected $casts = ['active' => 'boolean', 'group_id' => 'integer', 'width' => 'integer', 'height' => 'integer', 'sort_order' => 'integer'];

    protected $appends = ['image_url'];

    public function group()
    {
        return $this->belongsTo(StorePortfolioGroup::class, 'group_id');
    }

    public function getImageUrlAttribute(): string
    {
        return asset($this->image);
    }
}
