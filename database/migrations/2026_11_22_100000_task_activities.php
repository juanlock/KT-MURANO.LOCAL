<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Acuerdos / Registro de actividades" of a task (user, 2026-11-22): what the assigned person did or agreed, with its
 * date and time and the task's progress at that moment. Adding one moves the task's progress. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_activities')) {
            Schema::create('task_activities', function (Blueprint $t) {
                $t->id();
                $t->integer('company_id')->index();
                $t->integer('project_id')->index();
                $t->integer('task_id')->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->text('note');
                $t->unsignedTinyInteger('progress')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
