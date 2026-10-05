<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office rules (per company): the user's own sorting, applied to every
 * new email after the automatic one (App\Services\EmailOffice\EmailRules).
 * conditions: [{field: from|from_name|subject|body|any, op: contains|is|ends_with, value}]
 * match: any | all. Actions: set_category (a type, e.g. social / spam / archive),
 * mark_read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_office_rules')) {
            return;
        }
        Schema::create('email_office_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('name', 120);
            $table->json('conditions');
            $table->string('match', 3)->default('any'); // any | all
            $table->string('set_category', 12)->nullable();
            $table->boolean('mark_read')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_office_rules');
    }
};
