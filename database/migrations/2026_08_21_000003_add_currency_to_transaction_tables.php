<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Currency module: snapshot the document currency and its exchange rate
 * on each transactional document. All existing monetary columns keep their
 * base-currency semantics; document-currency amounts are derived at
 * display/print time (base × exchange_rate). NULL currency_id / exchange_rate
 * means "base currency, rate 1" so legacy rows and toggle-off installs are
 * unaffected. Deliberately no hard FK: currencies uses manual soft-deletes and
 * readers fall back to the base currency when the row is gone.
 */
return new class extends Migration
{
    private const TABLES = [
        'sales',
        'purchases',
        'quotations',
        'sale_returns',
        'purchase_returns',
        'online_orders',
        'draft_sales',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'currency_id')) {
                    $table->integer('currency_id')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'exchange_rate')) {
                    $table->decimal('exchange_rate', 18, 6)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'currency_id')) {
                    $table->dropColumn('currency_id');
                }
                if (Schema::hasColumn($tableName, 'exchange_rate')) {
                    $table->dropColumn('exchange_rate');
                }
            });
        }
    }
};
