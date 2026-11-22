<?php

namespace App\Support;

/**
 * The four pillars of every project (user's rule, 2026-11-22): Requerimiento, Análisis y Diseño, Implementación,
 * Puesta en Marcha. A project's stages are these four (tests go inside Implementación; support / closing continues
 * Puesta en Marcha); a new project starts with them and a template's tasks fall into them.
 */
class ProjectPillars
{
    public const REQ = 'Requerimiento';

    public const DESIGN = 'Análisis y Diseño';

    public const BUILD = 'Implementación';

    public const LAUNCH = 'Puesta en Marcha';

    public const ALL = [self::REQ, self::DESIGN, self::BUILD, self::LAUNCH];

    public const DESCRIPTIONS = [
        self::REQ => 'Qué necesita el cliente: reuniones, levantamiento de información y alcance acordado.',
        self::DESIGN => 'Cómo lo vamos a resolver: análisis, diseño de la solución, reglas y prototipos.',
        self::BUILD => 'Construirlo y probarlo: desarrollo, configuración, carga de datos y pruebas.',
        self::LAUNCH => 'Ponerlo en uso: capacitación, migración final, marcha blanca, cierre y soporte.',
    ];

    /** The pillar's stage of a project (created when missing), by name. */
    public static function stageId(int $projectId, string $pillar, ?int $userId = null): int
    {
        $id = \App\Models\ProjectMilestone::where('project_id', $projectId)->whereNull('deleted_at')->where('title', $pillar)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) \App\Models\ProjectMilestone::create([
            'project_id' => $projectId, 'title' => $pillar, 'description' => self::DESCRIPTIONS[$pillar] ?? null,
            'status' => 'pending', 'progress' => 0, 'position' => array_search($pillar, self::ALL, true) + 1, 'created_by' => $userId,
        ])->id;
    }

    /** The four stages of a new project. */
    public static function seed(int $projectId, ?int $userId = null): void
    {
        foreach (self::ALL as $p) {
            self::stageId($projectId, $p, $userId);
        }
    }

    /**
     * A template's stages regrouped into the four pillars. $map: old stage title => pillar; $taskMap: task title =>
     * pillar (an exception inside a stage). Each task keeps its absolute day; the pillar starts at its first task.
     */
    public static function regroup(array $stages, array $map, array $taskMap = [], array $extra = []): array
    {
        $buckets = array_fill_keys(self::ALL, []);
        foreach ($stages as $s) {
            $pillar = $map[$s['title']] ?? self::BUILD;
            foreach ($s['tasks'] ?? [] as $t) {
                $p = $taskMap[$t[0]] ?? $pillar;
                $buckets[$p][] = [$t[0], $s['start'] + $t[1], $t[2], $t[3], $t[4]];
            }
        }
        foreach ($extra as $p => $tasks) {
            foreach ($tasks as $t) {
                $buckets[$p][] = $t;
            }
        }
        $out = [];
        foreach (self::ALL as $p) {
            $tasks = $buckets[$p];
            if (! $tasks) {
                continue;
            }
            usort($tasks, fn ($a, $b) => $a[1] <=> $b[1]);
            $start = min(array_column($tasks, 1));
            $end = max(array_map(fn ($t) => $t[1] + max(1, $t[2]), $tasks));
            $out[] = ['title' => $p, 'start' => $start, 'days' => $end - $start,
                'tasks' => array_map(fn ($t) => [$t[0], $t[1] - $start, $t[2], $t[3], $t[4]], $tasks)];
        }

        return $out;
    }
}
