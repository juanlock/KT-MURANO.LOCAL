<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each objective belongs to one pillar (user, 2026-11-22: the numbers of a pillar must run consecutively): an objective
 * already in a project takes the pillar where most of its tasks are (the earlier pillar on a tie). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_objectives', 'pillar')) {
            Schema::table('project_objectives', function (Blueprint $t) {
                $t->string('pillar', 40)->nullable()->after('entity_id');
            });
        }
        $pillars = ['Requerimiento', 'Análisis y Diseño', 'Implementación', 'Puesta en Marcha'];
        foreach (DB::table('project_objectives')->whereNull('pillar')->whereNull('deleted_at')->get(['id']) as $o) {
            $counts = DB::table('tasks as t')->join('project_milestones as m', 'm.id', '=', 't.milestone_id')
                ->where('t.objective_id', $o->id)->whereNull('t.deleted_at')->whereIn('m.title', $pillars)
                ->selectRaw('m.title, count(*) n')->groupBy('m.title')->pluck('n', 'title');
            if ($counts->isEmpty()) {
                continue;
            }
            $best = null;
            foreach ($pillars as $p) {
                if (($counts[$p] ?? 0) > ($best ? $counts[$best] : 0)) {
                    $best = $p;
                }
            }
            DB::table('project_objectives')->where('id', $o->id)->update(['pillar' => $best]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_objectives', 'pillar')) {
            Schema::table('project_objectives', fn (Blueprint $t) => $t->dropColumn('pillar'));
        }
    }
};
