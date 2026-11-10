<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone app is gone (user's decision, 2026-11-10): Murano has no app of its
 * own, nobody had used it (no device registered, no sign-in from it), so its API,
 * its settings screen and its push notifications were removed. Phones use the web
 * app (installable as a PWA). Drops its table and its settings columns; safe to
 * run twice.
 */
return new class extends Migration
{
    private array $columns = [
        'mobile_app_enabled', 'mobile_app_name', 'mobile_logo', 'mobile_primary_color', 'mobile_theme_mode',
        'mobile_min_version', 'mobile_maintenance_message', 'mobile_modules', 'mobile_offline_enabled',
        'mobile_scanner_enabled', 'mobile_allow_price_edit', 'mobile_support_phone', 'mobile_support_email',
    ];

    public function up(): void
    {
        Schema::dropIfExists('mobile_device_tokens');

        $present = array_values(array_filter($this->columns, fn ($c) => Schema::hasColumn('settings', $c)));
        if ($present) {
            Schema::table('settings', function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }

    public function down(): void
    {
        // Not restored: the feature's code is gone with it (git history keeps it).
    }
};
