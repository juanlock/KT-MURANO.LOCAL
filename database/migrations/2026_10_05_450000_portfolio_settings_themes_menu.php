<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio -> Settings: themes and the website menu.
 *  portfolio_sites.template      the theme (App\Support\PortfolioThemes): minimal (theme 1, the
 *                                KT-TOOLS/apps/Porfolio example) | grid (theme 2, Adobe Portfolio style).
 *                                `theme` stays the Look (dark / light).
 *  portfolio_sites.menu          the menu, null = the default one (Home / About / Portfolio / Contact):
 *                                [{label, type: page|link|group, page, url, new_tab, visible, children: [...]}]
 *  portfolio_sites.menu_on_home  theme 1: also show the menu on Home / Portfolio / a work (the example
 *                                shows only the logo there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_sites', function (Blueprint $t) {
            if (! Schema::hasColumn('portfolio_sites', 'template')) {
                $t->string('template', 30)->default('minimal')->after('theme');
                $t->json('menu')->nullable()->after('pages');
                $t->boolean('menu_on_home')->default(false)->after('menu');
            }
        });
        // Sidebar: Portfolio -> Website became Portfolio -> Settings (last item); companies
        // that saved their menu keep the row, under the new key.
        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', '/app/portfolio/website')->get() as $row) {
                $max = (int) DB::table('menu_positions')->where('company_id', $row->company_id)->where('parent_key', 'portfolio')->max('sort_order');
                DB::table('menu_positions')->where('id', $row->id)->update([
                    'item_key' => '/app/portfolio/settings', 'label' => 'Settings', 'sort_order' => $max + 1, 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('portfolio_sites', function (Blueprint $t) {
            foreach (['template', 'menu', 'menu_on_home'] as $c) {
                if (Schema::hasColumn('portfolio_sites', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
