<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for self-service portal account closures. The portal_clients
     * row is removed outright (it is only a login credential), so this table is
     * what records that the customer asked for it and when. The Client record
     * and every sale / invoice / contract stay untouched.
     */
    public function up(): void
    {
        if (Schema::hasTable('portal_account_deletions')) {
            return;
        }

        Schema::create('portal_account_deletions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('client_id');
            $table->string('email', 190);
            $table->string('client_name', 190)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_account_deletions');
    }
};
