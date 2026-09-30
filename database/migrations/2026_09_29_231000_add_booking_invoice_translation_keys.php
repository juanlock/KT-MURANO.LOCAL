<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Booking List -> Invoice column: button that creates the sale of a completed
 * booking that has none yet (BookingController@createSale).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'Create_Invoice' => [
                'en' => 'Create invoice',
                'es' => 'Crear factura',
            ],
            'Booking_create_invoice_help' => [
                'en' => 'Completed bookings are invoiced automatically (paid in cash). This one has no invoice yet -- usually because it has no service selected.',
                'es' => 'Las reservas completadas se facturan automáticamente (pagadas en efectivo). Esta aún no tiene factura -- normalmente porque no tiene un servicio seleccionado.',
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
        DB::table('translations')->whereIn('key', ['Create_Invoice', 'Booking_create_invoice_help'])->delete();
    }
};
