<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (user, 2026-11-21):
 * - Objectives of the project ("Descripción y objetivos"): each objective is tied to tasks (tasks.objective_id);
 *   creating an objective creates its task and assigns it.
 * - A project may have no end date (a service that becomes a monthly support contract).
 * - Documents can be a link (a Zoom recording, a shared folder) instead of a file: project_documents.url.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_objectives')) {
            Schema::create('project_objectives', function (Blueprint $t) {
                $t->id();
                $t->integer('company_id')->index();
                $t->integer('project_id')->index();
                $t->string('title', 255);
                $t->text('description')->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasColumn('tasks', 'objective_id')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->unsignedBigInteger('objective_id')->nullable()->after('milestone_id')->index();
            });
        }
        if (! Schema::hasColumn('project_documents', 'url')) {
            Schema::table('project_documents', function (Blueprint $t) {
                $t->string('url', 1000)->nullable()->after('path');
            });
            DB::statement('ALTER TABLE project_documents MODIFY path VARCHAR(500) NULL');
        }
        DB::statement('ALTER TABLE projects MODIFY end_date DATE NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_objectives');
        if (Schema::hasColumn('tasks', 'objective_id')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('objective_id'));
        }
        if (Schema::hasColumn('project_documents', 'url')) {
            Schema::table('project_documents', fn (Blueprint $t) => $t->dropColumn('url'));
        }
    }
};
