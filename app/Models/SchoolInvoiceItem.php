<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolInvoiceItem extends Model
{
    protected $table = 'school_invoice_items';

    protected $fillable = ['invoice_id', 'fee_structure_id', 'description', 'quantity', 'unit_price', 'total'];

    protected $casts = [
        'invoice_id' => 'integer',
        'fee_structure_id' => 'integer',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(SchoolInvoice::class, 'invoice_id', 'id');
    }
}
