<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office: the company's own folders (types), created from the rules'
 * "Move to" list -- e.g. "Newsletters", "Banks". An email in one carries
 * category = "f{id}" (office_emails.category is short); like Social /
 * Archive / Spam they're out of the normal list, with their own card.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_office_folders')) {
            return;
        }
        Schema::create('email_office_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('name', 60);
            $table->string('color', 20)->default('purple');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_office_folders');
    }
};
