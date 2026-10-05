<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bill with the same amount every period -- App\Services\RecurringExpenses
 * creates its expenses on their dates. Per company (company_id).
 */
class RecurringExpense extends Model
{
    use SoftDeletes;

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected $fillable = [
        'company_id', 'user_id', 'warehouse_id', 'expense_category_id', 'payment_method_id', 'account_id',
        'amount', 'details', 'frequency', 'start_date', 'next_date', 'end_date', 'is_active',
        'last_generated_on', 'generated_count',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        'expense_category_id' => 'integer',
        'payment_method_id' => 'integer',
        'account_id' => 'integer',
        'amount' => 'double',
        'is_active' => 'boolean',
        'generated_count' => 'integer',
    ];

    public function expense_category()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function payment_method()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
