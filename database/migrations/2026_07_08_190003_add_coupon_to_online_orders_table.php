<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->default(0)->after('subtotal');
            $table->string('coupon_code', 60)->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn(['discount', 'coupon_code']);
        });
    }
};
