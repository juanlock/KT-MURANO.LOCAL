<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabOrder extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'lab_orders';

    protected $fillable = [
        'reference', 'patient_id', 'doctor_id', 'visit_id', 'ordered_at', 'completed_at',
        'priority', 'status', 'total', 'notes', 'created_by',
    ];

    protected $casts = [
        'patient_id' => 'integer',
        'doctor_id' => 'integer',
        'visit_id' => 'integer',
        'ordered_at' => 'datetime',
        'completed_at' => 'datetime',
        'total' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(LabOrderItem::class, 'lab_order_id', 'id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'id');
    }
}
