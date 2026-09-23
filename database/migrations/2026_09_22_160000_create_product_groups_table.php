<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real parent table for the "umbrella name shared by many independently
 * priced product rows" case (e.g. a services company's "Chemical
 * Application Services" covering 29 separately priced line items).
 *
 * Billing modeled this with a flat `products.sub_name` text column and an
 * app-level (company_id, name, sub_name) uniqueness check -- no FK, so
 * grouping was done purely by string-matching `products.name`. Murano's
 * sync dropped that column entirely and the string-matching hack already
 * produced 37 real (company_id, name) collisions in murano's live data
 * (see kt_logs / CLAUDE.md). This table replaces the hack with a real
 * parent row + FK; see 2026_09_22_160100_add_product_group_id_and_sub_name_to_products_table.php
 * for the child-side columns and the DB-level unique constraint.
 *
 * Deliberately NOT a substitute for `product_variants` (true Size/Color-style
 * variants of one product row, sharing category/image/brand) -- that table
 * is untouched and serves a different, already-live purpose in both systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('company_id');
            $table->string('name', 192);
            $table->timestamps(6);
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_groups');
    }
};
