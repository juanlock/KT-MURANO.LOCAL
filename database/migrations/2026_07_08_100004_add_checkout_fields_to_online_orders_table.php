<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            // Money breakdown (total already exists = grand total)
            $table->decimal('subtotal', 15, 2)->default(0)->after('total');
            $table->decimal('tax', 15, 2)->default(0)->after('subtotal');
            $table->decimal('tax_rate', 8, 3)->default(0)->after('tax');
            $table->decimal('shipping_cost', 15, 2)->default(0)->after('tax_rate');

            // Shipping method used
            $table->unsignedBigInteger('shipping_method_id')->nullable()->after('shipping_cost');
            $table->string('shipping_method_name', 120)->nullable()->after('shipping_method_id');

            // Customer / shipping address snapshot (frozen at order time)
            $table->string('customer_name', 150)->nullable()->after('shipping_method_name');
            $table->string('customer_email', 190)->nullable()->after('customer_name');
            $table->string('customer_phone', 40)->nullable()->after('customer_email');
            $table->string('shipping_address', 250)->nullable()->after('customer_phone');
            $table->string('shipping_city', 100)->nullable()->after('shipping_address');
            $table->string('shipping_state', 100)->nullable()->after('shipping_city');
            $table->string('shipping_zip', 30)->nullable()->after('shipping_state');
            $table->string('shipping_country', 100)->nullable()->after('shipping_zip');

            // Fraud review
            $table->boolean('is_flagged')->default(false)->after('shipping_country');
            $table->string('flag_reason', 500)->nullable()->after('is_flagged');

            $table->foreign('shipping_method_id')
                ->references('id')->on('shipping_methods')
                ->nullOnDelete();

            $table->index(['is_flagged']);
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_method_id']);
            $table->dropColumn([
                'subtotal', 'tax', 'tax_rate', 'shipping_cost',
                'shipping_method_id', 'shipping_method_name',
                'customer_name', 'customer_email', 'customer_phone',
                'shipping_address', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
                'is_flagged', 'flag_reason',
            ]);
        });
    }
};
