<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio module (per company; separate from Store -> Portfolio): a public
 * portfolio website at /portfolio/{slug} (layout of KT-TOOLS/Porfolio: logo
 * header, corner borders, filterable gallery, project pages with one image /
 * a slider / a video, About and Contact) managed from the "Portfolio" menu.
 *
 *  portfolio_sites       one per company: slug, texts, logo, about, contact, social, theme
 *  portfolio_categories  the gallery filter buttons (Hair Style, Nails...)
 *  portfolio_projects    a work: cover, type image|slider|video, details, categories
 *  portfolio_media       a project's photos (slider)
 *  portfolio_messages    the Contact form
 * Files: public/files/company_{id}/portfolio/. Permission: portfolio_manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_sites')) {
            Schema::create('portfolio_sites', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->unique();
                $t->string('slug', 80)->unique();
                $t->string('name', 150);
                $t->string('tagline', 255)->nullable();
                $t->string('logo', 255)->nullable();
                $t->string('theme', 10)->default('dark');          // dark | light
                $t->boolean('published')->default(false);
                $t->string('about_title', 150)->nullable();
                $t->string('about_subtitle', 255)->nullable();
                $t->string('about_name', 150)->nullable();
                $t->string('about_image', 255)->nullable();
                $t->text('about_text')->nullable();
                $t->json('skills')->nullable();                     // [{label, pct}]
                $t->string('contact_subtitle', 255)->nullable();
                $t->text('contact_address')->nullable();
                $t->text('contact_phones')->nullable();             // one per line
                $t->text('contact_emails')->nullable();             // one per line
                $t->text('map_url')->nullable();                    // Google Maps "embed" URL
                $t->json('social')->nullable();                     // {instagram, facebook, tiktok, ...}
                $t->string('footer_text', 255)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('portfolio_categories')) {
            Schema::create('portfolio_categories', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('name', 80);
                $t->string('slug', 90);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'slug']);
            });
        }
        if (! Schema::hasTable('portfolio_projects')) {
            Schema::create('portfolio_projects', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('title', 150);
                $t->string('slug', 170);
                $t->string('type', 10)->default('image');           // image | slider | video
                $t->string('cover', 255)->nullable();
                $t->text('description')->nullable();
                $t->string('client', 150)->nullable();
                $t->string('location', 150)->nullable();
                $t->date('project_date')->nullable();
                $t->string('video_url', 255)->nullable();
                $t->json('categories')->nullable();                 // [category ids]
                $t->boolean('featured')->default(true);             // shown on Home
                $t->boolean('published')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'slug']);
            });
        }
        if (! Schema::hasTable('portfolio_media')) {
            Schema::create('portfolio_media', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('project_id')->index();
                $t->string('path', 255);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('portfolio_messages')) {
            Schema::create('portfolio_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('name', 150);
                $t->string('email', 190)->nullable();
                $t->string('phone', 60)->nullable();
                $t->text('message');
                $t->string('ip', 45)->nullable();
                $t->boolean('is_read')->default(false);
                $t->timestamps();
            });
        }

        // Permission (granted per role in Users -> Roles; the role is mandatory).
        if (Schema::hasTable('permissions') && ! DB::table('permissions')->where('name', 'portfolio_manage')->exists()) {
            DB::table('permissions')->insert([
                'name' => 'portfolio_manage', 'label' => 'Portfolio: manage the portfolio website',
                'description' => 'Portfolio: manage the portfolio website', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['portfolio_messages', 'portfolio_media', 'portfolio_projects', 'portfolio_categories', 'portfolio_sites'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('permissions')->where('name', 'portfolio_manage')->delete();
    }
};
