<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store -> Menus: the navigation menu of a company's imported front page
 * (App\Services\FrontPageMenu), as {items: [...], pages: n}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_settings', 'imported_menu')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->longText('imported_menu')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_settings', 'imported_menu')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('imported_menu');
            });
        }
    }
};
