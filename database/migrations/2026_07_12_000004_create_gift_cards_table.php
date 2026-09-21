<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Wallet items" issued by the admin. A gift card is a redeemable code that
     * credits the customer's wallet balance. `type` allows future wallet-item
     * kinds (voucher, store_credit, ...) without a schema change.
     */
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('type', 30)->default('gift_card'); // gift_card | voucher | store_credit
            $table->string('code', 60)->unique();
            $table->string('name', 120)->nullable();
            $table->decimal('amount', 15, 2)->default(0);   // face value
            $table->decimal('balance', 15, 2)->default(0);  // remaining (supports partial redemption)
            $table->string('status', 20)->default('active'); // active | redeemed | disabled | expired
            $table->timestamp('expires_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->integer('created_by')->nullable();  // users.id
            $table->integer('redeemed_by')->nullable(); // clients.id of last/only redeemer
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
