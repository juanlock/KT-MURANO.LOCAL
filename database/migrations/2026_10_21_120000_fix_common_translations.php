<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Common messages with wrong texts (2026-10-21): "InvalidData" said "Fecha
 * inválida" (invalid DATE) for any error, "Created in successfully" /
 * "Creado en con éxito"... Fixed by key, only while they still hold the old
 * wrong text (a company's own customization is kept). Safe to run twice.
 */
return new class extends Migration
{
    /** key => [[old en, old es], [new en, new es]] */
    private const FIX = [
        'InvalidData' => [['Something went wrong', 'Fecha inválida'], ['Something went wrong', 'Algo salió mal']],
        'Created_in_successfully' => [['Created in successfully', 'Creado en con éxito'], ['Created successfully', 'Creado correctamente']],
        'Deleted_in_successfully' => [['Deleted in successfully', 'Eliminado en con éxito'], ['Deleted successfully', 'Eliminado correctamente']],
        'Updated_in_successfully' => [['Updated in successfully', 'Actualizado en con éxito'], ['Updated successfully', 'Actualizado correctamente']],
        'Field_is_required' => [['This Field is required', 'Se requiere campo'], ['This field is required', 'Este campo es obligatorio']],
        'Failed' => [['Failed', 'Fallida'], ['Failed', 'Falló']],
        'submit' => [['Submit', 'Enviar'], ['Submit', 'Guardar']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        foreach (self::FIX as $key => [[$oldEn, $oldEs], [$newEn, $newEs]]) {
            DB::table('translations')->where('key', $key)->where('en', $oldEn)->update(['en' => $newEn, 'updated_at' => now()]);
            DB::table('translations')->where('key', $key)->where('es', $oldEs)->update(['es' => $newEs, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
