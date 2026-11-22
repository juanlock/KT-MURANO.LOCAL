<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A B2B client's contacts -- several people per client, as in Hordex's earlier system (contactos: name, area, phone,
 * email; 2026-11-21: "arregla contactos para que soporte más de 1"). clients.contact_name keeps the first one for the
 * screens that read a single contact. Own id for the dev sync. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('b2b_client_contacts')) {
            Schema::create('b2b_client_contacts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('name', 190);
                $t->string('area', 120)->nullable();
                $t->string('phone', 60)->nullable();
                $t->string('email', 190)->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_client_contacts');
    }
};
