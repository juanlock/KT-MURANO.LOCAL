<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A project's configuration (user, 2026-11-22): its links (shared folder, meeting room, the client's system...). Safe to run twice. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'settings')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->text('settings')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'settings')) {
            Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('settings'));
        }
    }
};
