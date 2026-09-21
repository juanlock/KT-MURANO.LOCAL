<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalPayment extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'hospital_payments';

    protected $fillable = ['reference', 'invoice_id', 'patient_id', 'paid_on', 'amount', 'method', 'notes', 'created_by'];

    protected $casts = [
        'invoice_id' => 'integer',
        'patient_id' => 'integer',
        'paid_on' => 'date',
        'amount' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(HospitalInvoice::class, 'invoice_id', 'id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }
}
