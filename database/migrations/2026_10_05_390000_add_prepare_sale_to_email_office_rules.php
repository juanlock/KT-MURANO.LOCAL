<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email rules: "also prepare a paid sale" (Zelle deposit emails, e.g. "We
 * deposited your payment"): {product_id, sales_agent_id}. A matching email
 * gets office_emails.link_role = 'sale_draft' and waits in Email Office for
 * Confirm (App\Services\EmailOffice\ZelleSale).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_office_rules', 'prepare_sale')) {
            Schema::table('email_office_rules', function (Blueprint $table) {
                $table->json('prepare_sale')->nullable()->after('mark_read');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_office_rules', 'prepare_sale')) {
            Schema::table('email_office_rules', function (Blueprint $table) {
                $table->dropColumn('prepare_sale');
            });
        }
    }
};
