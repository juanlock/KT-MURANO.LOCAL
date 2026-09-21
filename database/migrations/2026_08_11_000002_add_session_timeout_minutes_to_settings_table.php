<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable session timeout (System Settings → Security). NULL means
 * "never expire" — the Security tab's control was previously a dead switch
 * (nothing persisted or enforced it); SetSessionConfig middleware now applies
 * this value to session.lifetime, which also drives the Passport cookie
 * expiry used by the SPA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'session_timeout_minutes')) {
                $table->unsignedInteger('session_timeout_minutes')->nullable()->default(null);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'session_timeout_minutes')) {
                $table->dropColumn('session_timeout_minutes');
            }
        });
    }
};
