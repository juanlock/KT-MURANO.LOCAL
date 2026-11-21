<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A finished product's pack size (Hordex's "Empaque" / productos.paso, 2026-11-21): units per box, used to consume a
 * packing material (embalaje) as 1 / pack_size per unit when a guide manufactures it. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'pack_size')) {
            Schema::table('products', function (Blueprint $t) {
                $t->unsignedInteger('pack_size')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'pack_size')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('pack_size'));
        }
    }
};
