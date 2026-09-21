<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-customer storefront defaults, so a shopper's language/currency
     * follows their account instead of living only in the browser session.
     * NULL on either column means "use the store default".
     */
    public function up(): void
    {
        Schema::table('ecommerce_clients', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_clients', 'preferred_locale')) {
                $table->string('preferred_locale', 5)->nullable()->after('email');
            }
            if (! Schema::hasColumn('ecommerce_clients', 'preferred_currency_id')) {
                $table->integer('preferred_currency_id')->nullable()->after('preferred_locale');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_clients', function (Blueprint $table) {
            foreach (['preferred_locale', 'preferred_currency_id'] as $col) {
                if (Schema::hasColumn('ecommerce_clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
