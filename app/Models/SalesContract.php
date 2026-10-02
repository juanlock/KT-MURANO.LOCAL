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

    protected $fillable = [
        'company_id', 'contract_number', 'client_id', 'warehouse_id', 'subject', 'type', 'value',
        'start_date', 'end_date', 'description', 'status', 'hide_from_customer',
        'signer_name', 'signed_at', 'signed_ip',
        'billing_enabled', 'billing_frequency', 'billing_interval', 'next_invoice_date', 'last_invoice_date',
        'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'value' => 'decimal:2',
        'hide_from_customer' => 'boolean',
        'billing_enabled' => 'boolean',
        'billing_interval' => 'integer',
        'signed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(SalesContractItem::class, 'sales_contract_id');
    }

    public function invoices()
    {
        return $this->hasMany(SalesContractInvoice::class, 'sales_contract_id')->orderByDesc('id');
    }

    public static function generateNumber(): string
    {
        $prefix = 'SC-'.date('Ymd').'-';
        $last = static::withTrashed()->where('contract_number', 'like', $prefix.'%')->orderByDesc('id')->value('contract_number');
        $seq = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
