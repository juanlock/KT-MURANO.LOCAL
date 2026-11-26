<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo's passenger base crossed with the tickets (user, 2026-11-26: "la idea es tener nombre, dni y teléfono y luego
 * cruzarlo con boletos, viajes, días de viaje"). The base itself is transport_passengers (2026-11-24, DNI from the
 * Liquidación Detallada). Here:
 *  - transport_online_sales: Jelaf's "Reporte de ventas web" PDFs (WEB / RED BUS, travel and purchase date, buyer's phone),
 *    every row kept once even when the files overlap; the source of the passengers' phones.
 *  - transport_boardings.name_key / passenger_id: each Control de Embarque ticket linked to its passenger by name.
 * Boards only: no selling here. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_online_sales')) {
            Schema::create('transport_online_sales', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->string('channel', 20);                   // web | redbus
                $t->date('travel_date');
                $t->date('sold_date')->nullable();
                $t->string('passenger', 150);
                $t->string('name_key', 190);
                $t->string('phone', 20)->nullable();
                $t->unsignedSmallInteger('occurrence')->default(1);
                $t->unsignedBigInteger('boarding_id')->nullable()->index();
                $t->string('source_file', 190)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['company_id', 'channel', 'travel_date', 'sold_date', 'name_key', 'phone', 'occurrence'], 'tos_unique');
                $t->index(['company_id', 'travel_date']);
            });
        }
        Schema::table('transport_boardings', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_boardings', 'name_key')) {
                $t->string('name_key', 190)->nullable()->after('passenger');
                $t->index(['company_id', 'name_key']);
            }
            if (! Schema::hasColumn('transport_boardings', 'passenger_id')) {
                $t->unsignedBigInteger('passenger_id')->nullable()->after('name_key')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transport_boardings', function (Blueprint $t) {
            if (Schema::hasColumn('transport_boardings', 'passenger_id')) {
                $t->dropColumn('passenger_id');
            }
            if (Schema::hasColumn('transport_boardings', 'name_key')) {
                $t->dropIndex(['company_id', 'name_key']);
                $t->dropColumn('name_key');
            }
        });
        Schema::dropIfExists('transport_online_sales');
    }
};
