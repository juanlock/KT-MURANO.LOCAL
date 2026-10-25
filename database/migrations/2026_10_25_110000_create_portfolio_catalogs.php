<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio module -> tablet catalogs (2026-10-25): as many per company as needed,
 * next to the company's portfolio website. Two types, full screen and dark, for a
 * tablet / iPad handed to the customer (header button like POS):
 *  - menu   (restaurant / food): slider on top, group buttons (Rice, Soups,
 *           Desserts, Drinks...), each group a 2-column grid of dishes (picture,
 *           name, price) taken from Products; the customer builds an order that
 *           goes to the Kitchen and waits in the POS to be charged.
 *  - models (salon / barber...): the same layout, but first the customer answers
 *           the catalog's steps (e.g. Man / Woman, then Adult / Child) and only
 *           the services that fit are shown.
 *
 *  portfolio_catalogs        company_id, type, name, public_key, status, settings (JSON:
 *                            title, logo, slides, accent, orders, ask_table, steps...)
 *  portfolio_catalog_groups  catalog_id, name, image, sort_order
 *  portfolio_catalog_items   catalog_id, group_id, product_id, sort_order, options (JSON:
 *                            models -> {step id: [option ids]}, empty = fits all)
 *  portfolio_catalog_orders  the orders sent from the tablet (number, table / name,
 *                            lines JSON, total, kitchen_order_id, sale_id, status)
 * Files: public/files/company_{id}/portfolio/ (public: shown on the tablet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_catalogs')) {
            Schema::create('portfolio_catalogs', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('type', 10);                    // menu | models
                $t->string('name', 150);
                $t->string('public_key', 24)->unique();
                $t->boolean('status')->default(true);
                $t->json('settings')->nullable();
                $t->unsignedInteger('next_number')->default(1);
                $t->integer('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('portfolio_catalog_groups')) {
            Schema::create('portfolio_catalog_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('catalog_id')->index();
                $t->string('name', 120);
                $t->string('image', 255)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('portfolio_catalog_items')) {
            Schema::create('portfolio_catalog_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('catalog_id')->index();
                $t->unsignedBigInteger('group_id')->index();
                $t->unsignedInteger('product_id')->index();
                $t->unsignedInteger('sort_order')->default(0);
                $t->json('options')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('portfolio_catalog_orders')) {
            Schema::create('portfolio_catalog_orders', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('catalog_id')->index();
                $t->unsignedInteger('number');
                $t->string('table_label', 60)->nullable();   // table number or the customer's name
                $t->string('notes', 500)->nullable();
                $t->json('lines');                           // [{product_id, name, price, qty, note}]
                $t->decimal('total', 14, 2)->default(0);
                $t->unsignedBigInteger('kitchen_order_id')->nullable();
                $t->unsignedBigInteger('sale_id')->nullable();
                $t->string('status', 20)->default('new');    // new | sent | charged | cancelled
                $t->string('ip', 45)->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('translations')) {
            foreach ([
                'Portfolio_Catalogs' => ['Tablet catalogs', 'Catálogos para tablet'],
            ] as $key => [$en, $es]) {
                if (! DB::table('translations')->where('key', $key)->exists()) {
                    DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['portfolio_catalog_orders', 'portfolio_catalog_items', 'portfolio_catalog_groups', 'portfolio_catalogs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
