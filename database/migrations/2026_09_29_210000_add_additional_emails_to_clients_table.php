<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extra recipient addresses for a customer (JSON array of strings), next to
// the single unique clients.email. Filled from the Sales list Send Email /
// Reminder recipient modal when a new address is typed there, and editable
// on the Customer form.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'additional_emails')) {
                $table->json('additional_emails')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'additional_emails')) {
                $table->dropColumn('additional_emails');
            }
        });
    }
};
