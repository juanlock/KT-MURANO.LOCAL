<?php

namespace App\Models;

use App\Traits\GeneratesHospitalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalInvoice extends Model
{
    use SoftDeletes, GeneratesHospitalReference;

    protected $table = 'hospital_invoices';

    protected $fillable = [
        'reference', 'patient_id', 'visit_id', 'admission_id', 'lab_order_id',
        'invoice_date', 'due_date', 'subtotal', 'discount', 'tax', 'total', 'paid',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'patient_id' => 'integer',
        'visit_id' => 'integer',
        'admission_id' => 'integer',
        'lab_order_id' => 'integer',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(HospitalInvoiceItem::class, 'invoice_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(HospitalPayment::class, 'invoice_id', 'id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
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
