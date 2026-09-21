<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleMake extends Model
{
    protected $fillable = ['name', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function models()
    {
        return $this->hasMany(VehicleModel::class);
    }
}
