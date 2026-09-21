<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleMaintenance extends Model
{
    use SoftDeletes;

    protected $table = 'vehicle_maintenances';

    protected $fillable = [
        'vehicle_id', 'type', 'title', 'service_date', 'odometer', 'cost',
        'vendor', 'status', 'next_service_date', 'next_service_odometer',
        'notes', 'created_by',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'odometer' => 'decimal:2',
        'cost' => 'decimal:2',
        'next_service_odometer' => 'decimal:2',
        'service_date' => 'date',
        'next_service_date' => 'date',
        'created_by' => 'integer',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
    }
}
