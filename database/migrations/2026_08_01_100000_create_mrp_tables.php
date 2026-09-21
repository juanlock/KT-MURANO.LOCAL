<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced Manufacturing MRP.
 *
 * Everything is additive — no existing table is altered — so nothing already
 * working can break. The module reaches into inventory, purchasing, sales, HRM
 * and accounting through their own tables at runtime rather than by changing
 * their shape.
 *
 * The costing model is deliberate: a production order accumulates material,
 * labour and overhead as it runs, and only divides by the good quantity at
 * completion. Scrap is therefore absorbed by the units that survived, which is
 * how a unit cost stays honest — spreading cost over units that were thrown
 * away would understate what the finished goods actually cost to make.
 */
return new class extends Migration
{
    public function up()
    {
        // --- where work happens ----------------------------------------------
        Schema::create('mrp_work_centers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            // Units the centre can turn out per hour, at 100% efficiency.
            $table->double('capacity_per_hour', 15, 4)->default(0);
            $table->double('hourly_cost', 15, 4)->default(0);
            $table->double('overhead_rate', 15, 4)->default(0);   // per hour, on top of labour
            // Real-world throughput as a share of nominal capacity.
            $table->unsignedInteger('efficiency_pct')->default(100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['is_active', 'warehouse_id']);
        });

        // --- what a product is made of ---------------------------------------
        Schema::create('mrp_boms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            // Quantity this recipe yields; component quantities are per this
            // figure, not per single unit, so "makes 12 loaves" stays readable.
            $table->double('output_qty', 15, 4)->default(1);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');       // draft|active|archived
            // Only one active default per product, enforced in the service.
            $table->boolean('is_default')->default(false);
            $table->double('scrap_pct', 8, 4)->default(0);        // expected loss on the output
            $table->double('overhead_cost', 15, 4)->default(0);   // fixed, per run
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['product_id', 'status']);
        });

        Schema::create('mrp_bom_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bom_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->double('qty', 15, 4)->default(0);             // per output_qty
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->double('scrap_pct', 8, 4)->default(0);        // expected loss on this component
            $table->boolean('is_optional')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('bom_id');
            $table->index('product_id');
        });

        Schema::create('mrp_bom_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bom_id');
            $table->unsignedInteger('sequence')->default(1);
            $table->string('name');
            $table->unsignedBigInteger('work_center_id')->nullable();
            $table->double('setup_minutes', 15, 4)->default(0);   // once per run
            $table->double('run_minutes_per_unit', 15, 4)->default(0);
            $table->boolean('requires_qc')->default(false);
            $table->text('instructions')->nullable();
            $table->timestamps();

            $table->index(['bom_id', 'sequence']);
        });

        // --- the order to make something -------------------------------------
        Schema::create('mrp_production_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference')->unique();
            $table->unsignedBigInteger('bom_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();

            $table->double('qty_planned', 15, 4)->default(0);
            $table->double('qty_produced', 15, 4)->default(0);
            $table->double('qty_scrapped', 15, 4)->default(0);

            // Materials leave from one warehouse; finished goods land in another
            // (often the same one, but a separate FG store is common).
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('fg_warehouse_id')->nullable();

            // draft|planned|released|in_progress|completed|cancelled
            $table->string('status', 20)->default('draft');
            $table->string('priority', 10)->default('normal');    // low|normal|high|urgent

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();

            // Accumulated as the order runs; unit_cost is only meaningful once
            // the order completes and a good quantity exists to divide by.
            $table->double('material_cost', 15, 4)->default(0);
            $table->double('labour_cost', 15, 4)->default(0);
            $table->double('overhead_cost', 15, 4)->default(0);
            $table->double('total_cost', 15, 4)->default(0);
            $table->double('unit_cost', 15, 4)->default(0);
            $table->double('planned_cost', 15, 4)->default(0);    // for variance reporting

            // Where the demand came from, when it came from somewhere.
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('planning_run_id')->nullable();

            $table->boolean('materials_issued')->default(false);
            $table->boolean('qc_required')->default(false);
            $table->boolean('qc_passed')->default(false);
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['status', 'planned_start']);
            $table->index('product_id');
            $table->index('warehouse_id');
        });

        Schema::create('mrp_production_order_materials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('production_order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->double('qty_required', 15, 4)->default(0);
            $table->double('qty_issued', 15, 4)->default(0);
            $table->double('qty_returned', 15, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            // Snapshot of the cost at issue time — a later price change must not
            // silently rewrite what a finished batch cost to build.
            $table->double('unit_cost', 15, 4)->default(0);
            $table->double('total_cost', 15, 4)->default(0);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->index('production_order_id');
            $table->index('product_id');
        });

        // --- shop-floor execution ---------------------------------------------
        Schema::create('mrp_work_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('production_order_id');
            $table->unsignedBigInteger('bom_operation_id')->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('name');
            $table->unsignedBigInteger('work_center_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();

            // pending|in_progress|completed|skipped
            $table->string('status', 20)->default('pending');
            $table->double('planned_minutes', 15, 4)->default(0);
            $table->double('actual_minutes', 15, 4)->default(0);
            $table->double('qty_completed', 15, 4)->default(0);
            $table->double('qty_rejected', 15, 4)->default(0);

            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->double('labour_cost', 15, 4)->default(0);
            $table->double('overhead_cost', 15, 4)->default(0);
            $table->boolean('requires_qc')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'sequence']);
            $table->index(['status', 'work_center_id']);
        });

        // --- quality control ---------------------------------------------------
        Schema::create('mrp_quality_checks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference')->unique();
            $table->unsignedBigInteger('production_order_id');
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->string('type', 20)->default('final');         // in_process|final
            // pending|passed|failed|partial
            $table->string('status', 20)->default('pending');
            $table->double('qty_inspected', 15, 4)->default(0);
            $table->double('qty_passed', 15, 4)->default(0);
            $table->double('qty_rejected', 15, 4)->default(0);
            $table->unsignedBigInteger('inspector_id')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['production_order_id', 'status']);
        });

        Schema::create('mrp_quality_check_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quality_check_id');
            $table->string('parameter');
            $table->string('expected')->nullable();
            $table->string('actual')->nullable();
            $table->string('result', 10)->default('pass');        // pass|fail
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('quality_check_id');
        });

        // --- the MRP run itself -------------------------------------------------
        Schema::create('mrp_planning_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference')->unique();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->date('horizon_start')->nullable();
            $table->date('horizon_end')->nullable();
            $table->string('status', 20)->default('completed');   // running|completed|failed
            $table->unsignedInteger('demand_lines')->default(0);
            $table->unsignedInteger('make_suggestions')->default(0);
            $table->unsignedInteger('buy_suggestions')->default(0);
            $table->boolean('include_safety_stock')->default(true);
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('mrp_planning_suggestions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('planning_run_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('action', 10);                         // make|buy
            // How the number was arrived at, so a planner can audit it rather
            // than trust it.
            $table->double('gross_requirement', 15, 4)->default(0);
            $table->double('on_hand', 15, 4)->default(0);
            $table->double('incoming', 15, 4)->default(0);        // open POs + open MOs
            $table->double('safety_stock', 15, 4)->default(0);
            $table->double('net_requirement', 15, 4)->default(0);
            $table->double('suggested_qty', 15, 4)->default(0);
            $table->unsignedInteger('level')->default(0);         // BOM explosion depth
            $table->unsignedBigInteger('bom_id')->nullable();
            $table->date('required_by')->nullable();
            // pending|accepted|dismissed
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('created_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['planning_run_id', 'action']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mrp_planning_suggestions');
        Schema::dropIfExists('mrp_planning_runs');
        Schema::dropIfExists('mrp_quality_check_lines');
        Schema::dropIfExists('mrp_quality_checks');
        Schema::dropIfExists('mrp_work_orders');
        Schema::dropIfExists('mrp_production_order_materials');
        Schema::dropIfExists('mrp_production_orders');
        Schema::dropIfExists('mrp_bom_operations');
        Schema::dropIfExists('mrp_bom_lines');
        Schema::dropIfExists('mrp_boms');
        Schema::dropIfExists('mrp_work_centers');
    }
};
