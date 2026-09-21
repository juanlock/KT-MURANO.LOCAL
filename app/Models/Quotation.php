<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'client_id', 'GrandTotal', 'warehouse_id', 'user_id', 'statut', 'time',
        'notes', 'discount', 'shipping', 'TaxNet', 'tax_rate', 'created_at', 'updated_at', 'deleted_at',
        // Multi-Currency snapshot; NULL = base currency, rate 1
        'currency_id', 'exchange_rate',
    ];

    protected $casts = [
        'GrandTotal' => 'double',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'currency_id' => 'integer',
        'exchange_rate' => 'float',
    ];

    public function currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\QuotationDetail');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }
}
