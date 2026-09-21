<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestashopMapping extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_ORDER = 'order';

    protected $table = 'prestashop_mappings';

    protected $fillable = ['entity_type', 'local_id', 'prestashop_id', 'extra', 'synced_at'];

    protected $casts = [
        'local_id' => 'integer',
        'prestashop_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    /** Upsert a mapping, merging `extra` into any existing row. */
    public static function put(string $type, int $localId, int $prestashopId, ?array $extra = null): self
    {
        $mapping = static::firstOrNew(['entity_type' => $type, 'local_id' => $localId]);
        $mapping->prestashop_id = $prestashopId;
        if ($extra !== null) {
            $mapping->extra = array_merge($mapping->extra ?? [], $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    public static function prestashopId(string $type, int $localId): ?int
    {
        $value = static::where('entity_type', $type)->where('local_id', $localId)->value('prestashop_id');

        return $value !== null ? (int) $value : null;
    }

    public static function localId(string $type, int $prestashopId): ?int
    {
        $value = static::where('entity_type', $type)->where('prestashop_id', $prestashopId)->value('local_id');

        return $value !== null ? (int) $value : null;
    }
}
