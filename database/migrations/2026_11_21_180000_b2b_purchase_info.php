<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hordex's "Facturas de Proveedores" (2026-11-21): what a supplier invoice carries that `purchases` has no column for --
 * its kind (bienes / servicios / adelanto), currency and exchange rate, due and payment dates, and its state
 * (PENDIENTE / CANCELADA / ANULADA) -- plus the link from a goods receipt ("Compras") to the invoice that billed it
 * ("Vincular"). Own id for the dev sync. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('b2b_purchase_info')) {
            Schema::create('b2b_purchase_info', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('purchase_id')->unique();
                $t->string('kind', 20)->nullable();
                $t->string('currency', 3)->default('PEN');
                $t->decimal('exchange_rate', 10, 4)->nullable();
                $t->date('due_date')->nullable();
                $t->date('pay_date')->nullable();
                $t->string('status', 15)->default('PENDIENTE');
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('b2b_receipts', 'purchase_id')) {
            Schema::table('b2b_receipts', function (Blueprint $t) {
                $t->unsignedBigInteger('purchase_id')->nullable()->index()->after('invoice_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('b2b_receipts', 'purchase_id')) {
            Schema::table('b2b_receipts', fn (Blueprint $t) => $t->dropColumn('purchase_id'));
        }
        Schema::dropIfExists('b2b_purchase_info');
    }
};
