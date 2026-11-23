<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * "Cuestionario Nro. 1 - NOV 2026" (public key tcpreguntas2026) is KcolTech's questionnaire to Turismo Cautivo, not one of
 * Turismo's own forms (user, 2026-11-22: "quita el Cuestionario... no debe estar parte de aquí"): it moves to KcolTech, so
 * Turismo's Forms no longer list it and its public link keeps working. By key and names, not ids. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kt = DB::table('companies')->where('name', 'KcolTech')->value('id');
        $tc = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $kt || ! $tc) {
            return;
        }
        $form = DB::table('forms')->where('public_key', 'tcpreguntas2026')->where('company_id', $tc)->first(['id']);
        if (! $form) {
            return;
        }
        DB::transaction(function () use ($form, $kt) {
            DB::table('forms')->where('id', $form->id)->update(['company_id' => $kt, 'updated_at' => now()]);
            DB::table('form_submissions')->where('form_id', $form->id)->update(['company_id' => $kt]);
        });
    }

    public function down(): void
    {
        $tc = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if ($tc) {
            $id = DB::table('forms')->where('public_key', 'tcpreguntas2026')->value('id');
            DB::table('forms')->where('id', $id)->update(['company_id' => $tc]);
            DB::table('form_submissions')->where('form_id', $id)->update(['company_id' => $tc]);
        }
    }
};
