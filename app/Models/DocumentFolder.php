<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentFolder extends Model
{
    use SoftDeletes;

    protected $table = 'document_folders';

    protected $fillable = [
        'name', 'parent_id', 'description', 'color', 'created_by',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(DocumentFolder::class, 'parent_id', 'id');
    }

    public function children()
    {
        return $this->hasMany(DocumentFolder::class, 'parent_id', 'id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'folder_id', 'id');
    }

    /**
     * Every descendant id of the given folder, plus the folder itself — so
     * browsing a parent shows what is filed in its sub-folders too.
     */
    public static function descendantIds($folderId)
    {
        $ids = [(int) $folderId];
        $frontier = [(int) $folderId];

        // Iterative instead of recursive: folder trees here are shallow, and a
        // cycle from a bad parent_id would otherwise loop forever.
        $guard = 0;
        while ($frontier && $guard++ < 50) {
            $next = static::whereNull('deleted_at')
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => in_array($id, $ids, true))
                ->values()
                ->all();

            if (! $next) {
                break;
            }
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }

        return $ids;
    }
}
