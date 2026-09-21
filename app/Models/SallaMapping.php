<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SallaMapping extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_VARIANT = 'variant';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_ORDER = 'order';

    protected $table = 'salla_mappings';

    protected $fillable = ['entity_type', 'local_id', 'salla_id', 'extra', 'synced_at'];

    protected $casts = [
        'local_id' => 'integer',
        'salla_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    /** Upsert a mapping, merging `extra` into any existing row. */
    public static function put(string $type, int $localId, int $sallaId, ?array $extra = null): self
    {
        $mapping = static::firstOrNew(['entity_type' => $type, 'local_id' => $localId]);
        $mapping->salla_id = $sallaId;
        if ($extra !== null) {
            $mapping->extra = array_merge($mapping->extra ?? [], $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    public static function sallaId(string $type, int $localId): ?int
    {
        $value = static::where('entity_type', $type)->where('local_id', $localId)->value('salla_id');

        return $value !== null ? (int) $value : null;
    }

    public static function localId(string $type, int $sallaId): ?int
    {
        $value = static::where('entity_type', $type)->where('salla_id', $sallaId)->value('local_id');

        return $value !== null ? (int) $value : null;
    }
}
