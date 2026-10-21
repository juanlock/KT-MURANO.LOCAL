<?php

namespace App\Models\AccountingV2;

use Illuminate\Database\Eloquent\Model;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class Budget extends Model
{
    // Multicompany (2026-10-21): only the acting company's rows.
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'acc_budgets';

    protected $fillable = [
        'name', 'start_date', 'end_date', 'currency', 'notes', 'is_active',
    ];

    public function lines()
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }
}





