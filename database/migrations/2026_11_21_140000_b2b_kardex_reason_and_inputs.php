<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hordex's product card (2026-11-21):
 *  - b2b_kardex.reason: the movement's motive as the earlier system names it (VENTA, FABRICACION, COMPRA LOCAL, AJUSTE
 *    INVENTARIO...), shown in "Últimos movimientos";
 *  - b2b_product_inputs: a finished product's recipe ("Insumos necesarios": input product + quantity per unit), with its
 *    own id so the dev sync carries it.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('b2b_kardex', 'reason')) {
            Schema::table('b2b_kardex', function (Blueprint $t) {
                $t->string('reason', 60)->nullable()->after('direction');
            });
        }
        if (! Schema::hasTable('b2b_product_inputs')) {
            Schema::create('b2b_product_inputs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('product_id')->index();
                $t->unsignedBigInteger('input_id');
                $t->decimal('quantity', 20, 6)->default(1);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_product_inputs');
        if (Schema::hasColumn('b2b_kardex', 'reason')) {
            Schema::table('b2b_kardex', fn (Blueprint $t) => $t->dropColumn('reason'));
        }
    }
};
