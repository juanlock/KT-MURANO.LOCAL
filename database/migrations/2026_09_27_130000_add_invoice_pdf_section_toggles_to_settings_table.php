<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ([
                'sale_pdf_show_disc_column',
                'sale_pdf_show_tax_column',
                'sale_pdf_show_shipping_row',
                'sale_pdf_show_discount_row',
                'sale_pdf_show_previous_dues',
                'sale_pdf_show_company_note',
                'sale_pdf_show_terms_conditions',
            ] as $column) {
                if (! Schema::hasColumn('settings', $column)) {
                    $table->boolean($column)->default(true);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ([
                'sale_pdf_show_disc_column',
                'sale_pdf_show_tax_column',
                'sale_pdf_show_shipping_row',
                'sale_pdf_show_discount_row',
                'sale_pdf_show_previous_dues',
                'sale_pdf_show_company_note',
                'sale_pdf_show_terms_conditions',
            ] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
