<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleFuelLog extends Model
{
    use SoftDeletes;

    protected $table = 'vehicle_fuel_logs';

    protected $fillable = [
        'vehicle_id', 'employee_id', 'log_date', 'odometer', 'quantity',
        'unit_price', 'total_cost', 'station', 'full_tank', 'notes', 'created_by',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'employee_id' => 'integer',
        'log_date' => 'date',
        'odometer' => 'decimal:2',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'full_tank' => 'boolean',
        'created_by' => 'integer',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
    }

    public function driver()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }
}
