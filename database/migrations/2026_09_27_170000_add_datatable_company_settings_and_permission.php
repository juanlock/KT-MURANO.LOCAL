<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> DataTable, per company instead of per device:
 *  - settings.datatable_settings: the company's table format (font, density,
 *    borders, stripes, header style, toolbar pieces) -- applied to every
 *    user's tables, like export_settings.
 *  - settings.datatable_columns: per-table column customization set by an
 *    admin ({tableKey: [{key, title, width, hidden}, ...] in display order}).
 *  - permission `datatable_customize` (the "Customize Columns" button),
 *    granted to each company's Owner / Admin / Admin-ALL roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'datatable_settings')) {
                $table->longText('datatable_settings')->nullable();
            }
            if (! Schema::hasColumn('settings', 'datatable_columns')) {
                $table->longText('datatable_columns')->nullable();
            }
        });

        $now = now();
        $permissionId = DB::table('permissions')->where('name', 'datatable_customize')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'datatable_customize', 'created_at' => $now, 'updated_at' => $now]);

        $roleIds = DB::table('roles')->whereIn('name', ['Owner', 'Admin', 'Admin-ALL'])->whereNull('deleted_at')->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'datatable_customize')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        Schema::table('settings', function (Blueprint $table) {
            foreach (['datatable_settings', 'datatable_columns'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
