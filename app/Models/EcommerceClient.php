<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EcommerceClient extends Model implements Authenticatable
{
    use AuthenticatableTrait, SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'client_id', 'username', 'email', 'status', 'password',
        'invite_code_id', 'referred_by',
        'email_verified_at', 'is_blocked', 'terms_accepted_at',
        'preferred_locale', 'preferred_currency_id',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'email_verified_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'status' => 'integer',
        'is_blocked' => 'boolean',
        'password' => 'hashed',
        'invite_code_id' => 'integer',
        'referred_by' => 'integer',
        'preferred_currency_id' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function inviteCode()
    {
        return $this->belongsTo(InviteCode::class, 'invite_code_id');
    }

    public function referrer()
    {
        return $this->belongsTo(self::class, 'referred_by');
    }
}
