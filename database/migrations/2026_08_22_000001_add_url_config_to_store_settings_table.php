<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable storefront URL:
     *  - store_url_path: custom base path (NULL = legacy default "online_store")
     *  - store_use_root_domain: serve the storefront from "/" instead of a path
     */
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'store_url_path')) {
                $table->string('store_url_path', 100)->nullable()->after('store_domain');
            }
            if (! Schema::hasColumn('store_settings', 'store_use_root_domain')) {
                $table->boolean('store_use_root_domain')->default(false)->after('store_url_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (Schema::hasColumn('store_settings', 'store_url_path')) {
                $table->dropColumn('store_url_path');
            }
            if (Schema::hasColumn('store_settings', 'store_use_root_domain')) {
                $table->dropColumn('store_use_root_domain');
            }
        });
    }
};
