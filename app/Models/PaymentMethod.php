<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Methods offered when RECORDING a new payment. Listings that describe
     * existing payments (reports, filters) must NOT use this scope — a
     * disabled method still appears on historical records.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
