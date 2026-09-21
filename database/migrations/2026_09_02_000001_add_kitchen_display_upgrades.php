<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kitchen Display upgrades:
     * - kitchen_orders.token_number: short daily call number shown big on the ticket
     *   (resets each day; assigned when the ticket is created).
     * - kitchen_orders.source: where the ticket came from ('pos' | 'online' | 'manual'),
     *   display-only, so the board can badge online orders.
     * - settings.kitchen_target_minutes: prep-time target driving the overdue color
     *   escalation on the board (null = no escalation).
     * - settings.kitchen_auto_online_orders: when on, confirming an online order
     *   also creates a kitchen ticket for the generated sale.
     */
    public function up(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('kitchen_orders', 'token_number')) {
                $table->unsignedInteger('token_number')->nullable()->after('ref');
            }
            if (! Schema::hasColumn('kitchen_orders', 'source')) {
                $table->string('source', 24)->default('pos')->after('token_number');
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'kitchen_target_minutes')) {
                $table->unsignedSmallInteger('kitchen_target_minutes')->nullable();
            }
            if (! Schema::hasColumn('settings', 'kitchen_auto_online_orders')) {
                $table->boolean('kitchen_auto_online_orders')->default(false);
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropColumn(['token_number', 'source']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['kitchen_target_minutes', 'kitchen_auto_online_orders']);
        });
    }
};
