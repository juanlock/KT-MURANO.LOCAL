<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A B2B client's commercial data that `clients` has no column for (Hordex's client card, 2026-11-21): its payment
 * condition (b2b_payment_terms) and whether it is a SUNAT withholding agent (agente de retención). One row per client.
 * It has its own `id` so the dev sync between PCs carries it like any other table. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('b2b_client_info')) {
            return;
        }
        Schema::create('b2b_client_info', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('client_id')->unique();
            $t->unsignedBigInteger('payment_term_id')->nullable();
            $t->boolean('retention_agent')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_client_info');
    }
};
