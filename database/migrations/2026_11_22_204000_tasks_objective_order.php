<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects -> Objectives (user, 2026-11-22: "que el jefe de proyecto pueda cambiar el orden de las tareas, drag and drop"):
 * tasks.objective_order = the task's place inside its objective, set by dragging; null = by date as before.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'objective_order')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->unsignedInteger('objective_order')->nullable()->after('objective_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'objective_order')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('objective_order'));
        }
    }
};
