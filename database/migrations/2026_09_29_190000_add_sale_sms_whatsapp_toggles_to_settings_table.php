<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'sale_sms_enabled')) {
                $table->boolean('sale_sms_enabled')->default(true);
            }
            if (! Schema::hasColumn('settings', 'sale_whatsapp_enabled')) {
                $table->boolean('sale_whatsapp_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'sale_sms_enabled')) {
                $table->dropColumn('sale_sms_enabled');
            }
            if (Schema::hasColumn('settings', 'sale_whatsapp_enabled')) {
                $table->dropColumn('sale_whatsapp_enabled');
            }
        });
    }
};
