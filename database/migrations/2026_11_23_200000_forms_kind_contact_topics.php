<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forms by purpose (user, 2026-11-23: "contactos es un standard de cada página web... debe ser tratada como un primer
 * contacto, reportes de fecha y tipo de preguntas... el formulario de reclamos es donde deben incidir las estadísticas;
 * ahora como está mezclado no me permite entender o sacar un análisis"):
 *  - settings.kind for every form, by its name: contact / complaints / other (the forms dashboard keeps them apart);
 *  - Turismo Cautivo's contact form gets a "Motivo" choice (the topic), its 26 earlier messages are classified by their
 *    words (data.motivo + data._motivo_auto), and it carries a drafted automatic reply per topic -- every one OFF: the
 *    form's admin reviews, completes (links, phones) and turns each one on (user: "ofrecer al administrador del
 *    formulario auto respuesta según el tema antes de implementarlo").
 * By names, not ids. Safe to run twice.
 */
return new class extends Migration
{
    private const TOPICS = ['Comprar pasaje', 'Precios y horarios', 'Copia de boleto', 'Reprogramar viaje', 'Confirmar mi pasaje',
        'Encomiendas', 'Objeto olvidado', 'Otro'];

    public function up(): void
    {
        foreach (DB::table('forms')->whereNull('deleted_at')->get(['id', 'name', 'settings']) as $f) {
            $s = json_decode((string) $f->settings, true) ?: [];
            if (! empty($s['kind']) && $s['kind'] !== 'other') {
                continue;
            }
            $n = mb_strtolower($f->name);
            $kind = preg_match('/reclam|complaint|queja/u', $n) ? 'complaints' : (preg_match('/cont[aá]ct/u', $n) ? 'contact' : 'other');
            $s['kind'] = $kind;
            DB::table('forms')->where('id', $f->id)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }

        $tc = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        $form = $tc ? DB::table('forms')->where('company_id', $tc)->whereNull('deleted_at')->where('name', 'like', 'Contacto%')->first() : null;
        if (! $form) {
            return;
        }
        $fields = json_decode((string) $form->fields, true) ?: [];
        if (! collect($fields)->contains('key', 'motivo')) {
            $at = collect($fields)->search(fn ($x) => ($x['key'] ?? '') === 'message');
            $motivo = ['key' => 'motivo', 'type' => 'select', 'label' => 'Motivo', 'width' => 'full', 'required' => true,
                'placeholder' => 'Seleccione el motivo de su consulta', 'help' => '', 'default' => '', 'options' => self::TOPICS, 'multiple' => false];
            array_splice($fields, $at === false ? count($fields) : $at, 0, [$motivo]);
        }
        $s = json_decode((string) $form->settings, true) ?: [];
        $s['kind'] = 'contact';
        $s['topic_field'] = 'motivo';
        $s['topic_replies'] = ($s['topic_replies'] ?? []) + self::drafts();
        DB::table('forms')->where('id', $form->id)->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);

        // The earlier messages, classified by their words (marked as automatic).
        foreach (DB::table('form_submissions')->where('form_id', $form->id)->get(['id', 'data']) as $sub) {
            $d = json_decode((string) $sub->data, true) ?: [];
            if (! empty($d['motivo'])) {
                continue;
            }
            $d['motivo'] = self::classify((string) ($d['message'] ?? ''));
            $d['_motivo_auto'] = true;
            DB::table('form_submissions')->where('id', $sub->id)->update(['data' => json_encode($d, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private static function classify(string $m): string
    {
        $t = mb_strtolower($m);
        $rules = [
            'Copia de boleto' => '/copia|boleto [a-z]?\d|duplicado/u',
            'Reprogramar viaje' => '/reprogram|cambiar (la )?fecha|postergar/u',
            'Confirmar mi pasaje' => '/confirmar|mis pas+ajes|detalles del boleto/u',
            'Objeto olvidado' => '/olvid|perd[ií]|dej[eé] (mi|una|un)/u',
            'Encomiendas' => '/encomienda|env[ií]o de (paquete|carga)/u',
            'Precios y horarios' => '/precio|tarifa|cu[aá]nto cuesta|horario|qu[eé] hora|para que hora/u',
            'Comprar pasaje' => '/pasaje|comprar|viajar|boleto/u',
        ];
        foreach ($rules as $topic => $re) {
            if (preg_match($re, $t)) {
                return $topic;
            }
        }

        return 'Otro';
    }

    /** Drafted from what the visitors asked until 2026-11-23; OFF until the form's admin approves each one. */
    private static function drafts(): array
    {
        $d = fn ($subject, $message) => ['subject' => $subject, 'message' => $message, 'active' => false];

        return [
            'Comprar pasaje' => $d('Cómo comprar su pasaje - Turismo Cautivo', "Hola {name}, gracias por escribirnos.\n\nPuede comprar su pasaje:\n- En línea: [link de compra en línea]\n- En nuestras agencias de Lima, Trujillo, Chepén y Chiclayo.\n- Por teléfono / WhatsApp: [número]\n\nPara pagar con Yape o Plin: [indicaciones].\nSi nos indica origen, destino, fecha y número de pasajeros le ayudamos a reservar.\n\nSu número de consulta es {number}."),
            'Precios y horarios' => $d('Precios y horarios - Turismo Cautivo', "Hola {name}, gracias por escribirnos.\n\nNuestras salidas entre Lima, Trujillo, Chepén y Chiclayo son diarias. Horarios y precios: [link o tabla de horarios y precios].\nLos precios pueden variar según la fecha y el tipo de asiento.\n\nSu número de consulta es {number}."),
            'Copia de boleto' => $d('Copia de su boleto - Turismo Cautivo', "Hola {name}, recibimos su pedido de copia de boleto.\n\nPara enviársela necesitamos: número de boleto, fecha de viaje, ruta y DNI del pasajero. Le responderemos a este correo en [plazo].\n\nSu número de consulta es {number}."),
            'Reprogramar viaje' => $d('Reprogramación de su viaje - Turismo Cautivo', "Hola {name}, recibimos su pedido para reprogramar su viaje.\n\nPuede reprogramar hasta [horas] antes de la salida en cualquiera de nuestras agencias o por [teléfono / WhatsApp]. Tenga a mano su número de boleto y DNI. [Condiciones o costo de reprogramación].\n\nSu número de consulta es {number}."),
            'Confirmar mi pasaje' => $d('Confirmación de su pasaje - Turismo Cautivo', "Hola {name}, recibimos su consulta.\n\nPara confirmar su pasaje indíquenos el número de boleto, la fecha y la ruta; también puede verificarlo en [link]. Le responderemos a la brevedad.\n\nSu número de consulta es {number}."),
            'Encomiendas' => $d('Encomiendas - Turismo Cautivo', "Hola {name}, gracias por escribirnos.\n\nRecibimos encomiendas en nuestras agencias de Lima, Trujillo, Chepén y Chiclayo. El costo depende del tamaño y del destino: [tarifas o contacto].\nCon su número de guía puede seguir su envío en línea.\n\nSu número de consulta es {number}."),
            'Objeto olvidado' => $d('Objeto olvidado en el bus - Turismo Cautivo', "Hola {name}, lamentamos lo ocurrido.\n\nRegistramos su pedido y lo derivamos a Operaciones. Para buscarlo necesitamos la fecha y hora del viaje, la ruta, el número de asiento y una descripción del objeto. Le avisaremos a este correo si lo encontramos.\n\nSu número de consulta es {number}."),
            'Otro' => $d('Recibimos su mensaje - Turismo Cautivo', "Hola {name}, gracias por escribirnos.\n\nRecibimos su mensaje y le responderemos a la brevedad.\n\nSu número de consulta es {number}."),
        ];
    }

    public function down(): void
    {
        // Left as is: the topics and the classification are data the company keeps.
    }
};
