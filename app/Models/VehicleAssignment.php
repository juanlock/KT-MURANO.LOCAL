<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'vehicle_assignments';

    protected $fillable = [
        'vehicle_id', 'employee_id', 'start_date', 'end_date', 'start_odometer',
        'end_odometer', 'purpose', 'destination', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'employee_id' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'start_odometer' => 'decimal:2',
        'end_odometer' => 'decimal:2',
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

    /** Kilometres covered, or null while the trip is still open. */
    public function getDistanceAttribute()
    {
        if ($this->end_odometer === null || $this->start_odometer === null) {
            return null;
        }

        return max(0, (float) $this->end_odometer - (float) $this->start_odometer);
    }
}
