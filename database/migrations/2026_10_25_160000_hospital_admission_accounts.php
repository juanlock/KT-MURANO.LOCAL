<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital phase 2 (user's analysis, 2026-10-25): the administrative side of an
 * admission and its account.
 *  - admissions: origin (ER / elective surgery / outpatient / referral...), the
 *    admission order (doctor + time: the wait until the bed is measured),
 *    coverage (self-pay / private insurer / public; policy, authorization and
 *    its status, coverage %, copay, deductible), signed consents (JSON), the
 *    guarantee deposit, billing mode (fee for service / DRG fixed rate), the
 *    account closed (invoice).
 *  - hms_drg: the company's fixed rates by diagnosis group.
 *  - hospital_invoices: the insurer / patient split, payer data, payment plan.
 *  - hospital_payments.payer (patient | insurer).
 *  - billed_invoice_id on lab orders, dispenses and surgeries: billed once.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $t) {
            $add = fn ($c, $fn) => Schema::hasColumn('admissions', $c) ? null : $fn();
            $add('origin', fn () => $t->string('origin', 24)->nullable()->after('department_id'));
            $add('ordered_by', fn () => $t->unsignedBigInteger('ordered_by')->nullable()->after('origin'));
            $add('ordered_at', fn () => $t->dateTime('ordered_at')->nullable()->after('ordered_by'));
            $add('payer_type', fn () => $t->string('payer_type', 16)->nullable()->after('ordered_at'));
            $add('insurer', fn () => $t->string('insurer', 120)->nullable()->after('payer_type'));
            $add('policy_number', fn () => $t->string('policy_number', 80)->nullable()->after('insurer'));
            $add('authorization_no', fn () => $t->string('authorization_no', 80)->nullable()->after('policy_number'));
            $add('authorization_status', fn () => $t->string('authorization_status', 16)->nullable()->after('authorization_no'));
            $add('coverage_percent', fn () => $t->decimal('coverage_percent', 5, 2)->nullable()->after('authorization_status'));
            $add('copay', fn () => $t->decimal('copay', 12, 2)->nullable()->after('coverage_percent'));
            $add('deductible', fn () => $t->decimal('deductible', 12, 2)->nullable()->after('copay'));
            $add('consents', fn () => $t->json('consents')->nullable()->after('deductible'));
            $add('deposit_amount', fn () => $t->decimal('deposit_amount', 12, 2)->nullable()->after('consents'));
            $add('deposit_method', fn () => $t->string('deposit_method', 32)->nullable()->after('deposit_amount'));
            $add('deposit_at', fn () => $t->dateTime('deposit_at')->nullable()->after('deposit_method'));
            $add('billing_mode', fn () => $t->string('billing_mode', 8)->nullable()->after('deposit_at'));
            $add('drg_id', fn () => $t->unsignedBigInteger('drg_id')->nullable()->after('billing_mode'));
            $add('account_invoice_id', fn () => $t->unsignedBigInteger('account_invoice_id')->nullable()->after('drg_id'));
        });

        if (! Schema::hasTable('hms_drg')) {
            Schema::create('hms_drg', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('code', 24);
                $t->string('name', 160);
                $t->decimal('price', 12, 2)->default(0);
                $t->unsignedSmallInteger('avg_days')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
            });
        }

        Schema::table('hospital_invoices', function (Blueprint $t) {
            $add = fn ($c, $fn) => Schema::hasColumn('hospital_invoices', $c) ? null : $fn();
            $add('payer_type', fn () => $t->string('payer_type', 16)->nullable()->after('status'));
            $add('insurer', fn () => $t->string('insurer', 120)->nullable()->after('payer_type'));
            $add('authorization_no', fn () => $t->string('authorization_no', 80)->nullable()->after('insurer'));
            $add('insurer_amount', fn () => $t->decimal('insurer_amount', 12, 2)->default(0)->after('authorization_no'));
            $add('patient_amount', fn () => $t->decimal('patient_amount', 12, 2)->nullable()->after('insurer_amount'));
            $add('payment_plan', fn () => $t->json('payment_plan')->nullable()->after('patient_amount'));
        });
        if (! Schema::hasColumn('hospital_payments', 'payer')) {
            Schema::table('hospital_payments', fn (Blueprint $t) => $t->string('payer', 16)->default('patient')->after('method'));
        }
        foreach (['lab_orders', 'hms_dispenses', 'hms_surgeries'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'billed_invoice_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('billed_invoice_id')->nullable()->index());
            }
        }
        // Lab orders already billed on their own invoice count as billed.
        if (Schema::hasColumn('lab_orders', 'billed_invoice_id')) {
            foreach (DB::table('hospital_invoices')->whereNotNull('lab_order_id')->whereNull('deleted_at')->get(['id', 'lab_order_id']) as $i) {
                DB::table('lab_orders')->where('id', $i->lab_order_id)->whereNull('billed_invoice_id')->update(['billed_invoice_id' => $i->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hms_drg');
    }
};
