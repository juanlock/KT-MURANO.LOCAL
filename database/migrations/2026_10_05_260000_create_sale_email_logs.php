<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every invoice / payment reminder / POS receipt emailed to a customer, per
 * sale (App\Services\SaleEmailLog): who sent it, when, to which addresses,
 * ok or failed. Kept whether or not Email Office is on; office_email_id links
 * the copy Email Office logged (Email Office -> Sent), when there is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_email_logs')) {
            return;
        }
        Schema::create('sale_email_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->nullable()->index();
            $table->unsignedInteger('sale_id')->index();
            $table->string('kind', 12); // invoice | reminder | receipt
            $table->text('recipients')->nullable(); // "a@x.com, b@y.com"
            $table->string('subject', 998)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('sent_at')->index();
            $table->string('status', 10)->default('sending'); // sending | ok | failed
            $table->text('error')->nullable();
            $table->unsignedBigInteger('office_email_id')->nullable();
            $table->timestamps();
            $table->index(['sale_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_email_logs');
    }
};
