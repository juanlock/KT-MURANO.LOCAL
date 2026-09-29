<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store -> Pages per company (multicompany rule): each CMS page belongs to one
 * company, and slugs are unique per company instead of install-wide (so every
 * company can have its own "home", "about-us", ...).
 *
 * Existing pages: demo-tagged ones go to the company they were generated for;
 * any other page goes to the storefront's company (the first store_settings
 * row -- the company the public store currently serves).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_pages', 'company_id')) {
            Schema::table('store_pages', function (Blueprint $table) {
                $table->unsignedInteger('company_id')->nullable()->index()->after('id');
            });
        }

        foreach (DB::table('demo_records')->where('record_type', 'store_pages')->get(['company_id', 'record_id']) as $d) {
            DB::table('store_pages')->where('id', $d->record_id)->whereNull('company_id')->update(['company_id' => $d->company_id]);
        }
        $storeCompany = DB::table('store_settings')->orderBy('id')->value('company_id');
        if ($storeCompany) {
            DB::table('store_pages')->whereNull('company_id')->update(['company_id' => $storeCompany]);
        }

        $indexes = collect(DB::select('SHOW INDEX FROM store_pages'))->pluck('Key_name')->unique();
        Schema::table('store_pages', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('store_pages_slug_unique')) {
                $table->dropUnique('store_pages_slug_unique');
            }
            if (! $indexes->contains('store_pages_company_slug_unique')) {
                $table->unique(['company_id', 'slug'], 'store_pages_company_slug_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            $table->dropUnique('store_pages_company_slug_unique');
            $table->dropColumn('company_id');
            $table->unique('slug');
        });
    }
};
