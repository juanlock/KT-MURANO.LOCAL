<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolPayment extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'school_payments';

    protected $fillable = ['reference', 'invoice_id', 'student_id', 'paid_on', 'amount', 'method', 'notes', 'created_by'];

    protected $casts = [
        'invoice_id' => 'integer',
        'student_id' => 'integer',
        'paid_on' => 'date',
        'amount' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(SchoolInvoice::class, 'invoice_id', 'id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }
}
