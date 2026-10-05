<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defaults -> Email Office: which summary cards Email Office (Received /
 * Sent) shows -- a JSON list of the HIDDEN ones, "in:spam", "out:reminder",
 * "in:f12" (a company folder). Empty / null = every card shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'email_office_hidden_cards')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->text('email_office_hidden_cards')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'email_office_hidden_cards')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('email_office_hidden_cards');
            });
        }
    }
};
