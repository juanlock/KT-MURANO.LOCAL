<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Xero: opt-in automatic invoice push for newly created sales. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xero_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('xero_settings', 'auto_sync')) {
                $table->boolean('auto_sync')->default(false)->after('payment_account_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('xero_settings', function (Blueprint $table) {
            if (Schema::hasColumn('xero_settings', 'auto_sync')) {
                $table->dropColumn('auto_sync');
            }
        });
    }
};
