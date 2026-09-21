<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XeroMapping extends Model
{
    public const TYPE_CONTACT = 'contact';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_PAYMENT = 'payment';

    protected $table = 'xero_mappings';

    protected $fillable = ['entity_type', 'local_id', 'xero_id', 'extra', 'synced_at'];

    protected $casts = [
        'local_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    /** Upsert a mapping, merging `extra` into any existing row. */
    public static function put(string $type, int $localId, string $xeroId, ?array $extra = null): self
    {
        $mapping = static::firstOrNew(['entity_type' => $type, 'local_id' => $localId]);
        $mapping->xero_id = $xeroId;
        if ($extra !== null) {
            $mapping->extra = array_merge($mapping->extra ?? [], $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    public static function xeroId(string $type, int $localId): ?string
    {
        $value = static::where('entity_type', $type)->where('local_id', $localId)->value('xero_id');

        return $value !== null ? (string) $value : null;
    }

    public static function localId(string $type, string $xeroId): ?int
    {
        $value = static::where('entity_type', $type)->where('xero_id', $xeroId)->value('local_id');

        return $value !== null ? (int) $value : null;
    }
}
