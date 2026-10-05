<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Intranet -> Files: document library (per company). `path`: new files
 * 'files/company_{id}/intranet/files/...' under public/ (project standard,
 * CompanyFiles, private folder: no direct URL); older rows are relative to
 * storage/app. Downloads are streamed by IntranetViewApiController::download
 * after the company + permission check.
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
        return str_starts_with((string) $this->path, 'files/company_') ? public_path($this->path) : storage_path('app/'.$this->path);
    }

    /** The file is this company's (new or legacy folder), without ../ tricks. */
    public function ownedBy(int $companyId): bool
    {
        $p = (string) $this->path;

        return ! str_contains($p, '..') && (str_starts_with($p, 'files/company_'.$companyId.'/intranet/files/')
            || str_starts_with($p, 'intranet/company_'.$companyId.'/files/'));
    }
}
