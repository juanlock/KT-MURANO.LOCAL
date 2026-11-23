<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "Menú propio" (user, 2026-11-23): a company whose menu is its own is left out when superadmin/AdminSystem saves or
 * resets the menu for every company in Manage Menu (MenuPositionController), so the SuperAdmin's order reaches only the
 * other companies (the demos). On by default for the companies in production. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'menu_own')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('menu_own')->default(false);
            });
            DB::table('companies')->where('is_live', 1)->update(['menu_own' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'menu_own')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('menu_own');
            });
        }
    }
};
