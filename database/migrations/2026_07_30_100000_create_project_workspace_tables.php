<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects Management additions: milestones and time logs.
     *
     * The existing projects/tasks tables are untouched — these two sit beside
     * them and answer the questions the module could not: "where are we against
     * plan?" (milestones) and "what has this cost us?" (time logs).
     *
     * A time log may hang off a project alone or a specific task; hours are the
     * only required figure, so a team can track effort before anyone decides
     * what it bills at.
     *
     * No DB-level foreign keys, matching the convention in this codebase.
     */
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('project_id')->index();
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->date('completed_on')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'delayed'])
                ->default('pending')->index();
            // Manual 0-100; a milestone is a judgement call, not a task count.
            $table->unsignedTinyInteger('progress')->default(0);
            $table->decimal('budget', 14, 2)->nullable();
            // Display order within the project's plan.
            $table->unsignedSmallInteger('position')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('project_time_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('project_id')->index();
            // Null = time booked to the project generally, not one task.
            $table->integer('task_id')->nullable()->index();
            $table->integer('employee_id')->nullable()->index();
            $table->date('log_date')->index();
            $table->decimal('hours', 8, 2)->default(0);
            $table->boolean('billable')->default(true)->index();
            $table->decimal('hourly_rate', 12, 2)->nullable();
            // Recomputed on save from hours x rate; never trusted from the client.
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_time_logs');
        Schema::dropIfExists('project_milestones');
    }
};
