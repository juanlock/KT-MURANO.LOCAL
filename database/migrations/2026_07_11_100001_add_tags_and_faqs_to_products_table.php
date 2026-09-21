<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'tags')) {
                $table->json('tags')->nullable()->after('note');
            }
            if (! Schema::hasColumn('products', 'faqs')) {
                $table->json('faqs')->nullable()->after('tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'faqs')) {
                $table->dropColumn('faqs');
            }
            if (Schema::hasColumn('products', 'tags')) {
                $table->dropColumn('tags');
            }
        });
    }
};
