<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * office_emails.link_role was varchar(12): "payment_draft" (Zelle payment sent
 * waiting for Confirm, 13 chars) was cut to "payment_draf". Now 20.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('office_emails', 'link_role')) {
            DB::statement('ALTER TABLE office_emails MODIFY link_role VARCHAR(20) NULL');
            DB::table('office_emails')->where('link_role', 'payment_draf')->update(['link_role' => 'payment_draft']);
        }
    }

    public function down(): void
    {
        // Not narrowed back: values longer than 12 would be cut.
    }
};
