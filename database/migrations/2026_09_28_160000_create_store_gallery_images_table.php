<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store -> Gallery: per-company pictures shown on the online store's
 * /gallery page. `source_path` remembers where an imported picture came
 * from (its path inside the company's imported website copy), so importing
 * again never duplicates it.
 *
 * Also gives every company that already has a saved sidebar layout
 * (menu_positions) a "Gallery" row right after Banners -- a company with no
 * rows keeps using config/menu.js, which lists it there already.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_gallery_images')) {
            Schema::create('store_gallery_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->string('title', 190)->nullable();
                $table->string('image', 255);
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

        $this->translations();

        $key = '/app/Store/Gallery';
        $companies = DB::table('menu_positions')->where('item_key', '/app/Store/Banners')->get(['company_id', 'parent_key', 'sort_order', 'location']);
        foreach ($companies as $b) {
            if (DB::table('menu_positions')->where('company_id', $b->company_id)->where('item_key', $key)->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $b->company_id)
                ->where('parent_key', $b->parent_key)->where('sort_order', '>', $b->sort_order)
                ->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $b->company_id,
                'item_key' => $key,
                'parent_key' => $b->parent_key,
                'sort_order' => $b->sort_order + 1,
                'external' => 0,
                'is_custom' => 0,
                'hidden' => 0,
                'location' => $b->location ?: 'sidebar',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Admin UI strings (translations table); other locales fall back to English. */
    private function translations(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $keys = [
            'Gallery' => ['Gallery', 'Galería', 'Galerie'],
            'Upload_Photos' => ['Upload photos', 'Subir fotos', 'Téléverser des photos'],
            'Import_From_Website' => ['Import from website', 'Importar del sitio web', 'Importer depuis le site'],
            'View_Gallery' => ['View gallery', 'Ver galería', 'Voir la galerie'],
            'Hidden' => ['Hidden', 'Oculto', 'Masqué'],
            'Imported' => ['Imported', 'Importado', 'Importé'],
            'Gallery_Import_Hint' => [
                'This company\'s imported website has {n} photos. "Import from website" copies the ones not in the gallery yet.',
                'El sitio web importado de esta empresa tiene {n} fotos. "Importar del sitio web" copia las que aún no están en la galería.',
                'Le site importé de cette société contient {n} photos. « Importer depuis le site » copie celles qui ne sont pas encore dans la galerie.',
            ],
            'Gallery_Import_Done' => [
                '{added} photos added, {skipped} already in the gallery.',
                '{added} fotos agregadas, {skipped} ya estaban en la galería.',
                '{added} photos ajoutées, {skipped} déjà dans la galerie.',
            ],
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
        DB::table('menu_positions')->where('item_key', '/app/Store/Gallery')->delete();
        Schema::dropIfExists('store_gallery_images');
    }
};
