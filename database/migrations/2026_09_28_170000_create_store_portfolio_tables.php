<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store -> Portfolio (per company): pictures in groups (the filter tabs, e.g.
 * "Web Design / Graphic Design"), shown as a front-page section -- a
 * Homepage Block on our store front page, or inserted into an imported
 * website's front page (store_settings.portfolio_section).
 *
 * Also a sidebar row after Gallery for every company with a saved layout,
 * and the admin UI strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_portfolio_groups')) {
            Schema::create('store_portfolio_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->string('name', 120);
                $table->string('slug', 140);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'slug']);
            });
        }
        if (! Schema::hasTable('store_portfolio_items')) {
            Schema::create('store_portfolio_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->unsignedBigInteger('group_id')->nullable()->index();
                $table->string('title', 190)->nullable();
                $table->string('subtitle', 190)->nullable();
                $table->string('image', 255);
                $table->string('link', 500)->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->string('source', 20)->default('upload'); // upload | imported
                $table->string('source_path', 255)->nullable();
                $table->timestamps();
                $table->index(['company_id', 'sort_order']);
            });
        }
        if (Schema::hasTable('store_settings') && ! Schema::hasColumn('store_settings', 'portfolio_section')) {
            Schema::table('store_settings', function (Blueprint $table) {
                // {enabled, placement: replace|before_footer|end, title, subtitle}
                $table->text('portfolio_section')->nullable();
            });
        }

        $this->translations();

        $key = '/app/Store/Portfolio';
        $after = DB::table('menu_positions')->where('item_key', '/app/Store/Gallery')->get(['company_id', 'parent_key', 'sort_order', 'location']);
        foreach ($after as $g) {
            if (DB::table('menu_positions')->where('company_id', $g->company_id)->where('item_key', $key)->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $g->company_id)
                ->where('parent_key', $g->parent_key)->where('sort_order', '>', $g->sort_order)
                ->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $g->company_id, 'item_key' => $key, 'parent_key' => $g->parent_key,
                'sort_order' => $g->sort_order + 1, 'external' => 0, 'is_custom' => 0, 'hidden' => 0,
                'location' => $g->location ?: 'sidebar', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $keys = [
            'Portfolio' => ['Portfolio', 'Portafolio', 'Portfolio'],
            'Portfolio_Groups' => ['Groups', 'Grupos', 'Groupes'],
            'Portfolio_Add_Group' => ['Add group', 'Agregar grupo', 'Ajouter un groupe'],
            'Portfolio_No_Group' => ['No group', 'Sin grupo', 'Sans groupe'],
            'Portfolio_All' => ['All', 'Todos', 'Tous'],
            'Portfolio_Import_Hint' => [
                'This company\'s imported website has a portfolio with {n} pictures. "Import from website" copies its groups and pictures.',
                'El sitio web importado de esta empresa tiene un portafolio con {n} fotos. "Importar del sitio web" copia sus grupos y fotos.',
                'Le site importé de cette société a un portfolio de {n} photos. « Importer depuis le site » copie ses groupes et photos.',
            ],
            'Portfolio_Import_Done' => [
                '{added} pictures and {groups} groups added, {skipped} already there.',
                '{added} fotos y {groups} grupos agregados, {skipped} ya existían.',
                '{added} photos et {groups} groupes ajoutés, {skipped} déjà présents.',
            ],
            'Subtitle' => ['Subtitle', 'Subtítulo', 'Sous-titre'],
        ];
        $locales = array_diff(Schema::getColumnListing('translations'), ['id', 'key', 'is_default', 'customized_locales', 'created_at', 'updated_at']);
        foreach ($keys as $key => [$en, $es, $fr]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            $row = ['key' => $key, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()];
            foreach ($locales as $l) {
                $row[$l] = $l === 'es' ? $es : ($l === 'fr' ? $fr : $en);
            }
            DB::table('translations')->insert($row);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', '/app/Store/Portfolio')->delete();
        if (Schema::hasColumn('store_settings', 'portfolio_section')) {
            Schema::table('store_settings', fn (Blueprint $t) => $t->dropColumn('portfolio_section'));
        }
        Schema::dropIfExists('store_portfolio_items');
        Schema::dropIfExists('store_portfolio_groups');
    }
};
