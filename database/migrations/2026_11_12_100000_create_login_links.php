<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in links (user's request, 2026-11-12): staff who don't manage passwords well (pilots, hostesses) get a
 * link by WhatsApp that signs them in without a password, on the FIRST device that opens it only
 * (App\Support\LoginLinks). One live link per login; the admin makes a new one or removes it in Company logins.
 *
 *  token_hash   sha256 of the link's code (how /acceso/{code} finds it)
 *  token_enc    the code, encrypted with APP_KEY, so the admin can copy / resend the same link
 *  device_hash  sha256 of the device key (cookie murano_login_device) of the device that claimed it; null = not used yet
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_links')) {
            return;
        }
        Schema::create('login_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('company_id')->index();
            $t->unsignedInteger('user_id')->index();
            $t->string('token_hash', 64)->unique();
            $t->text('token_enc');
            $t->string('device_hash', 64)->nullable();
            $t->string('device_name', 120)->nullable();
            $t->string('claimed_ip', 45)->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->timestamp('expires_at')->nullable();      // an unused link stops working after this
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_links');
    }
};
