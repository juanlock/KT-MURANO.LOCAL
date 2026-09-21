<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFitment extends Model
{
    protected $fillable = [
        'product_id', 'vehicle_make_id', 'vehicle_model_id',
        'year_from', 'year_to', 'engine', 'notes',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'vehicle_make_id' => 'integer',
        'vehicle_model_id' => 'integer',
        'year_from' => 'integer',
        'year_to' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function make()
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function model()
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }
}
