<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Client Portal login = the customer's own row in `users` (type 'customer').
 *
 * The old separate `portal_clients` table was merged into users
 * (2026_09_27_190000_move_portal_clients_to_users): a customer is created
 * with its user (LinkedUserService::provision), and the portal simply turns
 * that user's login on/off via users.portal_enabled.
 *
 * This class stays as the portal auth guard's model (config/auth.php) so the
 * portal controllers keep their `$portalClient->client_id` / `->status`
 * usage: both are mapped onto users here. The global scope keeps the portal
 * guard from ever resolving an admin/employee row.
 */
class PortalClient extends User
{
    protected $table = 'users';

    protected $fillable = [
        'email', 'password', 'status', 'portal_enabled', 'preferred_locale',
        'invitation_token', 'invitation_sent_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'invitation_token',
    ];

    protected $casts = [
        'portal_enabled' => 'integer',
        'invitation_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('portal_customer', function (Builder $q) {
            $q->where('users.type', 'customer')->whereNull('users.deleted_at');
        });
    }

    // users.id <- clients.user_id (explicit key: the subclass name would
    // otherwise make Eloquent look for clients.portal_client_id).
    public function client()
    {
        return $this->hasOne(Client::class, 'user_id');
    }

    /** The linked Client's id (was a real portal_clients column). */
    public function getClientIdAttribute(): ?int
    {
        return optional($this->client)->id;
    }

    /** Portal on/off (was portal_clients.status), stored as users.portal_enabled. */
    public function getStatusAttribute(): int
    {
        return (int) ($this->attributes['portal_enabled'] ?? 0);
    }

    public function setStatusAttribute($value): void
    {
        $this->attributes['portal_enabled'] = (int) $value;
    }

    public function canAccessPortal(): bool
    {
        return (int) $this->status === 1 && (int) $this->statut === 1;
    }
}
