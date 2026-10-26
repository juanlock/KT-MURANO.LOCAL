<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * HRM -> Employees in Spanish (2026-10-26): "Department" had no translation and a few
 * Spanish texts were wrong (no accents, odd wording). Each Spanish text is replaced only
 * while it is still the old one (or empty) -- an install that already corrected it keeps
 * its own. Safe to run twice.
 */
return new class extends Migration
{
    /** key => [English, Spanish, [old Spanish texts that may be replaced]] */
    private const TEXTS = [
        'Department' => ['Department', 'Departamento', []],
        'Designation' => ['Designation', 'Cargo', ['Designacion', 'Designación']],
        'Choose_Designation' => ['Choose Designation', 'Elija el cargo', ['Elija Designación', 'Elija Designacion']],
        'joining_date' => ['Joining date', 'Fecha de ingreso', ['Dia de ingreso', 'Día de ingreso']],
        'bank_account' => ['bank account', 'Cuentas bancarias', ['cuenta bancaria']],
        'Social_Media' => ['Social Media', 'Redes sociales', ['Medios de comunicación social']],
        'Remaining_leave' => ['Remaining leave', 'Días de vacaciones restantes', ['Licencia restante']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations') || ! Schema::hasColumn('translations', 'es')) {
            return;
        }
        foreach (self::TEXTS as $key => [$en, $es, $old]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
            if (! $row) {
                continue;
            }
            $set = [];
            if (trim((string) $row->es) === '' || trim((string) $row->es) === $key || in_array(trim((string) $row->es), $old, true)) {
                $set['es'] = $es;
            }
            if (trim((string) $row->en) === '' || trim((string) $row->en) === $key) {
                $set['en'] = $en; // ensure() leaves English = the key
            }
            if ($set) {
                DB::table('translations')->where('id', $row->id)->update($set + ['updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
