<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Labels (en/es) for the booking appointment mode, "On completion" and
 * reminder settings; and KcolTech (company 1, IT services: appointments
 * employee <-> client) switched to them: appointment mode on, sale left
 * pending payment on completion, reminders 24 h and 1 h before.
 */
return new class extends Migration
{
    private const KEYS = [
        'Appointment_Mode' => ['Appointment mode', 'Modalidad de la cita'],
        'Appointment_Mode_Setting_Help' => [
            'Show "Appointment mode" on the booking form: at the office, remote with a meeting link, or at the customer\'s site with its address.',
            'Muestra "Modalidad de la cita" en el formulario de reservas: en la oficina, remota con un enlace de reunión, o en el local del cliente con su dirección.',
        ],
        'Mode_Office' => ['At our office', 'En la oficina'],
        'Mode_Remote' => ['Remote (online)', 'Remota (en línea)'],
        'Mode_Onsite' => ['At the customer\'s site', 'En el local del cliente'],
        'Meeting_Link' => ['Meeting link', 'Enlace de la reunión'],
        'Meeting_Link_Help' => ['Zoom, Google Meet, Teams... It goes in the emails and reminders.', 'Zoom, Google Meet, Teams... Va en los correos y recordatorios.'],
        'Site_Address' => ['Site address', 'Dirección del lugar'],
        'Use_Customer_Address' => ['Use the customer\'s address', 'Usar la dirección del cliente'],
        'Join_Meeting' => ['Join meeting', 'Unirse a la reunión'],
        'Booking_On_Completion' => ['On completion', 'Al completar'],
        'Booking_On_Completion_Help' => [
            'What happens when a booking is marked Completed.',
            'Qué pasa cuando una reserva se marca como Completada.',
        ],
        'Invoice_Cash' => ['Create the sale, paid in cash', 'Crear la venta, pagada en efectivo'],
        'Invoice_Unpaid' => ['Create the sale, pending payment (on credit)', 'Crear la venta, pendiente de pago (a crédito)'],
        'Invoice_None' => ['Don\'t create a sale (e.g. covered by a contract)', 'No crear venta (por ejemplo, la cubre un contrato)'],
        'Booking_Reminders' => ['Appointment reminders', 'Recordatorios de citas'],
        'Booking_Reminders_Help' => [
            'Email the customer and the staff member before each pending or confirmed appointment.',
            'Envía un correo al cliente y al empleado antes de cada cita pendiente o confirmada.',
        ],
        'Reminder_Hours_Before' => ['Hours before', 'Horas antes'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert([
                    'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        forget_db_translations();

        DB::table('settings')->where('company_id', 1)->update([
            'booking_mode_enabled' => 1,
            'booking_invoice_mode' => 'unpaid',
            'booking_reminders_enabled' => 1,
            'booking_reminder_hours' => '24,1',
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->whereIn('key', array_keys(self::KEYS))->delete();
        forget_db_translations();
    }
};
