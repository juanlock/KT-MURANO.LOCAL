<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A move between warehouses. The `from` side is captured server-side from the
 * asset's current location rather than trusted from the client, so the chain
 * of moves always reads continuously.
 */
class AssetTransfer extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'asset_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_date',
        'reason',
        'notes',
        'created_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'transfer_date' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
