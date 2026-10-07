<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One Portfolio per company (user's choice, 2026-10-06): the old "Store ->
 * Portfolio" pictures (store_portfolio_groups / store_portfolio_items) become
 * works of the Portfolio module -- every group a category (reusing one with the
 * same name), every picture a published work with that picture as cover and
 * photo. The website section (Portfolio::data) reads the module from now on.
 * The image files stay where they are (already files/company_{id}/portfolio/).
 * The old tables are kept (read-only history); a re-run skips pictures already moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_portfolio_items') || ! Schema::hasTable('portfolio_projects')) {
            return;
        }
        $groups = DB::table('store_portfolio_groups')->get()->keyBy('id');
        $catOf = [];
        foreach (DB::table('store_portfolio_items')->orderBy('company_id')->orderBy('sort_order')->orderBy('id')->get() as $it) {
            $cid = (int) $it->company_id;
            if (! $it->image || DB::table('portfolio_projects')->where('company_id', $cid)->where('cover', $it->image)->exists()) {
                continue;
            }
            $catIds = [];
            if ($it->group_id && isset($groups[$it->group_id])) {
                $g = $groups[$it->group_id];
                $key = $cid.':'.$g->id;
                if (! isset($catOf[$key])) {
                    $catOf[$key] = DB::table('portfolio_categories')->where('company_id', $cid)
                        ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($g->name)])->orWhere('slug', $g->slug))->value('id')
                        ?? DB::table('portfolio_categories')->insertGetId([
                            'company_id' => $cid, 'name' => $g->name, 'slug' => $this->free('portfolio_categories', $cid, Str::slug($g->name) ?: 'category'),
                            'sort_order' => (int) DB::table('portfolio_categories')->where('company_id', $cid)->max('sort_order') + 1,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                }
                $catIds = [$catOf[$key]];
            }
            $title = trim((string) $it->title) ?: 'Work';
            $projectId = DB::table('portfolio_projects')->insertGetId([
                'company_id' => $cid, 'title' => Str::limit($title, 150, ''), 'slug' => $this->free('portfolio_projects', $cid, Str::slug($title) ?: 'work'),
                'type' => 'image', 'cover' => $it->image, 'description' => $it->subtitle,
                'categories' => json_encode($catIds), 'featured' => true, 'published' => (bool) $it->active,
                'sort_order' => (int) DB::table('portfolio_projects')->where('company_id', $cid)->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('portfolio_media')->insert(['company_id' => $cid, 'project_id' => $projectId, 'path' => $it->image, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        // The sidebar: Website Management -> Portfolio (the old store screen) is gone; the Portfolio module has it.
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/website/portfolio')->delete();
        }
    }

    private function free(string $table, int $cid, string $slug): string
    {
        $try = $slug;
        for ($i = 2; DB::table($table)->where('company_id', $cid)->where('slug', $try)->exists(); $i++) {
            $try = $slug.'-'.$i;
        }

        return $try;
    }

    public function down(): void
    {
    }
};
