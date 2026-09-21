<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotions module (ported from the ksa project): the promotions table with
     * usage limits folded in, the warehouse/product pivots, the usage log, and the
     * sale columns POS writes when a promotion is applied.
     *
     * No DB-level foreign keys on the pivots/usages, matching the existing
     * warehouses/products convention — integrity is enforced at the app layer.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 192);
            $table->string('code', 64)->nullable()->index();
            $table->text('description')->nullable();

            // 'discount' = unconditional reduction, 'promotion' = conditional offer.
            $table->enum('kind', ['discount', 'promotion'])->default('discount')->index();

            // How the value is applied.
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 12, 2)->default(0);

            $table->boolean('is_active')->default(true)->index();

            // Validity window.
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->time('time_of_day_start')->nullable();
            $table->time('time_of_day_end')->nullable();

            // Conditions.
            $table->decimal('min_cart_total', 14, 2)->nullable();
            $table->unsignedInteger('min_item_count')->nullable();
            $table->enum('product_scope', ['all', 'specific'])->default('all');

            // Stacking rules: highest priority wins; if stackable=true, multiple may apply.
            $table->integer('priority')->default(0)->index();
            $table->boolean('stackable')->default(false);

            // Usage caps; null = unlimited.
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();

            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('promotion_warehouse', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id')->index();
            $table->integer('warehouse_id')->index();
            $table->timestamps(6);

            $table->unique(['promotion_id', 'warehouse_id']);
        });

        Schema::create('promotion_products', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id')->index();
            $table->integer('product_id')->index();
            $table->timestamps(6);

            $table->unique(['promotion_id', 'product_id']);
        });

        // One row per time a promotion was applied to a sale. Drives usage-cap
        // enforcement and the usage report.
        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id')->index();
            $table->integer('sale_id')->nullable()->index();
            $table->integer('client_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->string('code', 64)->nullable();
            $table->dateTime('used_at')->index();
            $table->timestamps(6);
        });

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'promotion_discount')) {
                $table->decimal('promotion_discount', 14, 2)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('sales', 'promotion_code')) {
                $table->string('promotion_code', 64)->nullable()->after('promotion_discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            foreach (['promotion_discount', 'promotion_code'] as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotion_warehouse');
        Schema::dropIfExists('promotions');
    }
};
