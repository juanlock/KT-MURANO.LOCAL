<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sidebar_logo_width')
                ->default(32)
                ->after('hide_site_name');
            $table->unsignedSmallInteger('sidebar_logo_height')
                ->default(32)
                ->after('sidebar_logo_width');
            $table->boolean('sidebar_show_logo')
                ->default(true)
                ->after('sidebar_logo_height');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['sidebar_logo_width', 'sidebar_logo_height', 'sidebar_show_logo']);
        });
    }
};
