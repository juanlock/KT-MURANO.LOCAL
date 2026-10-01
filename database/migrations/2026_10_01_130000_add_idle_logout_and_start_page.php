<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> Users:
 *  - idle_logout_minutes: sign users out after this many minutes without
 *    activity (null = off), per company; skipped while the user has a POS
 *    register open.
 *  - start_page_mode: what users see after login -- 'dashboard' (the company
 *    dashboard) or 'custom' (each user: "Last used" shortcuts, or a form that
 *    opens automatically).
 *  - dashboard_hidden_sections: JSON list of dashboard cards / charts /
 *    tables switched off for the company.
 * users.start_preferences: the user's own choice + usage counts
 * ({mode: recent|form, form: '/path', recent: {'/path': {c, t}}}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'idle_logout_minutes')) {
                $table->unsignedSmallInteger('idle_logout_minutes')->nullable();
            }
            if (! Schema::hasColumn('settings', 'start_page_mode')) {
                $table->string('start_page_mode', 20)->default('dashboard');
            }
            if (! Schema::hasColumn('settings', 'dashboard_hidden_sections')) {
                $table->text('dashboard_hidden_sections')->nullable();
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'start_preferences')) {
                $table->text('start_preferences')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['idle_logout_minutes', 'start_page_mode', 'dashboard_hidden_sections']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('start_preferences');
        });
    }
};
