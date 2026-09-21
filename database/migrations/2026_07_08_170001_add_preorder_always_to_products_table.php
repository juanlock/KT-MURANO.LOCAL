<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // "Pure pre-order": when true, the product is always sold as a
            // pre-order even while stock is on hand (only meaningful with is_preorder).
            $table->boolean('preorder_always')->default(false)->after('is_preorder');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('preorder_always');
        });
    }
};
