<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-language popup copy: {"es": "…", "fr": "…"}. The plain columns stay
     * the admin-facing text and the fallback for any locale left blank.
     */
    public function up(): void
    {
        Schema::table('store_popups', function (Blueprint $table) {
            if (! Schema::hasColumn('store_popups', 'title_translations')) {
                $table->json('title_translations')->nullable()->after('title');
            }
            if (! Schema::hasColumn('store_popups', 'message_translations')) {
                $table->json('message_translations')->nullable()->after('message');
            }
            if (! Schema::hasColumn('store_popups', 'cta_label_translations')) {
                $table->json('cta_label_translations')->nullable()->after('cta_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_popups', function (Blueprint $table) {
            foreach (['title_translations', 'message_translations', 'cta_label_translations'] as $col) {
                if (Schema::hasColumn('store_popups', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
