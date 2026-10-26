<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Spanish companies (settings.default_language = es, 2026-10-26): the default names
 * Companies -> Add and the HRM demo used to create in English, renamed to Spanish --
 * office shifts, departments, designations, the main warehouse and the demo leave
 * reasons. Only the exact default texts (anything a company typed itself stays);
 * safe to run twice.
 */
return new class extends Migration
{
    /** table => [column, [English => Spanish]] */
    private const RENAMES = [
        'office_shifts' => ['name', ['Regular Hours 9:00-17:00' => 'Horario regular 9:00-17:00', 'Standard 9-5 (demo)' => 'Horario 9-17 (demo)']],
        'departments' => ['department', ['Administration' => 'Administración', 'Sales' => 'Ventas', 'Operations' => 'Operaciones', 'Finance' => 'Finanzas']],
        'designations' => ['designation', ['Administrator' => 'Administrador', 'Sales Representative' => 'Representante de ventas',
            'Sales Manager' => 'Gerente de ventas', 'Warehouse Clerk' => 'Almacenero', 'Accountant' => 'Contador']],
        'warehouses' => ['name', ['Main Location' => 'Local principal']],
        'leaves' => ['reason', ['Family event' => 'Evento familiar', 'Medical appointment' => 'Cita médica', 'Vacation' => 'Vacaciones', 'Personal matters' => 'Asuntos personales']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'default_language')) {
            return;
        }
        $companies = DB::table('settings')->whereNull('deleted_at')->where('default_language', 'like', 'es%')
            ->where('company_id', '!=', 999)->pluck('company_id')->filter()->unique()->values()->all();
        if (! $companies) {
            return;
        }
        foreach (self::RENAMES as $table => [$column, $map]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            foreach ($map as $en => $es) {
                DB::table($table)->whereIn('company_id', $companies)->whereRaw("`$column` = ? COLLATE utf8mb4_bin", [$en])->update([$column => $es]);
            }
        }
    }

    public function down(): void
    {
    }
};
