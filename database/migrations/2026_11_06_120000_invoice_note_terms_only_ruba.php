<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice note and terms (credit card fee, $35 late fee, US phones) are Ruba's only (user's rule, 2026-11-06): every
 * other company and the template new companies are cloned from stay empty. Only what is still those texts changes; safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ruba = (int) DB::table('companies')->where('name', 'like', 'Ruba Garden%')->value('id');
        if (! $ruba) {
            return;
        }
        DB::table('settings')->where('company_id', '<>', $ruba)->where('sale_pdf_company_note', 'like', 'We accept credit card%')
            ->update(['sale_pdf_company_note' => null]);
        DB::table('settings')->where('company_id', '<>', $ruba)
            ->where(fn ($q) => $q->where('sale_pdf_terms_conditions', 'like', 'Make all checks payable%')
                ->orWhere('sale_pdf_terms_conditions', 'Si tiene alguna consulta sobre este comprobante, comuníquese con nosotros.'))
            ->update(['sale_pdf_terms_conditions' => null]);
    }

    public function down(): void
    {
        // Left on purpose: those texts were never these companies'.
    }
};
