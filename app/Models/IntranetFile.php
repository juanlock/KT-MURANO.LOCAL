<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Intranet -> Files: document library (per company). `path` is relative to
 * storage/app (private, outside public/) -- downloads are streamed by
 * IntranetViewApiController::download after the company + permission check.
 */
class IntranetFile extends Model
{
    protected $fillable = ['company_id', 'user_id', 'title', 'description', 'folder', 'original_name', 'path', 'mime', 'extension', 'size', 'downloads'];

    protected $hidden = ['path'];

    protected $casts = ['size' => 'integer', 'downloads' => 'integer'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'firstname', 'lastname', 'username']);
    }

    public function absolutePath(): string
    {
        return storage_path('app/'.$this->path);
    }
}
