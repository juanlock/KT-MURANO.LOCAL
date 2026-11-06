<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $dates = ['deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'firstname', 'lastname', 'username', 'email', 'password', 'phone', 'statut', 'avatar', 'role_id', 'type', 'is_all_warehouses', 'record_view', 'company_id',
        // Client Portal (merged from portal_clients; see App\Models\PortalClient)
        'portal_enabled', 'preferred_locale', 'invitation_token', 'invitation_sent_at',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'invitation_token',
        // Cashier PIN (POS open/close register). Plain digits, unique per
        // company; kept out of API payloads -- only the user form reads it.
        'pin',
        // Start page choice + usage counts (get_user_auth sends the user's own).
        'start_preferences',
        // Login security (2026-10-27): the authenticator secret and recovery codes never leave the server.
        'two_factor_secret', 'two_factor_recovery',
    ];

    // Does $pin confirm this user? (constant-time compare)
    public function pinMatches($pin): bool
    {
        return $this->pin !== null && $this->pin !== '' && $pin !== null && $pin !== ''
            && hash_equals((string) $this->pin, (string) $pin);
    }

    // Is $pin already held by another (non-deleted) user of the same company?
    public static function pinTaken($companyId, $pin, $exceptUserId = null): bool
    {
        return static::where('company_id', $companyId)->where('pin', (string) $pin)
            ->whereNull('deleted_at')
            ->when($exceptUserId, fn ($q) => $q->where('id', '<>', $exceptUserId))
            ->exists();
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'role_id' => 'integer',
        'statut' => 'integer',
        'is_all_warehouses' => 'integer',
        'record_view' => 'boolean',
        'is_sales_staff' => 'boolean',
        'portal_enabled' => 'integer',
        'invitation_sent_at' => 'datetime',
    ];

    public function oauthAccessToken()
    {
        return $this->hasMany('\App\Models\OauthAccessToken');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    // The Customer profile this account was auto-provisioned for, if this
    // is a type=customer login (Client<->User is 1-to-1). Needed now that
    // 212 Billing users of this type were synced in via
    // sync_users_remaining_from_billing.sql.
    public function client()
    {
        return $this->hasOne(\App\Models\Client::class);
    }

    public function assignRole(Role $role)
    {
        return $this->roles()->save($role);
    }

    public function hasRole($role)
    {
        if (is_string($role)) {
            return $this->roles->contains('name', $role);
        }

        return (bool) $role->intersect($this->roles)->count();
    }

    public function assignedWarehouses()
    {
        return $this->belongsToMany('App\Models\Warehouse')->withoutGlobalScope('company');
    }

    /**
     * Check if user has record_view permission (user-level boolean with backward compatibility)
     * 
     * @return bool
     */
    public function hasRecordView()
    {
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        if (isset($this->record_view)) {
            return (bool) $this->record_view;
        } else {
            // Fallback to role permission check for backward compatibility
            $role = $this->roles()->first();
            if ($role) {
                return $role->inRole('record_view');
            }
        }
        
        return false;
    }
}
