<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project templates (user, 2026-11-21): a type of project brings its stages, tasks, the role that does each task and
 * approximate dates (days from the project's start). Applying one to a project creates its stages and tasks and assigns
 * each task to the member who has that role on the project (else the project manager).
 * company_id null = standard templates of the install (these eight); a company's own ones are saved from a project
 * ("Guardar como plantilla"). Seeded by code, safe to run twice.
 *
 * data = {roles: [..], stages: [{title, start, days, tasks: [[title, start in the stage, days, role, hours], ..]}]}
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_templates')) {
            Schema::create('project_templates', function (Blueprint $t) {
                $t->id();
                $t->integer('company_id')->nullable()->index();
                $t->string('code', 60)->nullable();
                $t->string('name', 191);
                $t->string('name_en', 191)->nullable();
                $t->string('description', 500)->nullable();
                $t->unsignedInteger('duration_days')->default(0);
                $t->longText('data');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        $JP = 'Jefe de proyecto';
        foreach ($this->templates($JP) as $code => $tpl) {
            $days = 0;
            foreach ($tpl['stages'] as $s) {
                $days = max($days, $s['start'] + $s['days']);
            }
            $row = [
                'name' => $tpl['name'], 'name_en' => $tpl['name_en'], 'description' => $tpl['description'],
                'duration_days' => $days,
                'data' => json_encode(['roles' => $tpl['roles'], 'stages' => $tpl['stages']], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ];
            $id = DB::table('project_templates')->whereNull('company_id')->where('code', $code)->value('id');
            if ($id) {
                DB::table('project_templates')->where('id', $id)->update($row);
            } else {
                DB::table('project_templates')->insert($row + ['code' => $code, 'company_id' => null, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_templates');
    }

    private function st(string $title, int $start, int $days, array $tasks): array
    {
        return ['title' => $title, 'start' => $start, 'days' => $days, 'tasks' => $tasks];
    }

    private function templates(string $JP): array
    {
        $AF = 'Analista funcional'; $DEV = 'Desarrollador'; $UX = 'Diseñador UX'; $QA = 'QA / Pruebas';
        $INF = 'Infraestructura'; $UC = 'Usuario clave (cliente)'; $CAP = 'Capacitador'; $ARQ = 'Arquitecto';
        $DBA = 'Base de datos (DBA)'; $ADM = 'Administración'; $ST = 'Soporte TI'; $RH = 'RR.HH.';
        $LEG = 'Legal / contable'; $JA = 'Jefe del área solicitante'; $AUD = 'Auditor de seguridad';
        $CONS = 'Consultor'; $AS = 'Analista de soporte'; $ARC = 'Arquitecto cloud';

        return [
            'system_from_scratch' => [
                'name' => 'Implementación de un sistema desde cero',
                'name_en' => 'Implementing a system from scratch',
                'description' => 'Del levantamiento de requerimientos a la puesta en marcha (~4 meses).',
                'roles' => [$JP, $AF, $UX, $DEV, $QA, $INF, $CAP, $UC],
                'stages' => [
                    $this->st('Inicio y levantamiento', 0, 15, [
                        ['Reunión de arranque', 0, 1, $JP, 4],
                        ['Entrevistas con las áreas usuarias', 1, 7, $AF, 24],
                        ['Documento de requerimientos', 6, 6, $AF, 16],
                        ['Aprobación del alcance por el cliente', 12, 3, $UC, 2],
                    ]),
                    $this->st('Análisis y diseño', 15, 20, [
                        ['Modelo de datos', 0, 7, $DEV, 24],
                        ['Prototipos de pantallas', 0, 10, $UX, 32],
                        ['Arquitectura e infraestructura', 5, 10, $INF, 16],
                        ['Validación de prototipos con los usuarios', 10, 4, $UC, 6],
                        ['Plan de trabajo detallado', 15, 5, $JP, 8],
                    ]),
                    $this->st('Desarrollo', 35, 50, [
                        ['Configuración de entornos', 0, 3, $INF, 8],
                        ['Sprint 1: módulos base', 3, 15, $DEV, 120],
                        ['Revisión de avance con el cliente', 14, 2, $JP, 4],
                        ['Sprint 2: módulos principales', 18, 15, $DEV, 120],
                        ['Sprint 3: reportes e integraciones', 33, 15, $DEV, 120],
                    ]),
                    $this->st('Pruebas', 85, 15, [
                        ['Plan y casos de prueba', 0, 4, $QA, 16],
                        ['Pruebas funcionales', 4, 7, $QA, 40],
                        ['Pruebas de aceptación del usuario (UAT)', 9, 6, $UC, 24],
                        ['Corrección de observaciones', 9, 6, $DEV, 40],
                    ]),
                    $this->st('Datos y capacitación', 100, 10, [
                        ['Carga inicial de datos', 0, 5, $DEV, 24],
                        ['Manuales de usuario', 0, 6, $CAP, 20],
                        ['Capacitación por rol', 5, 5, $CAP, 20],
                    ]),
                    $this->st('Puesta en marcha', 110, 10, [
                        ['Pase a producción', 0, 2, $INF, 8],
                        ['Marcha blanca y soporte', 2, 8, $DEV, 40],
                        ['Acta de cierre del proyecto', 8, 2, $JP, 4],
                    ]),
                ],
            ],
            'language_migration' => [
                'name' => 'Migración de un sistema a otro lenguaje',
                'name_en' => 'Migrating a system to another language',
                'description' => 'Reescribir un sistema existente en otra tecnología sin perder datos ni funciones (~3 meses y medio).',
                'roles' => [$JP, $ARQ, $DEV, $DBA, $QA, $INF, $UC],
                'stages' => [
                    $this->st('Diagnóstico del sistema actual', 0, 12, [
                        ['Inventario de módulos, pantallas y reportes', 0, 5, $ARQ, 24],
                        ['Análisis del código y sus dependencias', 2, 8, $ARQ, 32],
                        ['Análisis de la base de datos', 2, 6, $DBA, 16],
                        ['Informe de riesgos y estrategia de migración', 8, 4, $JP, 12],
                    ]),
                    $this->st('Diseño de la nueva arquitectura', 12, 12, [
                        ['Elección del stack y frameworks', 0, 4, $ARQ, 12],
                        ['Equivalencias de funciones y librerías', 2, 7, $ARQ, 24],
                        ['Mapeo de datos origen → destino', 4, 8, $DBA, 24],
                        ['Plan por fases y módulos', 9, 3, $JP, 6],
                    ]),
                    $this->st('Migración del código', 24, 45, [
                        ['Base del proyecto y entornos', 0, 4, $DEV, 16],
                        ['Fase 1: módulos núcleo', 4, 14, $DEV, 110],
                        ['Fase 2: módulos operativos', 18, 14, $DEV, 110],
                        ['Reportes e integraciones', 30, 15, $DEV, 60],
                        ['Fase 3: módulos restantes', 32, 13, $DEV, 100],
                    ]),
                    $this->st('Migración de datos', 55, 15, [
                        ['Scripts de migración', 0, 7, $DBA, 32],
                        ['Ensayos de migración y cuadre de cifras', 7, 8, $DBA, 24],
                    ]),
                    $this->st('Pruebas comparativas', 70, 15, [
                        ['Pruebas en paralelo: sistema antiguo vs nuevo', 0, 10, $QA, 48],
                        ['Pruebas de rendimiento', 5, 5, $QA, 16],
                        ['Validación del usuario', 8, 7, $UC, 20],
                    ]),
                    $this->st('Corte y estabilización', 85, 15, [
                        ['Plan de corte y vuelta atrás', 0, 3, $JP, 6],
                        ['Migración final y pase a producción', 3, 2, $INF, 12],
                        ['Soporte post-migración', 5, 10, $DEV, 40],
                        ['Apagado del sistema antiguo', 13, 2, $INF, 4],
                    ]),
                ],
            ],
            'new_office' => [
                'name' => 'Creación de una oficina',
                'name_en' => 'Setting up an office',
                'description' => 'Local, permisos, tecnología y personal hasta la apertura (~2 meses).',
                'roles' => [$JP, $ADM, $LEG, $INF, $ST, $RH],
                'stages' => [
                    $this->st('Planificación', 0, 10, [
                        ['Ubicación, aforo y presupuesto', 0, 5, $JP, 12],
                        ['Requisitos legales: licencia municipal y Defensa Civil', 2, 8, $LEG, 8],
                        ['Plan de compras', 5, 5, $ADM, 8],
                    ]),
                    $this->st('Local y acondicionamiento', 10, 25, [
                        ['Contrato de alquiler', 0, 5, $LEG, 6],
                        ['Obras, pintura y señalización', 5, 15, $ADM, 20],
                        ['Instalación eléctrica y cableado estructurado', 8, 10, $INF, 24],
                        ['Mobiliario', 10, 12, $ADM, 10],
                    ]),
                    $this->st('Tecnología', 25, 20, [
                        ['Internet y telefonía', 0, 7, $INF, 8],
                        ['Equipos de cómputo e impresoras', 3, 10, $ST, 24],
                        ['Red, wifi, firewall y cámaras', 5, 10, $INF, 24],
                        ['Correos, accesos y sistemas', 12, 6, $ST, 12],
                    ]),
                    $this->st('Personal', 30, 20, [
                        ['Definir los puestos', 0, 4, $RH, 6],
                        ['Contratación', 4, 12, $RH, 24],
                        ['Inducción', 16, 4, $RH, 8],
                    ]),
                    $this->st('Apertura', 50, 10, [
                        ['Pruebas generales', 0, 4, $ST, 8],
                        ['Inauguración', 5, 1, $JP, 4],
                        ['Seguimiento del primer mes', 6, 4, $ADM, 6],
                    ]),
                ],
            ],
            'hiring' => [
                'name' => 'Contratación de personal',
                'name_en' => 'Hiring staff',
                'description' => 'Del perfil del puesto a la incorporación del nuevo colaborador (~6 semanas).',
                'roles' => [$JP, $RH, $JA, $LEG, $ST],
                'stages' => [
                    $this->st('Definición del puesto', 0, 5, [
                        ['Requerimiento y perfil del puesto', 0, 3, $JA, 4],
                        ['Banda salarial y aprobación', 2, 3, $RH, 3],
                    ]),
                    $this->st('Convocatoria', 5, 10, [
                        ['Publicación en bolsas de trabajo y redes', 0, 2, $RH, 3],
                        ['Recepción y filtro de CV', 2, 8, $RH, 12],
                    ]),
                    $this->st('Selección', 15, 12, [
                        ['Evaluación técnica y psicológica', 0, 5, $RH, 10],
                        ['Entrevistas con el área', 3, 6, $JA, 8],
                        ['Verificación de referencias', 7, 3, $RH, 3],
                        ['Terna final y elección', 10, 2, $JA, 2],
                    ]),
                    $this->st('Contratación', 27, 6, [
                        ['Oferta y aceptación', 0, 2, $RH, 2],
                        ['Contrato y alta en planilla', 2, 4, $LEG, 4],
                    ]),
                    $this->st('Incorporación', 33, 7, [
                        ['Equipo, correo y accesos', 0, 3, $ST, 4],
                        ['Inducción', 3, 2, $RH, 4],
                        ['Seguimiento del periodo de prueba', 5, 2, $JA, 2],
                    ]),
                ],
            ],
            'security_audit' => [
                'name' => 'Auditoría de ciberseguridad',
                'name_en' => 'Cybersecurity audit',
                'description' => 'Evaluar la seguridad de la empresa, informar los riesgos y corregir los críticos (~6 semanas).',
                'roles' => [$JP, $AUD, $INF, $UC],
                'stages' => [
                    $this->st('Alcance', 0, 5, [
                        ['Reunión de arranque y acuerdo de confidencialidad', 0, 2, $JP, 4],
                        ['Inventario de activos y sistemas', 1, 4, $UC, 8],
                    ]),
                    $this->st('Evaluación', 5, 20, [
                        ['Revisión de políticas, usuarios y accesos', 0, 7, $AUD, 24],
                        ['Escaneo de vulnerabilidades', 3, 7, $AUD, 24],
                        ['Revisión de respaldos y continuidad', 5, 6, $INF, 12],
                        ['Pruebas de intrusión autorizadas', 8, 8, $AUD, 40],
                    ]),
                    $this->st('Informe', 25, 10, [
                        ['Hallazgos por nivel de riesgo', 0, 5, $AUD, 16],
                        ['Plan de remediación', 4, 4, $JP, 8],
                        ['Presentación a la gerencia', 8, 2, $JP, 4],
                    ]),
                    $this->st('Remediación y verificación', 35, 10, [
                        ['Corrección de los hallazgos críticos', 0, 7, $INF, 32],
                        ['Verificación final', 7, 3, $AUD, 8],
                    ]),
                ],
            ],
            'cloud_migration' => [
                'name' => 'Migración a la nube',
                'name_en' => 'Cloud migration',
                'description' => 'Llevar servidores, bases de datos y aplicaciones a la nube por oleadas (~2 meses).',
                'roles' => [$JP, $ARC, $INF, $DBA, $QA, $UC],
                'stages' => [
                    $this->st('Evaluación', 0, 10, [
                        ['Inventario de servidores y aplicaciones', 0, 5, $INF, 16],
                        ['Costos estimados y elección del proveedor', 3, 7, $ARC, 16],
                    ]),
                    $this->st('Diseño', 10, 10, [
                        ['Arquitectura destino: red, seguridad y respaldos', 0, 7, $ARC, 24],
                        ['Plan de migración por oleadas', 5, 5, $JP, 8],
                    ]),
                    $this->st('Preparación', 20, 10, [
                        ['Cuentas, redes y permisos en la nube', 0, 5, $INF, 16],
                        ['Monitoreo y alertas', 4, 6, $INF, 12],
                    ]),
                    $this->st('Migración', 30, 20, [
                        ['Oleada 1: servicios de prueba', 0, 6, $INF, 24],
                        ['Oleada 2: bases de datos', 5, 8, $DBA, 32],
                        ['Oleada 3: aplicaciones de producción', 12, 8, $INF, 32],
                    ]),
                    $this->st('Validación y cierre', 50, 10, [
                        ['Pruebas de funcionamiento y rendimiento', 0, 5, $QA, 16],
                        ['Validación del usuario', 3, 4, $UC, 8],
                        ['Apagado de servidores antiguos y documentación', 6, 4, $INF, 12],
                    ]),
                ],
            ],
            'digital_assessment' => [
                'name' => 'Diagnóstico de transformación digital',
                'name_en' => 'Digital transformation assessment',
                'description' => 'Cómo trabaja hoy la empresa, qué le falta y la hoja de ruta de proyectos (~1 mes).',
                'roles' => [$JP, $CONS, $AF, $UC],
                'stages' => [
                    $this->st('Levantamiento', 0, 10, [
                        ['Entrevistas con la gerencia y las áreas', 0, 7, $CONS, 24],
                        ['Mapa de procesos actuales', 3, 7, $AF, 20],
                        ['Inventario de sistemas y herramientas', 3, 5, $AF, 12],
                    ]),
                    $this->st('Análisis', 10, 10, [
                        ['Brechas y oportunidades', 0, 6, $CONS, 20],
                        ['Priorización por impacto y costo', 5, 5, $CONS, 12],
                    ]),
                    $this->st('Hoja de ruta', 20, 10, [
                        ['Proyectos recomendados y presupuesto', 0, 5, $CONS, 16],
                        ['Presentación de resultados', 6, 2, $JP, 4],
                        ['Aprobación del cliente', 8, 2, $UC, 2],
                    ]),
                ],
            ],
            'helpdesk_setup' => [
                'name' => 'Implementación de mesa de ayuda y soporte TI',
                'name_en' => 'Help desk and IT support setup',
                'description' => 'Catálogo de servicios, niveles de atención, herramienta y arranque del soporte (~6 semanas).',
                'roles' => [$JP, $AS, $INF, $CAP, $UC],
                'stages' => [
                    $this->st('Diseño del servicio', 0, 10, [
                        ['Catálogo de servicios y niveles de atención (SLA)', 0, 6, $JP, 12],
                        ['Flujos de atención y escalamiento', 4, 6, $AS, 12],
                    ]),
                    $this->st('Herramienta', 10, 15, [
                        ['Configuración del Helpdesk: categorías, prioridades y correos', 0, 8, $AS, 24],
                        ['Inventario de equipos y licencias', 3, 10, $INF, 20],
                        ['Base de conocimiento inicial', 8, 7, $AS, 16],
                    ]),
                    $this->st('Puesta en marcha', 25, 10, [
                        ['Capacitación a los técnicos', 0, 3, $CAP, 8],
                        ['Comunicación a los usuarios', 3, 2, $JP, 3],
                        ['Inicio de la atención', 5, 5, $AS, 20],
                    ]),
                    $this->st('Seguimiento', 35, 10, [
                        ['Indicadores del primer mes', 0, 8, $JP, 8],
                        ['Ajustes y mejoras', 6, 4, $AS, 8],
                    ]),
                ],
            ],
        ];
    }
};
