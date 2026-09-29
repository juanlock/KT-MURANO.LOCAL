<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Supplier counterpart of clients.additional_emails: extra recipient
// addresses (JSON array), filled from the Purchases list Send Email modal
// and editable on the Supplier form.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'additional_emails')) {
                $table->json('additional_emails')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (Schema::hasColumn('providers', 'additional_emails')) {
                $table->dropColumn('additional_emails');
            }
        });
    }
};
