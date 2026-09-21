<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global master switch for the Wholesale Pricing by Quantity module
 * (System Settings → Features). Defaults to false so existing installs keep
 * selling at the plain retail price until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_wholesale_pricing')) {
                $table->boolean('enable_wholesale_pricing')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'enable_wholesale_pricing')) {
                $table->dropColumn('enable_wholesale_pricing');
            }
        });
    }
};
