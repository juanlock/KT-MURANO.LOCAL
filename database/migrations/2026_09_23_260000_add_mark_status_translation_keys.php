<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New Status-tag quick actions on a Pending sale (SalesController@markOrdered
 * / @markCompleted) -- confirmation dialog copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'Confirm_Mark_Ordered' => [
                'en' => 'This will move the sale to Ordered. No stock or accounting changes either way.',
                'es' => 'Esto pasará la venta a Pedido. No hay cambios de stock ni contables.',
            ],
            'Confirm_Mark_Completed' => [
                'en' => 'This will complete the sale, deduct stock, and set its Payment Status to Pending (no payment is collected through this shortcut) -- use the full Edit screen if payment should be recorded now.',
                'es' => 'Esto completará la venta, descontará el stock y pondrá su Estado de Pago en Pendiente (no se registra ningún pago con este acceso rápido) -- usa la pantalla de edición completa si el pago debe registrarse ahora.',
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
        DB::table('translations')->whereIn('key', ['Confirm_Mark_Ordered', 'Confirm_Mark_Completed'])->delete();
    }
};
