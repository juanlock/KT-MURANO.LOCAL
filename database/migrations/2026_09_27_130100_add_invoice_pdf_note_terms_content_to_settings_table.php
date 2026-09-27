<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'sale_pdf_company_note')) {
                $table->text('sale_pdf_company_note')->nullable();
            }
            if (! Schema::hasColumn('settings', 'sale_pdf_terms_conditions')) {
                $table->text('sale_pdf_terms_conditions')->nullable();
            }
        });

        // Seed every existing company row with the requested default wording
        // (text() columns can't carry a schema-level default in MySQL, so this
        // backfill is the equivalent one-time step -- same approach used for
        // the company_id backfill earlier this session).
        DB::table('settings')->whereNull('sale_pdf_company_note')->update([
            'sale_pdf_company_note' => 'We accept credit card for payment with 3.5% extra fee from the total amount, '
                .'please call our office for more information (973)841-3779, also Late fee charge of $35.00 will be '
                .'applied after 30 days from the invoice date.',
        ]);
        DB::table('settings')->whereNull('sale_pdf_terms_conditions')->update([
            'sale_pdf_terms_conditions' => "Make all checks payable to: {{COMPANY NAME}}\nMailing address: {{ADDRESS COMPANY}}\n"
                .'If you have any questions concerning this invoice, contact: Customer Service (973)934-7606 or '
                .'(973)841-3779. Pay us as soon as possible to avoid delay.',
        ]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'sale_pdf_company_note')) {
                $table->dropColumn('sale_pdf_company_note');
            }
            if (Schema::hasColumn('settings', 'sale_pdf_terms_conditions')) {
                $table->dropColumn('sale_pdf_terms_conditions');
            }
        });
    }
};
