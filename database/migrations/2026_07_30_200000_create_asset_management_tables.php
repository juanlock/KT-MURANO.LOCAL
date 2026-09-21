<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Management: turns the flat asset register into a lifecycle.
 *
 * The existing `assets` table records what an asset IS. What was missing is
 * everything that happens to it afterwards — who holds it, what it cost to
 * keep running, where it moved, what it is still worth and what it fetched
 * when it left. Each of those is a log, not a column, because the history is
 * the point: "who had the laptop in March" cannot be answered by a single
 * `assigned_to_id`.
 */
return new class extends Migration
{
    public function up()
    {
        // --- lifecycle columns on the asset itself ---------------------------
        Schema::table('assets', function (Blueprint $table) {
            $table->string('supplier')->nullable()->after('purchase_cost');
            $table->date('warranty_expiry')->nullable()->after('supplier');

            // Depreciation inputs. Nullable throughout: an asset with no useful
            // life set simply never depreciates, which is the right behaviour
            // for the rows that already exist.
            $table->string('depreciation_method')->default('none')->after('warranty_expiry');
            $table->unsignedInteger('useful_life_months')->nullable()->after('depreciation_method');
            $table->double('salvage_value', 15, 2)->nullable()->after('useful_life_months');

            // Disposal. Set together, and once set they freeze the book value.
            $table->date('disposal_date')->nullable()->after('salvage_value');
            $table->double('disposal_amount', 15, 2)->nullable()->after('disposal_date');
            $table->string('disposal_note')->nullable()->after('disposal_amount');

            $table->index('disposal_date');
        });

        // --- custody ---------------------------------------------------------
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('user_id');
            $table->date('assigned_on');
            $table->date('due_back_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->string('condition_out')->nullable();
            $table->string('condition_in')->nullable();
            $table->text('notes')->nullable();
            // assigned | returned. "Overdue" is derived from due_back_on, never
            // stored — a stored flag would go stale the moment a date passes.
            $table->string('status')->default('assigned');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['asset_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('due_back_on');
        });

        // --- upkeep ----------------------------------------------------------
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->string('type')->default('service');
            $table->string('title');
            $table->string('vendor')->nullable();
            $table->date('scheduled_date');
            $table->date('completed_date')->nullable();
            $table->double('cost', 15, 2)->default(0);
            $table->date('next_due_date')->nullable();
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['asset_id', 'status']);
            $table->index('scheduled_date');
            $table->index('next_due_date');
        });

        // --- movement --------------------------------------------------------
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('from_warehouse_id')->nullable();
            $table->unsignedBigInteger('to_warehouse_id');
            $table->date('transfer_date');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['asset_id', 'transfer_date']);
            $table->index('to_warehouse_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('asset_transfers');
        Schema::dropIfExists('asset_maintenances');
        Schema::dropIfExists('asset_assignments');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['disposal_date']);
            $table->dropColumn([
                'supplier',
                'warranty_expiry',
                'depreciation_method',
                'useful_life_months',
                'salvage_value',
                'disposal_date',
                'disposal_amount',
                'disposal_note',
            ]);
        });
    }
};
