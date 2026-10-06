<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Service Technicians" are called "Operators" everywhere in the UI (user's
 * request, 2026-10-06) -- labels only; tables / columns (service_technicians,
 * technician_id) keep their names.
 */
return new class extends Migration
{
    private const LABELS = [
        'Service_Technicians' => [['Service Technicians', 'Técnicos de servicio'], ['Operators', 'Operadores']],
        'Technician' => [['Technician', 'Técnico'], ['Operator', 'Operador']],
        'Choose_Technician' => [['Choose Technician', 'Elegir técnico'], ['Choose Operator', 'Elegir operador']],
        'Technician_Details' => [['Technician Details', 'Detalles del técnico'], ['Operator Details', 'Detalles del operador']],
        'What_the_technician_found' => [['What the technician found', 'Lo que encontró el técnico'], ['What the operator found', 'Lo que encontró el operador']],
    ];

    public function up(): void
    {
        foreach (self::LABELS as $key => [, [$en, $es]]) {
            DB::table('translations')->where('key', $key)->update(['en' => $en, 'es' => $es, 'updated_at' => now()]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $key => [[$en, $es]]) {
            DB::table('translations')->where('key', $key)->update(['en' => $en, 'es' => $es, 'updated_at' => now()]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }
};
