<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Electronics storefront theme: per-theme presentation options (hero slides,
 * promo banners, trust strip, stats, section toggles) live in one JSON map so
 * adding a theme never means adding columns. `theme` itself already exists
 * (default | real_estate) and gains the `electronics` value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'theme_options')) {
                $table->json('theme_options')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (Schema::hasColumn('store_settings', 'theme_options')) {
                $table->dropColumn('theme_options');
            }
        });
    }
};
