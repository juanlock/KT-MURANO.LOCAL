<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->boolean('allow_cancellations')->default(true)->after('require_email_verification');
            // Days after delivery a customer may request a return. 0 disables returns.
            $table->unsignedInteger('return_window_days')->default(14)->after('allow_cancellations');
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_cancellations', 'return_window_days']);
        });
    }
};
