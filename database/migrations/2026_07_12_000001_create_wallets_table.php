<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            // Owner is the POS customer (clients.id). Storefront accounts map to a
            // client via ecommerce_clients.client_id, so the wallet is shared.
            $table->integer('client_id')->unique();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('status', 20)->default('active'); // active | frozen
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
