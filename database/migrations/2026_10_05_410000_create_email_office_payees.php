<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office -> who a company pays by Zelle ("Confirmed: Your payment to
 * <payee>"): the payee as written by the bank -> an employee (1099
 * contractor) and / or the expense category its payments go to. Filled the
 * first time a payee is confirmed, then reused (App\Services\EmailOffice\ZellePayment).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_office_payees')) {
            Schema::create('email_office_payees', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->string('payee', 190); // lower case, spaces collapsed
                $table->unsignedInteger('employee_id')->nullable();
                $table->unsignedInteger('expense_category_id');
                $table->timestamps();
                $table->unique(['company_id', 'payee']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_office_payees');
    }
};
