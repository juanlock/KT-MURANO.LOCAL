<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'code', 'adresse', 'phone', 'country', 'email', 'additional_emails', 'city', 'tax_number',
        'opening_balance', 'credit_limit', 'company_id',
        'state', 'zip', 'contact_name', 'contact_email', 'contact_phone',
    ];

    protected $casts = [
        'code' => 'integer',
        'opening_balance' => 'double',
        'credit_limit' => 'double',
        'additional_emails' => 'array',
    ];

    /**
     * Get custom field values for this provider
     */
    public function customFieldValues()
    {
        return $this->morphMany(CustomFieldValue::class, 'entity', 'entity_type', 'entity_id');
    }
}
