<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (user, 2026-11-22):
 * - a meeting task lists its points / questions, each with its answer written beside it (tasks.meeting_points, JSON
 *   [{q, a}]);
 * - an entry of the activity log can carry the hours spent (task_activities.hours); it is also booked as a project time
 *   log (task_activities.time_log_id) so the Hours tab adds it up by project, objective and task.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'meeting_points')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->text('meeting_points')->nullable()->after('meeting_link'));
        }
        if (! Schema::hasColumn('task_activities', 'hours')) {
            Schema::table('task_activities', function (Blueprint $t) {
                $t->decimal('hours', 6, 2)->nullable()->after('progress');
                $t->unsignedBigInteger('time_log_id')->nullable()->after('hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'meeting_points')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('meeting_points'));
        }
        if (Schema::hasColumn('task_activities', 'hours')) {
            Schema::table('task_activities', fn (Blueprint $t) => $t->dropColumn(['hours', 'time_log_id']));
        }
    }
};
