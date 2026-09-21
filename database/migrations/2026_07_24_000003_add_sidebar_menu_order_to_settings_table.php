<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'sidebar_menu_order')) {
                // JSON tree of menu-entry ids: [{id, children: [{id, ...}]}].
                // Null = default order from resources/src/config/menu.js.
                $table->longText('sidebar_menu_order')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'sidebar_menu_order')) {
                $table->dropColumn('sidebar_menu_order');
            }
        });
    }
};
