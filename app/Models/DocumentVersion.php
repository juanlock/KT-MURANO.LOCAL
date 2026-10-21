<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    // Multicompany (2026-10-21): only the acting company's rows.
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'document_versions';

    protected $fillable = [
        'document_id', 'version', 'file_name', 'file_path', 'mime_type',
        'extension', 'size', 'note', 'uploaded_by',
    ];

    protected $casts = [
        'document_id' => 'integer',
        'version' => 'integer',
        'size' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id', 'id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'id');
    }
}
