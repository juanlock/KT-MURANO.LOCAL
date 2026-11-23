<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * HORDEX: every product with "SERVICIO" in its name is of the Tipo "Servicios" (user, 2026-11-23: "mete ahí todos los que
 * tengan la palabra servicios dentro del tipo Servicios"). On 2026-11-23 only SERTDVG "SERVICIO DE TRANSPORTE DVG DE LOS
 * OLIVOS BOLSAS" was elsewhere (Insumos, as a stocked item); it becomes a service (category SER, type is_service, so it is
 * offered on SERVICIOS supplier invoices). Its purchases, receipts and kardex history are kept. By company name and the
 * category code. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'like', 'HORDEX%')->value('id');
        $ser = $cid ? DB::table('categories')->where('company_id', $cid)->where('code', 'SER')->whereNull('deleted_at')->value('id') : null;
        if (! $ser) {
            return;
        }
        DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('name', 'like', '%SERVICIO%')
            ->where(fn ($q) => $q->where('category_id', '!=', $ser)->orWhereNull('category_id')->orWhere('type', '!=', 'is_service'))
            ->update(['category_id' => $ser, 'type' => 'is_service', 'not_selling' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
