<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * settings.public_base_url (2026-10-07): the company's own public address for
 * links given to customers (booking codes: iframe / link / button), e.g.
 * https://book.mysalon.com. Empty = the server address (APP_URL, System
 * Settings -> General -> Server address, SuperAdmin) -- App\Support\PublicUrl.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'public_base_url')) {
            Schema::table('settings', fn (Blueprint $t) => $t->string('public_base_url', 190)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'public_base_url')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('public_base_url'));
        }
    }
};
