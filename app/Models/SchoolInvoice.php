<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolInvoice extends Model
{
    // The reference generator is module-agnostic despite its name; it was
    // written for the hospital module and produces PREFIX-YYYYMMDD-NNNN.
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'school_invoices';

    protected $fillable = [
        'reference', 'student_id', 'academic_year_id', 'class_id', 'invoice_date',
        'due_date', 'subtotal', 'discount', 'total', 'paid', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'academic_year_id' => 'integer',
        'class_id' => 'integer',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(SchoolInvoiceItem::class, 'invoice_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(SchoolPayment::class, 'invoice_id', 'id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    public function getDueAttribute()
    {
        return round((float) $this->total - (float) $this->paid, 2);
    }

    /**
     * Payment status derived from the money, never set by hand — the one place
     * that decides whether a bill is settled. Cancelled and draft are editorial
     * states and are left alone.
     */
    public function syncStatus()
    {
        if (in_array($this->status, ['cancelled', 'draft'], true)) {
            return $this;
        }

        $paid = (float) $this->paid;
        $total = (float) $this->total;

        if ($paid <= 0) {
            $this->status = 'unpaid';
        } elseif ($paid + 0.001 < $total) {
            $this->status = 'partial';
        } else {
            $this->status = 'paid';
        }

        return $this;
    }
}
