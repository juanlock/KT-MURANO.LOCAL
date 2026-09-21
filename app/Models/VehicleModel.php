<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleModel extends Model
{
    protected $fillable = ['vehicle_make_id', 'name', 'active'];

    protected $casts = [
        'vehicle_make_id' => 'integer',
        'active' => 'boolean',
    ];

    public function make()
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }
}
