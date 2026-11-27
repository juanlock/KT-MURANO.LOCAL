<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The traveller of each ticket (2026-11-26): Jelaf's "Reporte de Pasajeros Frecuentes" gives per ticket the document (DNI,
 * carnet de extranjería, pasaporte), name, phone and email -- transport_ticket_docs, one row per ticket (SERIE-NUMERO as
 * Control de Embarque writes it). The passenger base (transport_passengers) is then built by DNI, and gets the email.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_ticket_docs')) {
            Schema::create('transport_ticket_docs', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->string('ticket', 30);                 // B100-00138352
                $t->date('travel_date')->nullable();
                $t->date('sold_date')->nullable();
                $t->string('origin', 40)->nullable();
                $t->string('destination', 40)->nullable();
                $t->string('boarding_point', 40)->nullable();
                $t->string('doc_type', 20)->nullable();   // DNI, C.EXTRANJERIA, PASAP....
                $t->string('doc', 20)->nullable();
                $t->string('name', 150)->nullable();
                $t->string('phone', 20)->nullable();
                $t->string('email', 120)->nullable();
                $t->string('channel', 40)->nullable();
                $t->string('manifest', 30)->nullable();
                $t->string('seat', 10)->nullable();
                $t->decimal('total', 10, 2)->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'ticket']);
                $t->index(['company_id', 'doc']);
                $t->index(['company_id', 'travel_date']);
            });
        }
        if (Schema::hasTable('transport_passengers') && ! Schema::hasColumn('transport_passengers', 'email')) {
            Schema::table('transport_passengers', function (Blueprint $t) {
                $t->string('email', 120)->nullable()->after('phones');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_ticket_docs');
        if (Schema::hasColumn('transport_passengers', 'email')) {
            Schema::table('transport_passengers', fn (Blueprint $t) => $t->dropColumn('email'));
        }
    }
};
