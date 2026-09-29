<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreGalleryImage extends Model
{
    protected $fillable = ['company_id', 'title', 'image', 'width', 'height', 'sort_order', 'active', 'source', 'source_path'];

    protected $casts = ['active' => 'boolean', 'width' => 'integer', 'height' => 'integer', 'sort_order' => 'integer'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): string
    {
        return asset($this->image);
    }
}
