<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabOrderItem extends Model
{
    protected $table = 'lab_order_items';

    protected $fillable = [
        'lab_order_id', 'lab_test_id', 'test_name', 'price', 'result_value',
        'unit', 'normal_range', 'flag', 'remarks',
    ];

    protected $casts = [
        'lab_order_id' => 'integer',
        'lab_test_id' => 'integer',
        'price' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id', 'id');
    }

    public function test()
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id', 'id');
    }
}
