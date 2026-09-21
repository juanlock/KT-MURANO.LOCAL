<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-client portal language, so the choice made in the portal's language
     * switcher follows the client across devices. NULL means "not chosen yet"
     * (SetPortalLocale then falls back to cookie / app default).
     */
    public function up(): void
    {
        Schema::table('portal_clients', function (Blueprint $table) {
            if (! Schema::hasColumn('portal_clients', 'preferred_locale')) {
                $table->string('preferred_locale', 5)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('portal_clients', function (Blueprint $table) {
            if (Schema::hasColumn('portal_clients', 'preferred_locale')) {
                $table->dropColumn('preferred_locale');
            }
        });
    }
};
