<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $table = 'vehicles';

    protected $fillable = [
        'name', 'plate_number', 'vin', 'make', 'model', 'year', 'color',
        'type', 'status', 'warehouse_id', 'employee_id', 'fuel_type',
        'tank_capacity', 'odometer', 'purchase_date', 'purchase_price',
        'insurance_provider', 'insurance_policy', 'insurance_expiry',
        'registration_expiry', 'inspection_expiry', 'image', 'notes',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'employee_id' => 'integer',
        'year' => 'integer',
        'tank_capacity' => 'decimal:2',
        'odometer' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'purchase_date' => 'date',
        'insurance_expiry' => 'date',
        'registration_expiry' => 'date',
        'inspection_expiry' => 'date',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function driver()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function maintenances()
    {
        return $this->hasMany(VehicleMaintenance::class, 'vehicle_id', 'id');
    }

    public function fuelLogs()
    {
        return $this->hasMany(VehicleFuelLog::class, 'vehicle_id', 'id');
    }

    public function assignments()
    {
        return $this->hasMany(VehicleAssignment::class, 'vehicle_id', 'id');
    }

    /** The assignment currently holding the vehicle, if any. */
    public function activeAssignment()
    {
        return $this->hasOne(VehicleAssignment::class, 'vehicle_id', 'id')
            ->where('status', 'active')
            ->latest('start_date');
    }

    public function getDisplayNameAttribute()
    {
        return trim($this->name . ' (' . $this->plate_number . ')');
    }

    /**
     * Distance covered according to the fuel log: last reading minus first.
     * Odometer readings at the pump are the only continuous record a fleet
     * reliably keeps, so both the detail page and the reports measure from
     * them rather than from trip entries (which are often left open).
     */
    public static function distanceCovered($vehicleId)
    {
        $range = VehicleFuelLog::whereNull('deleted_at')
            ->where('vehicle_id', $vehicleId)
            ->where('odometer', '>', 0)
            ->selectRaw('MIN(odometer) as first_reading, MAX(odometer) as last_reading')
            ->first();

        if (! $range || $range->first_reading === null) {
            return 0.0;
        }

        return max(0.0, (float) $range->last_reading - (float) $range->first_reading);
    }

    /**
     * Distance per fuel unit (km/L and the like). The FIRST fill is excluded:
     * it paid for the distance driven BEFORE the log started, so counting it
     * understates efficiency — this is the standard tank-to-tank method.
     * Null until there are two readings to measure between.
     */
    public static function fuelEfficiency($vehicleId)
    {
        $logs = VehicleFuelLog::whereNull('deleted_at')
            ->where('vehicle_id', $vehicleId)
            ->orderBy('odometer')
            ->get(['odometer', 'quantity']);

        if ($logs->count() < 2) {
            return null;
        }

        $distance = (float) $logs->last()->odometer - (float) $logs->first()->odometer;
        $litres = (float) $logs->slice(1)->sum('quantity');

        if ($distance <= 0 || $litres <= 0) {
            return null;
        }

        return round($distance / $litres, 2);
    }
}
