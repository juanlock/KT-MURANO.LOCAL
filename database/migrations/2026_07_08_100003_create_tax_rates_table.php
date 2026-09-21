<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Location-based tax. Match by country (+ optional state/region).
        // A row with a state set is more specific and wins over a
        // country-only row for the same country.
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 120)->nullable();
            $table->string('country', 100);
            $table->string('state', 100)->nullable();
            $table->decimal('rate', 8, 3)->default(0); // percentage, e.g. 20.000
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country']);
            $table->index(['country', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
