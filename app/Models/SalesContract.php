<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sales > Sales Contracts: a contract with a CUSTOMER (employee contracts are
 * App\Models\Contract, HRM). Company-scoped. Its lines (items) are what each
 * recurring invoice bills; generated invoices are SalesContractInvoice rows
 * linking to the Sale (see App\Services\SalesContractBilling).
 */
class SalesContract extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'active', 'expired', 'cancelled'];
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];
    // Which day each invoice falls on (see SalesContractBilling::align()).
    public const DAY_MODES = ['same', 'first', 'last', 'day'];

    protected $fillable = [
        'company_id', 'contract_number', 'client_id', 'warehouse_id', 'subject', 'type', 'value',
        // Order tax % on each invoice (sales.tax_rate), like a sale's Order Tax.
        'order_tax_rate',
        'start_date', 'end_date', 'description', 'invoice_note', 'status', 'hide_from_customer',
        'signer_name', 'signed_at', 'signed_ip',
        'billing_enabled', 'invoice_as_draft', 'billing_frequency', 'billing_interval', 'billing_count', 'billing_day_mode', 'billing_day',
        'next_invoice_date', 'last_invoice_date',
        // Recurring service jobs (visits) -- App\Services\SalesContractJobs.
        'jobs_enabled', 'jobs_service_item', 'jobs_job_type', 'jobs_technician_id', 'jobs_frequency', 'jobs_interval',
        'jobs_count', 'jobs_day_mode', 'jobs_day', 'jobs_time', 'jobs_lead_days', 'next_job_date',
        'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'value' => 'decimal:2',
        'order_tax_rate' => 'float',
        'hide_from_customer' => 'boolean',
        'billing_enabled' => 'boolean',
        'invoice_as_draft' => 'boolean',
        'billing_interval' => 'integer',
        'billing_count' => 'integer',
        'billing_day' => 'integer',
        'jobs_enabled' => 'boolean',
        'jobs_technician_id' => 'integer',
        'jobs_interval' => 'integer',
        'jobs_count' => 'integer',
        'jobs_day' => 'integer',
        'jobs_lead_days' => 'integer',
        'signed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id')->withoutGlobalScope('company');
    }

    public function items()
    {
        return $this->hasMany(SalesContractItem::class, 'sales_contract_id');
    }

    public function invoices()
    {
        return $this->hasMany(SalesContractInvoice::class, 'sales_contract_id')->orderByDesc('id');
    }

    /** Service jobs (visits) this contract created. */
    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class, 'sales_contract_id')->orderBy('scheduled_date');
    }

    public static function generateNumber(): string
    {
        $prefix = 'SC-'.date('Ymd').'-';
        $last = static::withTrashed()->where('contract_number', 'like', $prefix.'%')->orderByDesc('id')->value('contract_number');
        $seq = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
