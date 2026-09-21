<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HospitalInvoiceItem extends Model
{
    protected $table = 'hospital_invoice_items';

    protected $fillable = ['invoice_id', 'type', 'product_id', 'description', 'quantity', 'unit_price', 'total'];

    protected $casts = [
        'invoice_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(HospitalInvoice::class, 'invoice_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
