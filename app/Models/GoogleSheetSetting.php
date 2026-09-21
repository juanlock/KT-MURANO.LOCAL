<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleSheetSetting extends Model
{
    protected $table = 'google_sheet_settings';

    protected $fillable = [
        'enabled', 'client_id', 'client_secret',
        'access_token', 'refresh_token', 'access_token_expires_at',
        'spreadsheet_id', 'auto_export', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_export' => 'boolean',
        'access_token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConnected(): bool
    {
        return ! empty($this->refresh_token);
    }

    public function hasApiCredentials(): bool
    {
        return ! empty($this->client_id) && ! empty($this->client_secret);
    }

    public function spreadsheetUrl(): ?string
    {
        return $this->spreadsheet_id
            ? 'https://docs.google.com/spreadsheets/d/'.$this->spreadsheet_id
            : null;
    }
}
