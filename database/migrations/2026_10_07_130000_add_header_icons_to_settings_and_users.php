<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header icons on / off (2026-10-07), see App\Support\HeaderIcons:
 *  - settings.header_icons: JSON list of the icons hidden for the whole company
 *    (Defaults -> Users), except pos / customize / language which keep their
 *    older columns;
 *  - users.header_icons: JSON {icon: true|false}, the user's own choices
 *    (Profile); missing = follow the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'header_icons')) {
            Schema::table('settings', fn (Blueprint $t) => $t->text('header_icons')->nullable());
        }
        if (! Schema::hasColumn('users', 'header_icons')) {
            Schema::table('users', fn (Blueprint $t) => $t->text('header_icons')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'header_icons')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('header_icons'));
        }
        if (Schema::hasColumn('users', 'header_icons')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('header_icons'));
        }
    }
};
