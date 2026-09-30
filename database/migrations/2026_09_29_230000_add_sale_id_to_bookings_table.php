<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking -> Sale link: a completed booking gets one sale invoice
 * (App\Services\BookingSaleService). sale_id is the idempotency key, so a
 * booking is never invoiced twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bookings', 'sale_id')) {
            return;
        }
        Schema::table('bookings', function (Blueprint $table) {
            $table->integer('sale_id')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bookings', 'sale_id')) {
            return;
        }
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropColumn('sale_id');
        });
    }
};
