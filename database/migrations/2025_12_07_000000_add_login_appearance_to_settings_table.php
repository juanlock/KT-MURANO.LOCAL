<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // NO string defaults. These are optional admin overrides: left NULL,
        // the login page falls through to tdb('Login_hero_title', ...) etc.,
        // which is translated into every shipped locale. Baking English in as a
        // column default made a fresh install permanently English no matter the
        // company language, since the override was always "set".
        // The later 2026_03_16 migration (badge, features, button, footer)
        // already gets this right — these four now match it.
        Schema::table('settings', function (Blueprint $table) {
            $table->string('login_hero_title')
                ->nullable()
                ->after('page_title_suffix');

            $table->string('login_hero_subtitle')
                ->nullable()
                ->after('login_hero_title');

            $table->string('login_panel_title')
                ->nullable()
                ->after('login_hero_subtitle');

            $table->string('login_panel_subtitle')
                ->nullable()
                ->after('login_panel_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'login_hero_title',
                'login_hero_subtitle',
                'login_panel_title',
                'login_panel_subtitle',
            ]);
        });
    }
};















