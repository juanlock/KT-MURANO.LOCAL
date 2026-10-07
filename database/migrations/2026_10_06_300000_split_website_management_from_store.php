<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online Store split in two modules (2026-10-06): Store (the shop) and Website
 * Management (front page, homepage blocks, pages, menus, hero, branding / SEO,
 * gallery, portfolio) -- every company:
 *  - sidebar (menu_positions): Store -> Gallery / Portfolio move under the new
 *    "website" entry; where a company saved its top-level order, Website
 *    Management goes right after Store;
 *  - Business Modules: a company that had the Store switched off keeps those
 *    screens off (website = off too);
 *  - translations for the new menu labels.
 */
return new class extends Migration
{
    private const MOVED = ['/app/Store/Gallery' => '/app/website/gallery', '/app/Store/Portfolio' => '/app/website/portfolio'];

    public function up(): void
    {
        foreach ([
            'Website_Management' => ['Website Management', 'Gestión del sitio web'],
            'Front_Page' => ['Front page', 'Página principal'],
            'Website_Settings' => ['Website settings', 'Ajustes del sitio web'],
        ] as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            if (Schema::hasTable('translations')) {
                DB::table('translations')->where('key', $key)->where('en', $key)->update(['en' => $en]);
            }
        }

        if (Schema::hasTable('menu_positions')) {
            foreach (self::MOVED as $old => $new) {
                DB::table('menu_positions')->where('item_key', $old)->update(['item_key' => $new, 'parent_key' => 'website', 'updated_at' => now()]);
            }
            // Companies with a saved menu: the Website entries in their natural order
            // (the moved Gallery / Portfolio rows kept their old Store positions).
            $order = ['/app/website/front-page', '/app/website/homepage', '/app/website/pages', '/app/website/menus',
                '/app/website/hero', '/app/website/gallery', '/app/website/portfolio', '/app/website/settings'];
            foreach (DB::table('menu_positions')->distinct()->pluck('company_id') as $cid) {
                foreach ($order as $i => $key) {
                    $exists = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists();
                    $exists
                        ? DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->update(['parent_key' => 'website', 'sort_order' => $i, 'updated_at' => now()])
                        : DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => $key, 'parent_key' => 'website', 'label' => null, 'is_custom' => 0,
                            'hidden' => 0, 'location' => 'sidebar', 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach (DB::table('menu_positions')->where('item_key', 'Store')->whereNull('parent_key')->get() as $store) {
                if (DB::table('menu_positions')->where('company_id', $store->company_id)->where('item_key', 'website')->exists()) {
                    continue;
                }
                DB::table('menu_positions')->where('company_id', $store->company_id)->whereNull('parent_key')
                    ->where('sort_order', '>', $store->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $store->company_id, 'item_key' => 'website', 'parent_key' => null, 'label' => null,
                    'is_custom' => 0, 'hidden' => (int) $store->hidden, 'location' => $store->location ?? 'sidebar',
                    'sort_order' => $store->sort_order + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasColumn('settings', 'module_flags')) {
            foreach (DB::table('settings')->whereNotNull('module_flags')->get(['id', 'module_flags']) as $row) {
                $flags = json_decode((string) $row->module_flags, true) ?: [];
                if (($flags['Store'] ?? true) === false && ! array_key_exists('website', $flags)) {
                    $flags['website'] = false;
                    DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags)]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            foreach (self::MOVED as $old => $new) {
                DB::table('menu_positions')->where('item_key', $new)->update(['item_key' => $old, 'parent_key' => 'Store']);
            }
            DB::table('menu_positions')->where('item_key', 'website')->whereNull('parent_key')->delete();
            DB::table('menu_positions')->where('parent_key', 'website')->delete();
        }
    }
};
