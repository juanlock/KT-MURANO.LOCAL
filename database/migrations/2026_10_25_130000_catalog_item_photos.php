<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio tablet catalogs: each item's model photos (2026-10-25) -- tapping a
 * service's picture on the tablet shows them and the customer picks one.
 * JSON list of paths (files/company_{id}/portfolio/...).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portfolio_catalog_items', 'photos')) {
            Schema::table('portfolio_catalog_items', function (Blueprint $t) {
                $t->json('photos')->nullable()->after('options');
            });
        }
    }

    public function down(): void
    {
        Schema::table('portfolio_catalog_items', fn (Blueprint $t) => $t->dropColumn('photos'));
    }
};
