<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website Management sidebar (user's request, 2026-10-06): Front page, Homepage
 * blocks, Pages, Menus and Hero are tabs of Website settings -- their separate
 * menu entries are removed for every company. Left: Website settings, Gallery,
 * Portfolio (in that order).
 */
return new class extends Migration
{
    private const REMOVED = ['/app/website/front-page', '/app/website/homepage', '/app/website/pages', '/app/website/menus', '/app/website/hero'];

    public function up(): void
    {
        if (! Schema::hasTable('menu_positions')) {
            return;
        }
        DB::table('menu_positions')->whereIn('item_key', self::REMOVED)->delete();
        foreach (['/app/website/settings' => 0, '/app/website/gallery' => 1, '/app/website/portfolio' => 2] as $key => $sort) {
            DB::table('menu_positions')->where('item_key', $key)->where('parent_key', 'website')->update(['sort_order' => $sort, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The removed entries come back from config/menu.js if it lists them again.
    }
};
