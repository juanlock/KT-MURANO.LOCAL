<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Spanish rows of the email and SMS templates still held the English text, so a Spanish company (Turismo Cautivo and
 * 14 more) wrote to its customers in English. Only the install's pool (AdminSystem, 999) and only a row that still equals
 * the English one or doesn't exist: a text someone already wrote is left alone, a company's own templates too. Safe to run
 * twice. down() leaves them: restoring English would not be a fix.
 */
return new class extends Migration
{
    private const EMAILS = [
        'sale' => ['¡Gracias por su compra!', '<p><b>Estimado(a) {contact_name}:</b></p><p>¡Gracias por su compra! Su número de comprobante es {invoice_number}.</p><p>Si tiene alguna pregunta o inquietud, no dude en escribirnos. Estamos para ayudarle.</p><p>Atentamente,</p><p><b>{business_name}</b></p>'],
        'quotation' => ['Gracias por su interés en nuestros productos', '<p><b>Estimado(a) {contact_name}:</b></p><p>Gracias por su interés en nuestros productos. Su número de cotización es {quotation_number}.</p><p>Si tiene alguna pregunta sobre su cotización, avísenos. Estamos para ayudarle.</p><p>Atentamente,</p><p><b>{business_name}</b></p>'],
        'payment_received' => ['Pago recibido. Gracias', '<p><b>Estimado(a) {contact_name}:</b></p><p>Gracias por su pago. Lo hemos recibido y se procesó correctamente.</p><p>Si tiene alguna otra pregunta, no dude en escribirnos. Estamos para ayudarle.</p><p>Atentamente,</p><p><b>{business_name}</b></p>'],
        'purchase' => ['Gracias por su colaboración y servicio', '<p><b>Estimado(a) {contact_name}:</b></p><p>Hemos realizado una compra a su empresa y queremos agradecerle su colaboración y servicio. Nuestro número de comprobante es {invoice_number}.</p><p>Si tiene alguna pregunta sobre esta compra, no dude en escribirnos.</p><p>Atentamente,</p><p><b>{business_name}</b></p>'],
        'payment_sent' => ['Pago enviado. Gracias por su servicio', '<p><b>Estimado(a) {contact_name}:</b></p><p>Acabamos de enviar el pago. Agradecemos su atención y la calidad de su servicio.</p><p>Si necesita más información, no dude en escribirnos.</p><p>Atentamente,</p><p><b>{business_name}</b></p>'],
        'booking' => ['Confirmación de su reserva', '<p><b>Estimado(a) {contact_name}:</b></p><p>Su reserva ha sido confirmada. Número de reserva: {booking_number}.</p><p>Fecha: {booking_date}<br>Hora: {start_time} - {end_time}<br>Servicio: {service_name}</p><p>Si tiene alguna pregunta, no dude en contactarnos.</p><p>Atentamente,</p><p><b>{business_name}</b></p>'],
        'asset_validation_due' => ['Validación de activo pendiente: {asset_name}', '<p>El siguiente activo debe validarse pronto o ya está vencido. Verifique la herramienta o el equipo.</p><p><b>Activo:</b> {asset_name} ({asset_tag})<br><b>Próxima validación:</b> {next_validation}</p><p><a href="{asset_edit_url}">Ver o editar el activo</a></p><p>Atentamente,<br><b>{business_name}</b></p>'],
        'payment_reminder' => ['Recordatorio de pago: comprobante {invoice_number} — {business_name}', '<p>Hola {contact_name}:</p><p>Le recordamos que el comprobante <strong>{invoice_number}</strong> de {business_name} aún tiene saldo pendiente.</p><p>Importe total: {total_amount}<br>Pagado: {paid_amount}<br><strong>Saldo pendiente: {due_amount}</strong></p><p>Puede ver el comprobante aquí: <a href="{invoice_url}">{invoice_url}</a></p><p>Por favor realice el pago lo antes posible. Si ya lo hizo, ignore este recordatorio.</p><p>Gracias,<br>{business_name}</p>'],
    ];

    private const SMS = [
        'sale' => "Estimado(a) {contact_name}:\n¡Gracias por su compra! Su número de comprobante es {invoice_number}.\nSi tiene alguna pregunta o inquietud, escríbanos. Estamos para ayudarle.\nAtentamente,\n{business_name}",
        'purchase' => "Estimado(a) {contact_name}:\nHemos realizado una compra a su empresa y queremos agradecerle su colaboración y servicio. Nuestro número de comprobante es {invoice_number}.\nSi tiene alguna pregunta sobre esta compra, escríbanos.\nAtentamente,\n{business_name}",
        'quotation' => "Estimado(a) {contact_name}:\nGracias por su interés en nuestros productos. Su número de cotización es {quotation_number}.\nSi tiene alguna pregunta sobre su cotización, avísenos.\nAtentamente,\n{business_name}",
        'payment_received' => "Estimado(a) {contact_name}:\nGracias por su pago. Lo hemos recibido y se procesó correctamente.\nSi tiene alguna otra pregunta, escríbanos.\nAtentamente,\n{business_name}",
        'payment_sent' => "Estimado(a) {contact_name}:\nAcabamos de enviar el pago. Agradecemos su atención y la calidad de su servicio.\nSi necesita más información, escríbanos.\nAtentamente,\n{business_name}",
        'subscription_reminder' => "Hola {client_name}:\n{business_name} le recuerda que su suscripción se renovará automáticamente el {next_billing_date}.\nVerifique que su medio de pago esté al día para evitar interrupciones.\n¡Gracias!",
        'asset_validation_due' => 'Recordatorio de {business_name}: el activo {asset_name} ({asset_tag}) debe validarse el {next_validation}. Verifique la herramienta o el equipo.',
    ];

    public function up(): void
    {
        if (Schema::hasTable('email_messages')) {
            foreach (self::EMAILS as $name => [$subject, $body]) {
                $pool = DB::table('email_messages')->where('company_id', 999)->where('name', $name)->whereNull('deleted_at');
                $en = (clone $pool)->where('locale', 'en')->first();
                $es = (clone $pool)->where('locale', 'es')->first();
                if (! $es) {
                    DB::table('email_messages')->insert(['company_id' => 999, 'name' => $name, 'locale' => 'es', 'subject' => $subject, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
                } elseif ($en && $es->body === $en->body) {
                    DB::table('email_messages')->where('id', $es->id)->update(['subject' => $subject, 'body' => $body, 'updated_at' => now()]);
                }
            }
        }
        if (Schema::hasTable('sms_messages')) {
            foreach (self::SMS as $name => $text) {
                $en = DB::table('sms_messages')->where('name', $name)->where('locale', 'en')->whereNull('deleted_at')->value('text');
                DB::table('sms_messages')->where('name', $name)->where('locale', 'es')->whereNull('deleted_at')->where('text', $en)->update(['text' => $text, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
