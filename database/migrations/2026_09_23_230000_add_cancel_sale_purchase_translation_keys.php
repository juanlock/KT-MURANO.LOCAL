<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New "Cancel" row action on a Pending sale/purchase (SalesController@cancel,
 * PurchasesController@cancel) -- confirmation dialog copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'Confirm_Cancel_Sale' => [
                'en' => 'This will disable the invoice (it stays on record but can no longer be edited or paid) and reverse any loyalty points it used or earned.',
                'es' => 'Esto deshabilitará la factura (permanece en el registro pero ya no podrá editarse ni pagarse) y revertirá los puntos de fidelidad que haya usado u obtenido.',
            ],
            'Confirm_Cancel_Purchase' => [
                'en' => 'This will disable the purchase (it stays on record but can no longer be edited or paid).',
                'es' => 'Esto deshabilitará la compra (permanece en el registro pero ya no podrá editarse ni pagarse).',
            ],
        ];

        foreach ($rows as $key => $translations) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('translations')->insert([
                'key' => $key,
                'is_default' => 1,
                'en' => $translations['en'],
                'es' => $translations['es'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('translations')->whereIn('key', [
            'Confirm_Cancel_Sale', 'Confirm_Cancel_Purchase',
        ])->delete();
    }
};
