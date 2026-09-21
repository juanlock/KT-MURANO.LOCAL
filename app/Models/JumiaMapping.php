<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JumiaMapping extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_ORDER = 'order';

    protected $table = 'jumia_mappings';

    protected $fillable = ['entity_type', 'local_id', 'jumia_id', 'extra', 'synced_at'];

    protected $casts = [
        'local_id' => 'integer',
        'jumia_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    /** Upsert a mapping, merging `extra` into any existing row. */
    public static function put(string $type, int $localId, int $jumiaId, ?array $extra = null): self
    {
        $mapping = static::firstOrNew(['entity_type' => $type, 'local_id' => $localId]);
        $mapping->jumia_id = $jumiaId;
        if ($extra !== null) {
            $mapping->extra = array_merge($mapping->extra ?? [], $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    public static function jumiaId(string $type, int $localId): ?int
    {
        $value = static::where('entity_type', $type)->where('local_id', $localId)->value('jumia_id');

        return $value !== null ? (int) $value : null;
    }

    public static function localId(string $type, int $jumiaId): ?int
    {
        $value = static::where('entity_type', $type)->where('jumia_id', $jumiaId)->value('local_id');

        return $value !== null ? (int) $value : null;
    }
}
