<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile app (Flutter) settings, editable from System Settings → Mobile App.
 *
 * Everything is nullable so an untouched install behaves exactly as before:
 * the app stays enabled, falls back to the company name and logo, and no
 * module is hidden. The app reads these from /api/mobile/ping at launch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'mobile_app_enabled')) {
                $table->boolean('mobile_app_enabled')->default(1);
            }
            if (! Schema::hasColumn('settings', 'mobile_app_name')) {
                $table->string('mobile_app_name', 120)->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_logo')) {
                $table->string('mobile_logo')->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_primary_color')) {
                $table->string('mobile_primary_color', 20)->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_theme_mode')) {
                // system | light | dark — the default the app opens with.
                $table->string('mobile_theme_mode', 10)->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_min_version')) {
                $table->string('mobile_min_version', 20)->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_maintenance_message')) {
                $table->text('mobile_maintenance_message')->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_modules')) {
                // {module_key: bool} — null means every module is available.
                $table->json('mobile_modules')->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_offline_enabled')) {
                $table->boolean('mobile_offline_enabled')->default(1);
            }
            if (! Schema::hasColumn('settings', 'mobile_scanner_enabled')) {
                $table->boolean('mobile_scanner_enabled')->default(1);
            }
            if (! Schema::hasColumn('settings', 'mobile_allow_price_edit')) {
                // Lets a cashier change a line price in the app's cart.
                $table->boolean('mobile_allow_price_edit')->default(1);
            }
            if (! Schema::hasColumn('settings', 'mobile_support_phone')) {
                $table->string('mobile_support_phone', 40)->nullable();
            }
            if (! Schema::hasColumn('settings', 'mobile_support_email')) {
                $table->string('mobile_support_email', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ([
                'mobile_app_enabled', 'mobile_app_name', 'mobile_logo',
                'mobile_primary_color', 'mobile_theme_mode', 'mobile_min_version',
                'mobile_maintenance_message', 'mobile_modules',
                'mobile_offline_enabled', 'mobile_scanner_enabled',
                'mobile_allow_price_edit', 'mobile_support_phone', 'mobile_support_email',
            ] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
