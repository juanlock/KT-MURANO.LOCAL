<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'firstname', 'lastname',
        'name', 'code', 'adresse', 'email', 'additional_emails', 'phone', 'country', 'city', 'state', 'zip', 'tax_number',
        'is_royalty_eligible', 'points', 'opening_balance', 'credit_limit',
        'woocommerce_id', 'company_id', 'user_id',
        'contact_name', 'contact_email', 'contact_phone',
        'sync_issue_type', 'sync_issue_message', 'sync_issue_source', 'sync_issue_at',
    ];

    protected $casts = [
        'code' => 'integer',
        'is_royalty_eligible' => 'integer',
        'points' => 'double',
        'opening_balance' => 'double',
        'credit_limit' => 'double',
        'sync_issue_at' => 'datetime',
        'additional_emails' => 'array',
    ];

    protected static function booted(): void
    {
        // Email Office: a new / changed address picks up that customer's
        // unassigned emails right away. Never blocks saving the customer.
        static::saved(function (Client $c) {
            if ($c->wasRecentlyCreated || $c->wasChanged(['email', 'additional_emails', 'contact_email'])) {
                try {
                    \App\Services\EmailOffice\EmailOffice::assignToClient((int) $c->id);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * Get custom field values for this client
     */
    public function customFieldValues()
    {
        return $this->morphMany(CustomFieldValue::class, 'entity', 'entity_type', 'entity_id');
    }
}
