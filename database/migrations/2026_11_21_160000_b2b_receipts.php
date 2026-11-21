<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribución B2B -> Compras = goods received into the warehouse (Hordex's "Compras" / ingresos, 2026-11-21), apart
 * from the suppliers' invoices (purchases). A receipt of kind "compra" adds stock (kardex COMPRA LOCAL, linked by
 * b2b_kardex.receipt_id so deleting the receipt takes its stock back out). Every table has its own id (dev sync).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('b2b_receipts')) {
            Schema::create('b2b_receipts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->date('date');
                $t->string('motive', 80)->nullable();          // the earlier system's transport motive (COMPRA LOCAL...)
                $t->string('kind', 20)->nullable();            // compra / adelanto / servicio
                $t->unsignedBigInteger('provider_id')->nullable()->index();
                $t->string('guide', 30)->nullable();
                $t->string('invoice', 30)->nullable();
                $t->date('invoice_date')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('b2b_receipt_items')) {
            Schema::create('b2b_receipt_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('receipt_id')->index();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->string('currency', 3)->default('PEN');
                $t->decimal('price', 16, 7)->nullable();
                $t->decimal('quantity', 14, 3)->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('b2b_kardex', 'receipt_id')) {
            Schema::table('b2b_kardex', function (Blueprint $t) {
                $t->unsignedBigInteger('receipt_id')->nullable()->index()->after('reference');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('b2b_kardex', 'receipt_id')) {
            Schema::table('b2b_kardex', fn (Blueprint $t) => $t->dropColumn('receipt_id'));
        }
        Schema::dropIfExists('b2b_receipt_items');
        Schema::dropIfExists('b2b_receipts');
    }
};
