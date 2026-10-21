<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spanish for the School Management menu and its page (browser tab) titles
 * (Colegio Bicentenario, 2026-10-20). The key is the English text, as the
 * sidebar and lib/docTitle.js look them up. By key, never by id: a missing key
 * is inserted; an existing one gets its Spanish only when the cell is empty
 * or still the English text. Safe to run twice; a hand-edited translation is
 * never overwritten.
 */
return new class extends Migration
{
    private const TEXTS = [
        // Side menu (resources/src/config/menu.js)
        'School Management' => 'Gestión escolar',
        'Students' => 'Alumnos',
        'Enrolment' => 'Matriculación',
        'Attendance' => 'Asistencia',
        'Timetable' => 'Horario',
        'Exams & Results' => 'Exámenes y resultados',
        'Fees' => 'Cuotas',
        'Teachers' => 'Profesores',
        'Academic Setup' => 'Configuración académica',
        'School Reports' => 'Informes escolares',
        // Route titles (resources/src/router/index.js, school/*)
        'School Dashboard' => 'Panel escolar',
        'Admit Student' => 'Admitir alumno',
        'Edit Student' => 'Editar alumno',
        'Student Record' => 'Expediente del alumno',
        'School Fees' => 'Cuotas escolares',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations') || ! Schema::hasColumn('translations', 'es')) {
            return;
        }

        foreach (self::TEXTS as $key => $es) {
            // Insert when missing / fill an empty Spanish cell.
            TranslationKeys::ensure($key, ['es' => $es]);

            // Spanish still equal to the English text = never translated.
            $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
            if ($row && trim((string) $row->es) === trim((string) ($row->en ?: $key))) {
                $customized = array_values(array_unique(array_merge(array_filter(explode(',', (string) $row->customized_locales)), ['es'])));
                DB::table('translations')->where('id', $row->id)->update([
                    'es' => $es,
                    'customized_locales' => implode(',', $customized),
                    'updated_at' => now(),
                ]);
            }
        }

        if (function_exists('forget_db_translations')) {
            forget_db_translations('es');
        }
    }

    public function down(): void
    {
        // Translations are kept (other screens may use the same keys).
    }
};
