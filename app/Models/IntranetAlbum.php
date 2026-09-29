<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Intranet -> Gallery: a photo album (per company). */
class IntranetAlbum extends Model
{
    protected $fillable = ['company_id', 'title', 'description', 'album_date', 'cover_photo_id', 'published', 'sort_order'];

    protected $casts = ['published' => 'boolean', 'album_date' => 'date:Y-m-d', 'sort_order' => 'integer', 'cover_photo_id' => 'integer'];

    public function photos()
    {
        return $this->hasMany(IntranetPhoto::class, 'album_id')->orderBy('sort_order')->orderBy('id');
    }

    public function cover()
    {
        return $this->belongsTo(IntranetPhoto::class, 'cover_photo_id');
    }
}
