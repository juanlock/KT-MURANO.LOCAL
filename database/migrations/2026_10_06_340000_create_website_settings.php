<?php

use App\Models\WebsiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website Management has its OWN settings (user's choice, 2026-10-06): name,
 * logo, favicon, SEO, top bar & footer, social links and menus -- separate from
 * the Online Store's (store_settings), which keeps the shop's set. Website pages
 * (front page with our theme, pages, gallery, contact) use these; the shop
 * pages (shop, product, cart, account) keep the store's.
 * Every company starts with a copy of its store values (logo / favicon files
 * copied into files/company_{id}/website/), so nothing changes visually today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_settings')) {
            Schema::create('website_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->unique();
                $t->string('site_name', 190)->nullable();
                $t->string('logo_path', 255)->nullable();
                $t->string('favicon_path', 255)->nullable();
                $t->string('seo_meta_title', 255)->nullable();
                $t->text('seo_meta_description')->nullable();
                $t->string('seo_title_template', 190)->nullable();
                $t->string('topbar_text_left', 190)->nullable();
                $t->string('topbar_text_right', 190)->nullable();
                $t->string('footer_text', 255)->nullable();
                $t->json('social_links')->nullable();
                $t->json('menus')->nullable();
                $t->json('text_translations')->nullable();
                $t->timestamps();
            });
        }
        foreach (DB::table('store_settings')->pluck('company_id') as $cid) {
            WebsiteSetting::forCompany((int) $cid); // created from the store's values
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_settings');
    }
};
