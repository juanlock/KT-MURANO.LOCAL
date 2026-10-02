<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts split in two (user's request, 2026-10-02):
 *  - `contracts` = EMPLOYEE contracts only (HRM > Employee Contracts); gets
 *    company_id (multicompany scope, filled from each contract's party).
 *  - `sales_contracts` (+ items, + generated invoices) = CUSTOMER contracts
 *    (Sales > Sales Contracts) with recurring invoices.
 * Customer rows already in `contracts` move to `sales_contracts` (their
 * attachments / comments / notes / renewals / tasks are copied into the
 * sales contract's notes only when present -- none existed at split time).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'company_id')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->unsignedInteger('company_id')->nullable()->after('id')->index();
            });
        }

        if (! Schema::hasTable('sales_contracts')) {
            Schema::create('sales_contracts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('company_id')->index();
                $table->string('contract_number', 50)->unique();
                $table->unsignedInteger('client_id')->index();
                $table->unsignedInteger('warehouse_id')->nullable();
                $table->string('subject');
                $table->string('type', 50)->nullable();
                $table->decimal('value', 16, 2)->default(0);
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft'); // draft, active, expired, cancelled
                $table->boolean('hide_from_customer')->default(false);
                $table->string('signer_name')->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->string('signed_ip', 45)->nullable();
                // Recurring invoices
                $table->boolean('billing_enabled')->default(false);
                $table->string('billing_frequency', 20)->default('monthly'); // weekly, monthly, quarterly, yearly
                $table->unsignedSmallInteger('billing_interval')->default(1);
                $table->date('next_invoice_date')->nullable();
                $table->date('last_invoice_date')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('sales_contract_items')) {
            Schema::create('sales_contract_items', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('sales_contract_id')->index();
                $table->unsignedInteger('product_id')->nullable();
                $table->string('name');
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('price', 16, 3)->default(0);
                $table->decimal('tax_percent', 8, 3)->default(0);
                $table->string('tax_method', 2)->default('1'); // 1 exclusive, 2 inclusive
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_contract_invoices')) {
            Schema::create('sales_contract_invoices', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('sales_contract_id')->index();
                $table->unsignedInteger('sale_id')->index();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->boolean('automatic')->default(false);
                $table->timestamps();
            });
        }

        // Company of every existing contract (from its employee / customer).
        DB::statement('UPDATE contracts c LEFT JOIN employees e ON e.id = c.employee_id LEFT JOIN clients cl ON cl.id = c.client_id
            SET c.company_id = COALESCE(e.company_id, cl.company_id) WHERE c.company_id IS NULL');

        // Customer contracts move to Sales Contracts.
        foreach (DB::table('contracts')->where(function ($q) {
            $q->where('party_type', 'customer')->orWhere(fn ($q) => $q->whereNull('employee_id')->whereNotNull('client_id'));
        })->get() as $c) {
            $number = DB::table('sales_contracts')->where('contract_number', $c->contract_number)->exists()
                ? $c->contract_number.'-S' : $c->contract_number;
            DB::table('sales_contracts')->insert([
                'company_id' => (int) $c->company_id,
                'contract_number' => $number,
                'client_id' => (int) $c->client_id,
                'subject' => $c->subject,
                'type' => $c->type,
                'value' => $c->value,
                'start_date' => $c->start_date,
                'end_date' => $c->end_date,
                'description' => $c->description,
                'status' => $c->status ?: 'draft',
                'hide_from_customer' => (bool) $c->hide_from_customer,
                'signer_name' => $c->signer_name,
                'signed_at' => $c->signed_at,
                'signed_ip' => $c->signed_ip,
                'created_at' => $c->created_at,
                'updated_at' => now(),
                'deleted_at' => $c->deleted_at,
            ]);
            $children = 0;
            foreach (['contract_attachments', 'contract_comments', 'contract_renewals', 'contract_notes', 'contract_tasks'] as $t) {
                $children += Schema::hasTable($t) ? DB::table($t)->where('contract_id', $c->id)->count() : 0;
            }
            // Only rows with nothing attached are removed from `contracts`;
            // anything with history stays (soft-deleted) so it can be reviewed.
            if ($children === 0) {
                DB::table('contracts')->where('id', $c->id)->delete();
            } else {
                DB::table('contracts')->where('id', $c->id)->update(['deleted_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_contract_invoices');
        Schema::dropIfExists('sales_contract_items');
        Schema::dropIfExists('sales_contracts');
        if (Schema::hasColumn('contracts', 'company_id')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('company_id');
            });
        }
    }
};
