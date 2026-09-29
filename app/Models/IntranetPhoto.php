<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Intranet -> Gallery: one picture of an album (public/files/company_{id}/intranet/gallery). */
class IntranetPhoto extends Model
{
    protected $fillable = ['company_id', 'album_id', 'title', 'image', 'width', 'height', 'sort_order'];

    protected $casts = ['width' => 'integer', 'height' => 'integer', 'sort_order' => 'integer', 'album_id' => 'integer'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): string
    {
        return asset($this->image);
    }
}
