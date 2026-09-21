<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileDeviceToken extends Model
{
    protected $table = 'mobile_device_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_name',
        'app_version',
        'last_used_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
