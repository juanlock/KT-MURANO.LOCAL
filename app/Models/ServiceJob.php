<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceJob extends Model
{
    use HasFactory;

    protected $table = 'service_jobs';

    /**
     * Statuses whose balance counts toward the customer's due. Pending jobs
     * are still quotes the customer has not accepted; declined / cancelled
     * jobs are never collectable.
     */
    public const DUE_STATUSES = ['approved', 'in_progress', 'ready', 'delivered', 'completed'];

    protected $dates = [
        'scheduled_date',
        'scheduled_end_date',
        'started_at',
        'completed_at',
        'quote_valid_until',
        'quote_approved_at',
        'warranty_expires_at',
        'delivered_at',
        'deleted_at',
    ];

    protected $fillable = [
        'Ref',
        'client_id',
        'technician_id',
        'warehouse_id',
        'service_item',
        'job_type',
        'status',
        'scheduled_date',
        'scheduled_end_date',
        'started_at',
        'completed_at',
        'notes',

        // Device identity
        'device_brand',
        'device_model',
        'device_serial',
        'device_imei',
        'device_color',
        'device_password',
        'accessories',

        // Intake / diagnostic
        'condition_on_arrival',
        'reported_issue',
        'diagnosis',
        'diagnostic_fee',

        // Quote
        'quote_amount',
        'quote_valid_until',
        'quote_approved_at',
        'quote_approved_by',

        // Totals & payment
        'total_amount',
        'paid_amount',
        'payment_status',

        // Warranty
        'warranty_days',
        'warranty_expires_at',
        'parent_job_id',
        'quotation_id',

        // Delivery
        'delivered_at',
        'pickup_signature',

        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'accessories' => 'array',
        'diagnostic_fee' => 'float',
        'quote_amount' => 'float',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'warranty_days' => 'integer',
        'client_id' => 'integer',
        'technician_id' => 'integer',
        'warehouse_id' => 'integer',
        'parent_job_id' => 'integer',
        'quotation_id' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function technician()
    {
        return $this->belongsTo(ServiceTechnician::class, 'technician_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function checklistItems()
    {
        return $this->hasMany(ServiceJobChecklistItem::class, 'service_job_id');
    }

    public function items()
    {
        return $this->hasMany(ServiceJobItem::class, 'service_job_id');
    }

    public function payments()
    {
        return $this->hasMany(ServiceJobPayment::class, 'service_job_id');
    }

    public function photos()
    {
        return $this->hasMany(ServiceJobPhoto::class, 'service_job_id');
    }

    public function parentJob()
    {
        return $this->belongsTo(ServiceJob::class, 'parent_job_id');
    }

    public function warrantyClaims()
    {
        return $this->hasMany(ServiceJob::class, 'parent_job_id');
    }

    /** Jobs whose outstanding balance is owed by the customer. */
    public function scopeCountsTowardDue($query)
    {
        return $query->whereNull('service_jobs.deleted_at')
            ->whereIn('service_jobs.status', self::DUE_STATUSES);
    }

    /**
     * Aggregate service totals for one customer: ['total', 'paid', 'due'].
     * Same rule everywhere (customers list, ledger, pay-due, POS previous dues).
     */
    public static function dueTotalsForClient($clientId): array
    {
        if (! $clientId) {
            return ['total' => 0.0, 'paid' => 0.0, 'due' => 0.0];
        }

        $row = static::query()
            ->countsTowardDue()
            ->where('client_id', $clientId)
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total, COALESCE(SUM(paid_amount), 0) AS paid')
            ->first();

        $total = (float) ($row->total ?? 0);
        $paid = (float) ($row->paid ?? 0);

        return ['total' => $total, 'paid' => $paid, 'due' => $total - $paid];
    }

    public function getBalanceDueAttribute(): float
    {
        return (float) ($this->total_amount ?? 0) - (float) ($this->paid_amount ?? 0);
    }
}
