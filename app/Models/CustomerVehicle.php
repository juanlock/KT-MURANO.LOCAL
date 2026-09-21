<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerVehicle extends Model
{
    protected $fillable = [
        'client_id', 'vehicle_make_id', 'vehicle_model_id',
        'year', 'engine', 'nickname', 'is_default',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'vehicle_make_id' => 'integer',
        'vehicle_model_id' => 'integer',
        'year' => 'integer',
        'is_default' => 'boolean',
    ];

    public function make()
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function model()
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** Display label, e.g. "2018 Toyota Corolla 1.8L". */
    public function label(): string
    {
        $parts = array_filter([
            $this->year ?: null,
            $this->make?->name,
            $this->model?->name,
            $this->engine ?: null,
        ]);

        return trim(implode(' ', $parts)) ?: ($this->nickname ?: '#'.$this->id);
    }
}
