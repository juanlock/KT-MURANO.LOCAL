<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compact table rows are the standard (user's rule, 2026-11-20: "las filas para todas las tablas que sean más compactas
 * verticalmente... que sea un standard"). Every company still on the old default row density ('middle') moves to 'small';
 * one that picked 'large' on purpose keeps it. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'datatable_settings')) {
            return;
        }
        foreach (DB::table('settings')->whereNotNull('datatable_settings')->get(['id', 'datatable_settings']) as $row) {
            $prefs = json_decode((string) $row->datatable_settings, true);
            if (! is_array($prefs) || ($prefs['density'] ?? 'middle') !== 'middle') {
                continue;
            }
            $prefs['density'] = 'small';
            DB::table('settings')->where('id', $row->id)->update(['datatable_settings' => json_encode($prefs)]);
        }
    }

    public function down(): void
    {
        // Left as is: 'small' is the standard now.
    }
};
