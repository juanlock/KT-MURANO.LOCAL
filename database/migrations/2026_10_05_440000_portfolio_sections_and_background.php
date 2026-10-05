<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio module: the website's look and the Home page sections.
 *  portfolio_sites: bg_color / bg_image (page background), pages {about, contact} on / off.
 *  portfolio_sections: the Home page, top to bottom -- type gallery | text |
 *  image | image_text | video | contact, title / subtitle / text / image /
 *  video / button, its own background color, visible, order.
 * Every existing site gets one "gallery" section (what Home showed until now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_sites', function (Blueprint $t) {
            if (! Schema::hasColumn('portfolio_sites', 'bg_color')) {
                $t->string('bg_color', 9)->nullable()->after('theme');
                $t->string('bg_image', 255)->nullable()->after('bg_color');
                $t->json('pages')->nullable()->after('bg_image');
            }
        });
        if (! Schema::hasTable('portfolio_sections')) {
            Schema::create('portfolio_sections', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('type', 20);                    // gallery | text | image | image_text | video | contact
                $t->string('title', 150)->nullable();
                $t->string('subtitle', 255)->nullable();
                $t->text('body')->nullable();
                $t->string('image', 255)->nullable();
                $t->string('video_url', 255)->nullable();
                $t->string('button_label', 60)->nullable();
                $t->string('button_link', 255)->nullable();
                $t->string('bg_color', 9)->nullable();
                $t->json('settings')->nullable();           // gallery: {featured_only, show_all}
                $t->boolean('visible')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        foreach (DB::table('portfolio_sites')->pluck('company_id') as $cid) {
            if (! DB::table('portfolio_sections')->where('company_id', $cid)->exists()) {
                DB::table('portfolio_sections')->insert([
                    'company_id' => $cid, 'type' => 'gallery', 'settings' => json_encode(['featured_only' => true, 'show_all' => false]),
                    'visible' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('portfolio_sites')->whereNull('pages')->update(['pages' => json_encode(['about' => true, 'contact' => true])]);
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_sections');
        Schema::table('portfolio_sites', function (Blueprint $t) {
            foreach (['bg_color', 'bg_image', 'pages'] as $c) {
                if (Schema::hasColumn('portfolio_sites', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
