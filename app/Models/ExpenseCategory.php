<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    // Per company (company_id), with the expense-list columns
    // (App\Support\DefaultAccounting). The name is translated through the
    // translations table (key = name, App\Support\TranslationKeys);
    // reference: a short explanation of the category. coa_code: the account
    // in the company's chart that expenses of this category post to.
    protected $fillable = [
        'company_id', 'user_id', 'description', 'name', 'reference',
        'account_type', 'expense_group', 'expense_group_es', 'typical_account', 'period', 'period_es',
        'approximate_amount', 'is_common', 'coa_code', 'sort_order',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'company_id' => 'integer',
        'is_common' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function expense()
    {
        return $this->belongsTo('App\Models\Expense');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }
}
