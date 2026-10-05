<?php

namespace App\Models\AccountingV2;

use Illuminate\Database\Eloquent\Model;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class ChartOfAccount extends Model
{
    protected $table = 'acc_chart_of_accounts';

    // company_id: the company's own chart (null = an older shared account,
    // still used by existing journal entries). name_es: Spanish name.
    protected $fillable = [
        'company_id', 'account_id', 'code', 'name', 'name_es', 'type', 'parent_id', 'level', 'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'parent_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}





