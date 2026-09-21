<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Pack Selling for variant products.
 *
 * Adds product_packs.product_variant_id so a pack can belong to a specific
 * variant of a variable product. NULL keeps the original meaning: a pack of a
 * single (standard) product. Each (product, variant) scope keeps its own
 * default pack (multiplier = 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_packs') || Schema::hasColumn('product_packs', 'product_variant_id')) {
            return;
        }

        Schema::table('product_packs', function (Blueprint $table) {
            // Matches the signed integer PK of product_variants.
            $table->integer('product_variant_id')->nullable()->after('product_id');
            $table->index(['product_id', 'product_variant_id'], 'pp_product_variant_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_packs') || ! Schema::hasColumn('product_packs', 'product_variant_id')) {
            return;
        }

        Schema::table('product_packs', function (Blueprint $table) {
            $table->dropIndex('pp_product_variant_idx');
            $table->dropColumn('product_variant_id');
        });
    }
};
