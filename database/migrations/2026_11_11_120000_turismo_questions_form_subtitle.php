<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo's questions form (public key tcpreguntas2026, 2026_11_10_170000): the long introduction under the
 * title is replaced by "Cuestionario Nro. 1 - NOV 2026" (user's request, 2026-11-11). Only the description changes;
 * the rest of the form's settings stay as they are. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = 'tcpreguntas2026';

    private const TEXT = 'Cuestionario Nro. 1 - NOV 2026';

    private const OLD = 'Para dejar Murano funcionando con su operación real necesitamos confirmar algunos datos, módulo por módulo. '
        .'Responda las que pueda: puede dejar en blanco las que prefiera ver en la reunión del 15 de noviembre. '
        .'Las marcadas como (Supuesto) son cifras que pusimos nosotros para avanzar.';

    public function up(): void
    {
        $this->set(self::TEXT);
    }

    public function down(): void
    {
        $this->set(self::OLD);
    }

    private function set(string $text): void
    {
        if (! Schema::hasTable('forms')) {
            return;
        }
        $form = DB::table('forms')->where('public_key', self::KEY)->first(['id', 'settings']);
        if (! $form) {
            return;
        }
        $settings = json_decode((string) $form->settings, true) ?: [];
        $settings['description'] = $text;
        DB::table('forms')->where('id', $form->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
};
