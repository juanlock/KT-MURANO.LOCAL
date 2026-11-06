<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo's invoices and receipts carried another company's texts in English: a credit card fee and a $35 late fee
 * with US phone numbers. Only what is still that text changes; safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $cid) {
            return;
        }
        DB::table('settings')->where('company_id', $cid)->where('sale_pdf_company_note', 'like', 'We accept credit card%')->update(['sale_pdf_company_note' => null]);
        DB::table('settings')->where('company_id', $cid)->where('sale_pdf_terms_conditions', 'like', 'Make all checks payable%')
            ->update(['sale_pdf_terms_conditions' => 'Si tiene alguna consulta sobre este comprobante, comuníquese con nosotros.']);
        DB::table('pos_settings')->where('company_id', $cid)->where('note_customer', 'like', 'Thank You For Shopping%')
            ->update(['note_customer' => 'Gracias por su compra. Vuelva pronto.']);
        DB::table('clients')->where('company_id', $cid)->where('name', 'Walk-in Customer')->update(['name' => 'Cliente de mostrador']);
    }

    public function down(): void
    {
        // Left on purpose: those texts were never this company's.
    }
};
