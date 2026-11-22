<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (user, 2026-11-21, KcolTech's project "Turismo Cautivo 2026"):
 * - "Hitos" are the project's stages ("Etapas del proyecto"): each task belongs to one (tasks.milestone_id).
 * - Members: the people involved, from the project's company or from the client's own Murano company
 *   (projects.client_company_id, set by a SuperAdmin), each with a role on the project; one is the project manager
 *   ("Jefe de proyecto", employee_project.is_manager) -- a new task is assigned to them by default.
 * - Documents: files / images attached to the tasks while they are worked on (project_documents, private files in
 *   files/company_{id}/projects/).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'milestone_id')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->unsignedBigInteger('milestone_id')->nullable()->after('project_id')->index();
            });
        }
        if (! Schema::hasColumn('projects', 'client_company_id')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->integer('client_company_id')->nullable()->after('client_id');
            });
        }
        if (! Schema::hasColumn('employee_project', 'role')) {
            Schema::table('employee_project', function (Blueprint $t) {
                $t->string('role', 80)->nullable();
                $t->boolean('is_manager')->default(false);
            });
        }
        if (! Schema::hasTable('project_documents')) {
            Schema::create('project_documents', function (Blueprint $t) {
                $t->id();
                $t->integer('company_id')->index();
                $t->integer('project_id')->index();
                $t->integer('task_id')->nullable()->index();
                $t->string('name', 255);
                $t->string('path', 500);
                $t->string('mime', 120)->nullable();
                $t->unsignedBigInteger('size')->default(0);
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_documents');
        if (Schema::hasColumn('employee_project', 'role')) {
            Schema::table('employee_project', fn (Blueprint $t) => $t->dropColumn(['role', 'is_manager']));
        }
        if (Schema::hasColumn('projects', 'client_company_id')) {
            Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('client_company_id'));
        }
        if (Schema::hasColumn('tasks', 'milestone_id')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('milestone_id'));
        }
    }
};
