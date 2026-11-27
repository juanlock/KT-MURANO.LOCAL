<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo (the user, 2026-11-27, after the presentation): Administración (role Manager: Mayte) sees every board, like
 * the Director. No list = every board its permissions allow. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($cid) {
            DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')
                ->update(['dashboard_tabs' => null, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
