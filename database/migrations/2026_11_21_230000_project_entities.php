<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectives grouped by the entity they relate to (user, 2026-11-21): Jelaf (online ticket sales provider), Dirección,
 * Operaciones, Administración... Each project has its own entities (project_entities); an objective belongs to one
 * (project_objectives.entity_id). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_entities')) {
            Schema::create('project_entities', function (Blueprint $t) {
                $t->id();
                $t->integer('company_id')->index();
                $t->integer('project_id')->index();
                $t->string('name', 150);
                $t->string('description', 500)->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasColumn('project_objectives', 'entity_id')) {
            Schema::table('project_objectives', function (Blueprint $t) {
                $t->unsignedBigInteger('entity_id')->nullable()->after('project_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_objectives', 'entity_id')) {
            Schema::table('project_objectives', fn (Blueprint $t) => $t->dropColumn('entity_id'));
        }
        Schema::dropIfExists('project_entities');
    }
};
