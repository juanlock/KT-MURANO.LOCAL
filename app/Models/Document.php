<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'folder_id', 'title', 'description', 'file_name', 'file_path',
        'mime_type', 'extension', 'size', 'tags', 'reference',
        'expiry_date', 'is_starred', 'version', 'uploaded_by',
    ];

    protected $casts = [
        'folder_id' => 'integer',
        'size' => 'integer',
        'is_starred' => 'boolean',
        'version' => 'integer',
        'uploaded_by' => 'integer',
        'expiry_date' => 'date',
    ];

    public function folder()
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id', 'id');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class, 'document_id', 'id')->orderBy('version', 'desc');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'id');
    }

    /** Tags are stored as a JSON array; always hand back a clean list. */
    public function getTagListAttribute()
    {
        $decoded = json_decode((string) $this->tags, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), 'strlen')) : [];
    }

    /**
     * Coarse family used for icons/filters — one of:
     * image, pdf, word, excel, powerpoint, archive, video, audio, text, other.
     */
    public function getKindAttribute()
    {
        return static::kindFor($this->extension, $this->mime_type);
    }

    public static function kindFor($extension, $mime = null)
    {
        $ext = strtolower((string) $extension);
        $mime = strtolower((string) $mime);

        $map = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic'],
            'pdf' => ['pdf'],
            'word' => ['doc', 'docx', 'odt', 'rtf'],
            'excel' => ['xls', 'xlsx', 'ods', 'csv'],
            'powerpoint' => ['ppt', 'pptx', 'odp'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            'video' => ['mp4', 'avi', 'mov', 'mkv', 'webm'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
            'text' => ['txt', 'md', 'log', 'json', 'xml'],
        ];

        foreach ($map as $kind => $extensions) {
            if (in_array($ext, $extensions, true)) {
                return $kind;
            }
        }

        // Fall back to the mime family when the name carried no useful suffix.
        foreach (['image', 'video', 'audio', 'text'] as $family) {
            if (strpos($mime, $family . '/') === 0) {
                return $family;
            }
        }

        return 'other';
    }
}
