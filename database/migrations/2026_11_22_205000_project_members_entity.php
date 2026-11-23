<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects -> Members (user, 2026-11-22: "indica cuál es su entidad... agregar entidad debe estar dentro de miembros"):
 * each member can belong to one of the project's entities (Jelaf, Operaciones, Administración...).
 * employee_project.entity_id -> project_entities.id. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_project', 'entity_id')) {
            Schema::table('employee_project', fn (Blueprint $t) => $t->unsignedBigInteger('entity_id')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_project', 'entity_id')) {
            Schema::table('employee_project', fn (Blueprint $t) => $t->dropColumn('entity_id'));
        }
    }
};
