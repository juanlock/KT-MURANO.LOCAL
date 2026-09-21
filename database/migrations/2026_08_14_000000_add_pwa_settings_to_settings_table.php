<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installable-app (PWA) identity, editable from System Settings → PWA.
 *
 * Before this, the manifest name was derived from app_name and everything else
 * (colors, display mode, launch URL) was hardcoded in the manifest route. All
 * columns are nullable so an empty value keeps the previous derived default —
 * PwaManifestController falls back to app_name and the original constants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'pwa_enabled')) {
                $table->boolean('pwa_enabled')->default(1);
            }
            if (! Schema::hasColumn('settings', 'pwa_name')) {
                $table->string('pwa_name')->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_short_name')) {
                // Shown under the launcher icon, so browsers expect it short.
                $table->string('pwa_short_name', 60)->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_description')) {
                $table->string('pwa_description')->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_start_url')) {
                $table->string('pwa_start_url')->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_display')) {
                $table->string('pwa_display', 20)->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_orientation')) {
                $table->string('pwa_orientation', 20)->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_theme_color')) {
                $table->string('pwa_theme_color', 20)->nullable();
            }
            if (! Schema::hasColumn('settings', 'pwa_background_color')) {
                $table->string('pwa_background_color', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ([
                'pwa_enabled', 'pwa_name', 'pwa_short_name', 'pwa_description',
                'pwa_start_url', 'pwa_display', 'pwa_orientation',
                'pwa_theme_color', 'pwa_background_color',
            ] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
