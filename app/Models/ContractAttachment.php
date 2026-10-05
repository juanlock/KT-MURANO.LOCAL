<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractAttachment extends Model
{
    protected $fillable = ['contract_id', 'file_name', 'file_path'];

    protected $casts = ['contract_id' => 'integer'];

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id', 'id');
    }

    /**
     * The file on disk: new 'files/company_{id}/contracts/...' under public/
     * (CompanyFiles, private folder); legacy rows: the storage/app/public disk.
     */
    public function absolutePath(): string
    {
        $p = (string) $this->file_path;

        return str_starts_with($p, 'files/company_') && ! str_contains($p, '..')
            ? public_path($p)
            : \Illuminate\Support\Facades\Storage::disk('public')->path($p);
    }
}
