<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Sheets integration:
 *  - google_sheet_settings: singleton row — per-tenant Google OAuth client
 *    (each tenant brings their own Google Cloud project, like QuickBooks/
 *    Xero/Salla bring their own apps), the token pair, and the target
 *    spreadsheet id.
 *  - google_sheet_logs: export audit trail, same shape as salla_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('google_sheet_settings')) {
            Schema::create('google_sheet_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                $table->string('client_id', 191)->nullable();
                $table->text('client_secret')->nullable();
                $table->longText('access_token')->nullable();
                // Google web-app refresh tokens do not rotate; they die only on
                // revocation (or after 7 days while the consent screen is in
                // "Testing" status).
                $table->longText('refresh_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                // Target spreadsheet (created by us or pasted by the admin).
                $table->string('spreadsheet_id', 128)->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('google_sheet_logs')) {
            Schema::create('google_sheet_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action', 100);
                $table->string('level', 20)->default('info'); // info|warning|error
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps(6);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheet_logs');
        Schema::dropIfExists('google_sheet_settings');
    }
};
