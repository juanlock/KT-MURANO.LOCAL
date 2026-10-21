<?php

namespace App\Models\AccountingV2;

use Illuminate\Database\Eloquent\Model;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class BudgetLine extends Model
{
    // Multicompany (2026-10-21): only the acting company's rows.
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'acc_budget_lines';

    protected $fillable = [
        'budget_id', 'coa_id', 'amount', 'period',
    ];

    public function budget()
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function coa()
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }
}





