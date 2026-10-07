<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Portfolio -> "Works" is now "Photos" (/portfolio/photos): saved sidebar menus follow. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/portfolio/works')->update(['item_key' => '/app/portfolio/photos']);
            DB::table('menu_positions')->where('item_key', '/app/portfolio/photos')->where('label', 'Works')->update(['label' => 'Photos']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/portfolio/photos')->update(['item_key' => '/app/portfolio/works']);
        }
    }
};
