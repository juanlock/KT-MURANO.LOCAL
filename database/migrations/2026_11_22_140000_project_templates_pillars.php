<?php

use App\Support\ProjectPillars as P;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The standard project templates regrouped into the four pillars (user's rule, 2026-11-22): Requerimiento, Análisis y
 * Diseño, Implementación, Puesta en Marcha. Each task keeps its day and role. Safe to run twice (a template already in
 * the four pillars is left alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        $R = P::REQ; $D = P::DESIGN; $I = P::BUILD; $L = P::LAUNCH;
        $plans = [
            'system_from_scratch' => [['Inicio y levantamiento' => $R, 'Análisis y diseño' => $D, 'Desarrollo' => $I, 'Pruebas' => $I,
                'Datos y capacitación' => $L, 'Puesta en marcha' => $L], ['Carga inicial de datos' => $I], []],
            'language_migration' => [['Diagnóstico del sistema actual' => $R, 'Diseño de la nueva arquitectura' => $D, 'Migración del código' => $I,
                'Migración de datos' => $I, 'Pruebas comparativas' => $I, 'Corte y estabilización' => $L], [], []],
            'new_office' => [['Planificación' => $R, 'Local y acondicionamiento' => $I, 'Tecnología' => $I, 'Personal' => $I, 'Apertura' => $L],
                ['Plan de compras' => $D],
                [$D => [['Diseño de la distribución del local (layout) y del mobiliario', 6, 6, 'Administración', 10],
                    ['Diseño de la red, puntos de corriente y cámaras', 7, 5, 'Infraestructura', 8]]]],
            'hiring' => [['Definición del puesto' => $R, 'Convocatoria' => $D, 'Selección' => $D, 'Contratación' => $I, 'Incorporación' => $L], [], []],
            'security_audit' => [['Alcance' => $R, 'Evaluación' => $D, 'Informe' => $D, 'Remediación y verificación' => $I],
                ['Verificación final' => $L], [$L => [['Entrega del informe final y plan de mejora continua', 45, 2, 'Jefe de proyecto', 4]]]],
            'cloud_migration' => [['Evaluación' => $R, 'Diseño' => $D, 'Preparación' => $I, 'Migración' => $I, 'Validación y cierre' => $L], [], []],
            'digital_assessment' => [['Levantamiento' => $R, 'Análisis' => $D, 'Hoja de ruta' => $I],
                ['Presentación de resultados' => $L, 'Aprobación del cliente' => $L], []],
            'helpdesk_setup' => [['Diseño del servicio' => $R, 'Herramienta' => $I, 'Puesta en marcha' => $L, 'Seguimiento' => $L],
                ['Flujos de atención y escalamiento' => $D], []],
        ];
        foreach ($plans as $code => [$map, $taskMap, $extra]) {
            $t = DB::table('project_templates')->whereNull('company_id')->where('code', $code)->first();
            if (! $t) {
                continue;
            }
            $data = json_decode($t->data, true) ?: [];
            $titles = array_column($data['stages'] ?? [], 'title');
            if (! array_diff($titles, P::ALL)) {
                continue; // already in the four pillars
            }
            $data['stages'] = P::regroup($data['stages'] ?? [], $map, $taskMap, $extra);
            foreach ($extra as $tasks) {
                foreach ($tasks as $x) {
                    if ($x[3] && ! in_array($x[3], $data['roles'] ?? [], true)) {
                        $data['roles'][] = $x[3];
                    }
                }
            }
            $days = 0;
            foreach ($data['stages'] as $s) {
                $days = max($days, $s['start'] + $s['days']);
            }
            DB::table('project_templates')->where('id', $t->id)->update([
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'duration_days' => $days, 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The earlier stage names are not restored (2026_11_21_210000 can be re-run to rebuild them).
    }
};
