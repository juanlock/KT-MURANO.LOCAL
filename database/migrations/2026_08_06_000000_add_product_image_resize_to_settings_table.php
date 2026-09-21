<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Defaults reproduce the previously hard-coded behaviour (resize every
            // uploaded product image down to 800x800) so existing installs are
            // unaffected until the setting is changed.
            if (! Schema::hasColumn('settings', 'product_image_resize')) {
                $table->boolean('product_image_resize')->default(true);
            }
            if (! Schema::hasColumn('settings', 'product_image_max_size')) {
                $table->unsignedInteger('product_image_max_size')->default(800);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'product_image_resize')) {
                $table->dropColumn('product_image_resize');
            }
            if (Schema::hasColumn('settings', 'product_image_max_size')) {
                $table->dropColumn('product_image_max_size');
            }
        });
    }
};
