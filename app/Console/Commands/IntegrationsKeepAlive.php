<?php

namespace App\Console\Commands;

use App\Models\SallaSetting;
use App\Models\XeroSetting;
use Illuminate\Console\Command;

/**
 * Keeps OAuth-based integration connections alive.
 *
 * Why: refresh tokens expire on idle — Salla's after 1 month, Xero's after 60
 * days. Without activity the connection would die silently. One authenticated
 * ping per connector per day refreshes tokens through the connectors' own
 * clients (which serialize refreshes behind locks), so the expiry clock keeps
 * resetting. Google Sheets refresh tokens do not expire on idle.
 */
class IntegrationsKeepAlive extends Command
{
    protected $signature = 'integrations:keep-alive';

    protected $description = 'Ping connected Salla/Xero accounts so their OAuth refresh tokens never expire on idle';

    public function handle(): int
    {
        $this->pingSalla();
        $this->pingXero();

        return self::SUCCESS;
    }

    private function pingSalla(): void
    {
        try {
            $settings = SallaSetting::query()->first();
            if (! $settings || ! $settings->enabled || ! $settings->isConnected()) {
                return;
            }

            $result = \App\Services\Salla\SyncService::make($settings)->testConnection();
            $this->line('salla ping '.(($result['ok'] ?? false) ? 'ok' : 'FAILED — '.($result['error'] ?? '?')));
            if (! ($result['ok'] ?? false)) {
                \App\Services\Salla\SyncService::make($settings)->log('keep_alive', 'warning', 'Keep-alive ping failed: '.($result['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            $this->warn('salla ping error — '.$e->getMessage());
        }
    }

    private function pingXero(): void
    {
        try {
            $settings = XeroSetting::query()->first();
            if (! $settings || ! $settings->enabled || ! $settings->isConnected()) {
                return;
            }

            $result = \App\Services\Xero\SyncService::make($settings)->testConnection();
            $this->line('xero ping '.(($result['ok'] ?? false) ? 'ok' : 'FAILED — '.($result['error'] ?? '?')));
            if (! ($result['ok'] ?? false)) {
                \App\Services\Xero\SyncService::make($settings)->log('keep_alive', 'warning', 'Keep-alive ping failed: '.($result['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            $this->warn('xero ping error — '.$e->getMessage());
        }
    }
}
