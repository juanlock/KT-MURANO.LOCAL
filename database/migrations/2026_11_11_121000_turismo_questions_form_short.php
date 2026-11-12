<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo's questions (user's requests, 2026-11-11: "no quiero cansarlos"; only the owner and the supervisor
 * will answer, so one link; tabs; save the progress, "Guardar" and "Guardar y enviar" on top): the form of 65 questions
 * (tcpreguntas2026, 2026_11_10_170000) becomes 20 questions in four tabs coded by who answers them -- DUE (owner), GER (management),
 * SUP (agency supervisor), FLO (fleet) -- with who answers on top. Only what the client alone can answer and Murano needs to run with their real
 * operation; the rest is asked at the meeting or keeps our default. The previous fields are kept in the form's settings
 * (`fields_v1`) and down() puts them back. Not applied once the form has answers. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = 'tcpreguntas2026';

    public function up(): void
    {
        if (! Schema::hasTable('forms')) {
            return;
        }
        // Two separate forms were tried first on this PC only: gone if nobody answered them.
        foreach (['tcpagencias2026', 'tcpflota2026'] as $old) {
            $id = DB::table('forms')->where('public_key', $old)->value('id');
            if ($id && ! DB::table('form_submissions')->where('form_id', $id)->exists()) {
                DB::table('forms')->where('id', $id)->delete();
            }
        }
        $form = DB::table('forms')->where('public_key', self::KEY)->first(['id', 'fields', 'settings']);
        if (! $form || DB::table('form_submissions')->where('form_id', $form->id)->exists()) {
            return;
        }
        $settings = json_decode((string) $form->settings, true) ?: [];
        if (! isset($settings['fields_v1'])) {
            $settings['fields_v1'] = json_decode((string) $form->fields, true) ?: [];
        }
        $settings['title'] = 'Preguntas para Turismo Cautivo';
        $settings['description'] = 'Cuestionario Nro. 1 - NOV 2026';
        $settings['save_draft'] = true;
        $settings['hide_after_submit'] = false;
        $settings['button_position'] = 'top';
        $settings['submit_text'] = 'Guardar y enviar';
        $settings['success_message'] = '¡Gracias! Recibimos sus respuestas. Puede volver cuando quiera, completar las que faltan y enviarlas otra vez.';

        DB::table('forms')->where('id', $form->id)->update([
            'name' => 'Cuestionario Nro. 1 - NOV 2026',
            'fields' => json_encode(\App\Support\FormSchema::fields($this->fields()), JSON_UNESCAPED_UNICODE),
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('forms')) {
            return;
        }
        $form = DB::table('forms')->where('public_key', self::KEY)->first(['id', 'settings']);
        $settings = $form ? (json_decode((string) $form->settings, true) ?: []) : [];
        if (! $form || empty($settings['fields_v1']) || DB::table('form_submissions')->where('form_id', $form->id)->exists()) {
            return;
        }
        $old = $settings['fields_v1'];
        unset($settings['fields_v1'], $settings['save_draft'], $settings['button_position']);
        $settings['hide_after_submit'] = true;
        $settings['submit_text'] = 'Enviar respuestas';
        DB::table('forms')->where('id', $form->id)->update(['name' => 'Preguntas para la reunión del 15-11-2026',
            'fields' => json_encode($old, JSON_UNESCAPED_UNICODE), 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    private function fields(): array
    {
        $fields = [
            ['key' => 'h_responde', 'type' => 'heading', 'text' => 'Quién responde', 'level' => 3, 'width' => 'full'],
            ['key' => 'responde_nombre', 'type' => 'text', 'width' => 'half', 'label' => 'Nombre', 'required' => true, 'max' => 190, 'placeholder' => '', 'help' => ''],
            ['key' => 'responde_cargo', 'type' => 'text', 'width' => 'half', 'label' => 'Cargo', 'required' => false, 'max' => 190, 'placeholder' => '', 'help' => ''],
            ['key' => 'p_codigos', 'type' => 'paragraph', 'width' => 'full',
                'text' => 'Cada pestaña tiene un código según quién la responde: DUE = dueño, GER = gerencia y administración, SUP = supervisor de agencia, FLO = jefe de flota.'],
        ];
        foreach ($this->tabs() as $code => [$tab, $questions]) {
            $fields[] = ['key' => 'tab_'.strtolower($code), 'type' => 'tab', 'text' => "{$code} · {$tab}", 'width' => 'full'];
            foreach ($questions as $i => $q) {
                $n = $i + 1;
                $fields[] = $q + ['key' => strtolower($code).'_'.$n, 'type' => 'textarea', 'width' => 'full', 'rows' => 2, 'max' => 3000,
                    'required' => false, 'placeholder' => 'Su respuesta', 'help' => ''];
                $last = count($fields) - 1;
                $fields[$last]['label'] = "{$code}-{$n}. ".$fields[$last]['label'];
            }
        }

        return $fields;
    }

    /** code => [tab, [field, ...]] -- the code says who answers it (user's request, 2026-11-11). */
    private function tabs(): array
    {
        $q = fn (string $label, string $help = '') => ['label' => $label, 'help' => $help];

        return [
            // The owner decides what is sensitive (2026-11-11): whether the agency supervisors see money on their board.
            'DUE' => ['Dueño', [
                ['label' => '¿Los supervisores de agencia deben ver en su tablero los montos de su agencia (pasajes y encomiendas en soles)?', 'type' => 'radio',
                    'options' => ['Sí, los montos de su agencia', 'No, solo pasajeros, ocupación y encomiendas (sin montos)', 'Lo vemos en la reunión'],
                    'help' => 'Es información sensible: usted decide. Ningún supervisor ve la rentabilidad ni los costos.'],
                $q('¿Qué cifras quiere ver usted cada día, y dónde: en el celular, por correo o en la computadora?'),
                $q('¿Con qué correo quiere entrar a Murano?', 'Le crearemos su propio usuario de dueño.'),
            ]],
            'GER' => ['Gerencia y administración', [
                $q('¿Las 4 agencias (Lima, Chiclayo, Chepén, Trujillo) empezarán a vender en Murano desde el primer día?'),
                $q('¿Emiten boleta o factura electrónica (SUNAT)? ¿Con qué sistema o proveedor?'),
                $q('¿Cuánto más cuesta el pasaje VIP? ¿El precio cambia por temporada o feriados?', 'Hoy suponemos el VIP 20 % más caro.'),
                $q('¿Cómo se paga a pilotos y azafatas (sueldo, por viaje o ambos)?'),
                $q('¿Quién será el supervisor de cada agencia?'),
                $q('¿A qué correos enviamos el PDF del cierre diario de salidas?'),
            ]],
            'SUP' => ['Supervisor de agencia', [
                $q('¿Cuántas cajas y vendedores tiene cada agencia?'),
                $q('¿Qué medios de pago aceptan (efectivo, tarjeta, Yape, Plin, transferencia)?'),
                $q('¿Quién llena el reporte de salidas y a qué hora se cierra el día?'),
                $q('¿Cómo cobran una encomienda (por peso, tamaño o destino)? ¿Aceptan pago contra entrega?'),
                $q('¿Los terminales tienen personal que vende pasajes, o solo embarcan pasajeros y reciben encomiendas?'),
                $q('¿Qué agencias se quedan sin internet, cada cuánto y por cuántas horas?'),
            ]],
            'FLO' => ['Flota y operaciones', [
                $q('Tenemos 17 de sus 19 vehículos: ¿nombre, placa y número de asientos de los 2 que faltan?'),
                $q('¿Qué buses tienen asientos VIP y cuántos asientos VIP tiene cada uno?', 'Hoy suponemos los buses 103, 104, 370 y 380, con 15 cada uno.'),
                $q('¿Los buses tienen GPS? ¿De qué proveedor?'),
                $q('¿Cuánto cuestan el peaje y el combustible de Lima a Chiclayo? ¿Qué significa «CV» en su reporte?'),
                $q('¿El mantenimiento se hace en taller propio o externo? ¿Cada cuántos kilómetros?'),
            ]],
        ];
    }};
