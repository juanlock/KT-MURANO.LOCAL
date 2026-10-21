<?php

namespace App\Models\AccountingV2;

use Illuminate\Database\Eloquent\Model;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class TaxReport extends Model
{
    // Multicompany (2026-10-21): only the acting company's rows.
    use \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'acc_tax_reports';

    protected $fillable = [
        'period_start', 'period_end', 'type', 'taxable_sales', 'output_tax', 'taxable_purchases', 'input_tax', 'net_tax', 'source', 'generated_at',
    ];
}





