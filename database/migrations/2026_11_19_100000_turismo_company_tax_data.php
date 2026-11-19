<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo's company data from SUNAT (the client's answer to Req01, 2026-11-19): RUC 20601601282, TURISMO CAUTIVO
 * S.A.C. (sociedad anónima cerrada), domicilio fiscal Cal. Los Insurgentes Nro. 221 Urb. Santa Constanza, Santiago de Surco,
 * Lima; activity 4922 (otras actividades de transporte por vía terrestre); electronic receipts since 2019 (Factura / Boleta
 * from SUNAT's portal). The RUC goes in settings.vat_number and the fiscal address in CompanyAdress (what the invoices show);
 * the trade name "Turismo Cautivo" stays. Safe to run twice; by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        DB::table('settings')->where('company_id', $cid)->update([
            'vat_number' => '20601601282',
            'CompanyAdress' => 'Cal. Los Insurgentes Nro. 221 Urb. Santa Constanza, Santiago de Surco, Lima',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
    }
};
