<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionItem extends Model
{
    protected $table = 'prescription_items';

    protected $fillable = [
        'prescription_id', 'product_id', 'medicine', 'dosage', 'frequency',
        'duration', 'quantity', 'instructions',
    ];

    protected $casts = [
        'prescription_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'decimal:2',
    ];

    public function prescription()
    {
        return $this->belongsTo(Prescription::class, 'prescription_id', 'id');
    }

    /** Set only for drugs that exist in the pharmacy catalogue. */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
