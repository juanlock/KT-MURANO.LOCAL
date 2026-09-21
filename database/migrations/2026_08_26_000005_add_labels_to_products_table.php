<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storefront product labels ("New", "Used", "Refurbished", or anything the
     * store invents) shown as a corner badge on the card and the product page.
     *
     * Deliberately separate from `tags`: tags are search keywords and can be
     * numerous, labels are a short badge list meant to be seen.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'labels')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('labels')->nullable()->after('tags');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'labels')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('labels');
            });
        }
    }
};
