<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task that is a meeting (user, 2026-11-22) has its date and time, its medium (Zoom, Teams, Meet, in person, phone)
 * and the link or the place; the Outlook invitation goes with its documents. Tasks named "Reunión..." / "Meeting..."
 * are marked as meetings. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'is_meeting')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->boolean('is_meeting')->default(false)->after('reason');
                $t->dateTime('meeting_at')->nullable()->after('is_meeting');
                $t->string('meeting_medium', 30)->nullable()->after('meeting_at');
                $t->string('meeting_link', 1000)->nullable()->after('meeting_medium');
            });
        }
        DB::table('tasks')->where('is_meeting', 0)
            ->where(fn ($q) => $q->where('title', 'like', 'Reunión%')->orWhere('title', 'like', 'Reunion%')->orWhere('title', 'like', 'Meeting%'))
            ->update(['is_meeting' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'is_meeting')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['is_meeting', 'meeting_at', 'meeting_medium', 'meeting_link']));
        }
    }
};
