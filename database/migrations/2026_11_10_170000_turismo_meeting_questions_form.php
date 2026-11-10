<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo's questions for the 2026-11-15 meeting as a Murano form (user's request,
 * 2026-11-10: the client fills it from a link, the answers stay in Murano -> Forms). One
 * heading per module, one answer box per question; "(Supuesto)" marks a figure KcolTech
 * set and the client must confirm. Answers are emailed to juanlock@kcoltech.com.
 * Created only if missing (by its public key): safe to run twice, never overwrites edits.
 */
return new class extends Migration
{
    private const KEY = 'tcpreguntas2026';

    public function up(): void
    {
        if (! Schema::hasTable('forms') || DB::table('forms')->where('public_key', self::KEY)->exists()) {
            return;
        }
        $cid = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->orderBy('id')->value('id');
        if (! $cid) {
            return;
        }

        $fields = [];
        foreach ($this->sections() as $id => [$title, $context, $questions]) {
            $fields[] = ['key' => "h_{$id}", 'type' => 'heading', 'text' => $title, 'level' => 3, 'width' => 'full'];
            $fields[] = ['key' => "p_{$id}", 'type' => 'paragraph', 'text' => $context, 'width' => 'full'];
            foreach ($questions as $i => $q) {
                [$label, $help, $assumed] = $q + [1 => '', 2 => false];
                $fields[] = [
                    'key' => "{$id}_".($i + 1), 'type' => 'textarea', 'width' => 'full', 'rows' => 2, 'max' => 5000,
                    'label' => $label.($assumed ? ' (Supuesto)' : ''), 'required' => false, 'placeholder' => 'Su respuesta',
                    'help' => trim($help.($assumed ? ' Es una cifra que pusimos nosotros: confírmela o corríjala.' : '')),
                ];
            }
        }
        $fields[] = ['key' => 'h_cierre', 'type' => 'heading', 'text' => 'Quién responde', 'level' => 3, 'width' => 'full'];
        $fields[] = ['key' => 'responde_nombre', 'type' => 'text', 'width' => 'half', 'label' => 'Nombre', 'required' => true, 'max' => 190, 'placeholder' => '', 'help' => ''];
        $fields[] = ['key' => 'responde_cargo', 'type' => 'text', 'width' => 'half', 'label' => 'Cargo', 'required' => false, 'max' => 190, 'placeholder' => '', 'help' => ''];

        $settings = \App\Support\FormSchema::settings([
            'title' => 'Preguntas para Turismo Cautivo',
            'description' => 'Para dejar Murano funcionando con su operación real necesitamos confirmar algunos datos, módulo por módulo. '
                .'Responda las que pueda: puede dejar en blanco las que prefiera ver en la reunión del 15 de noviembre. '
                .'Las marcadas como (Supuesto) son cifras que pusimos nosotros para avanzar.',
            'language' => 'es',
            'submit_text' => 'Enviar respuestas',
            'success_message' => '¡Gracias! Recibimos sus respuestas. Las revisaremos antes de la reunión.',
            'notify' => true,
            'notify_emails' => ['juanlock@kcoltech.com'],
            'notify_subject' => 'Respuestas de Turismo Cautivo: {form}',
            'number_prefix' => 'PRG-',
            'min_seconds' => 10,
            'width' => 860,
            // Turismo's white logo on the purple of its own contact form.
            'header_background' => '#8664a3',
            'primary_color' => '#8664a3',
            'align' => 'center',
        ]);

        DB::table('forms')->insert([
            'company_id' => $cid,
            'name' => 'Preguntas para la reunión del 15-11-2026',
            'public_key' => self::KEY,
            'status' => true,
            'fields' => json_encode(\App\Support\FormSchema::fields($fields), JSON_UNESCAPED_UNICODE),
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'next_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('forms')->where('public_key', self::KEY)->value('id');
        if ($id && ! DB::table('form_submissions')->where('form_id', $id)->exists()) {
            DB::table('forms')->where('id', $id)->delete();
        }
    }

    /** id => [title, context, [[question, help, assumed?], ...]] */
    private function sections(): array
    {
        return [
            'pasajes' => ['Venta de pasajes', 'Cada agencia vende en el POS de Murano con su propio usuario de caja. Hoy existen asientos VIP, venta por tramos y pase de abordar con QR.', [
                ['¿Las 4 agencias (Lima, Chiclayo, Chepén, Trujillo) venderán en Murano desde el primer día? ¿Cuántas cajas y vendedores tiene cada una?'],
                ['Hoy, ¿cómo vende una agencia un asiento de un bus que sale de otra ciudad?'],
                ['¿Qué 4 buses tienen asientos VIP y cuántos asientos VIP tiene cada uno?', 'Hoy los 17 buses figuran con 15. Suponemos los buses 103, 104, 370 y 380.', true],
                ['¿El pasaje VIP cuesta 20 % más que el normal?', '', true],
                ['¿Venden tramos cortos dentro de un viaje largo (por ejemplo Lima → Trujillo en el bus que va a Chepén)? ¿Cómo se fija el precio del tramo?'],
                ['¿El precio cambia por día, temporada o feriado? ¿Quién lo decide y con cuánta anticipación?'],
                ['¿Emiten boleta o factura electrónica de SUNAT en cada agencia? ¿Con qué proveedor o sistema y con qué series?'],
                ['¿Qué medios de pago aceptan en ventanilla (efectivo, tarjeta, Yape, Plin, transferencia)?'],
                ['¿Qué datos del pasajero son obligatorios (DNI, nombre, teléfono, edad)? ¿Cómo viajan los menores de edad?'],
                ['¿Cuál es la política de cambios de fecha y devoluciones? ¿Hay penalidad?'],
                ['¿Quieren premiar a los clientes frecuentes (por ejemplo, un viaje gratis cada N viajes)? ¿Cada cuántos?'],
                ['¿Quieren vender pasajes por la página web más adelante?'],
            ]],
            'abordaje' => ['Abordaje y equipaje', 'El pase de abordar tiene un QR que se lee con la cámara del celular en la puerta del bus.', [
                ['¿Quién controla el abordaje: la azafata, el personal de la agencia o el terminal?'],
                ['¿Ese personal tiene un celular con cámara para leer el QR?'],
                ['¿Registran el equipaje en bodega con un ticket? ¿Hay límite de kilos y cobro por exceso?'],
                ['¿Entregan una lista de pasajeros (manifiesto) a alguna autoridad? ¿En qué formato?'],
            ]],
            'salidas' => ['Salidas diarias y cierre del día', 'La pantalla de salidas diarias reemplaza su Excel por agencia. Al cerrar el día se guarda un PDF y se envía por correo.', [
                ['¿Quién llena el reporte en cada agencia y a qué hora se cierra el día?'],
                ['¿Quién recibe el PDF del cierre diario (correos)?'],
                ['¿Cuándo se conocen el peaje y el combustible de cada salida: al salir, al llegar o al día siguiente?'],
                ['¿A dónde va cada bus de su reporte? Suponemos Lima → Chiclayo y regreso.', '', true],
                ['¿Qué significa «CV» en su reporte?', 'Lo guardamos como Cavassa.', true],
                ['¿Cuánto cuestan el peaje y el combustible del tramo a Chiclayo?'],
                ['¿Los horarios son fijos por día de la semana o cambian cada día?'],
            ]],
            'encomiendas' => ['Encomiendas', 'Una encomienda se registra en la agencia con sus medidas, el sistema elige el primer bus con espacio y el cliente recibe un enlace de rastreo.', [
                ['¿Cómo cobran una encomienda: por peso, por tamaño, por tipo o por destino? ¿Tienen una tarifa?'],
                ['¿Se puede pagar en destino (contra entrega)?'],
                ['¿Cómo se entrega: con DNI, con firma, con foto? ¿Puede recogerla otra persona?'],
                ['¿Es correcto decirle al cliente que llega en 1 a 3 días?', '', true],
                ['¿Quieren avisar al destinatario por SMS o WhatsApp cuando llega?'],
                ['¿Qué pasa con una encomienda que nadie recoge: cuántos días se guarda y se cobra almacenaje?'],
            ]],
            'terminales' => ['Agencias y terminales', 'Hoy cada agencia tiene su almacén, su caja y sus usuarios. Los 39 terminales no tienen almacén, personal ni encomiendas.', [
                ['¿Cada terminal tiene personal propio con usuario, o se maneja desde la agencia de su ciudad?'],
                ['¿Un terminal vende pasajes y cobra, o solo embarca pasajeros y recibe encomiendas?'],
                ['¿Una encomienda se puede dejar en un terminal y el bus la recoge allí (terminal → bus → terminal)?'],
                ['¿Los 39 funcionan igual? ¿Móvil PNorte, Móvil Chiclayo y Oficina Galves son solo puntos de venta o paradas?'],
                ['¿«Lambayeqye 2» está mal escrito? ¿Ilo pertenece a Lima? ¿Chimbote y Virú pertenecen a Trujillo?'],
                ['¿Nos pueden dar la ubicación exacta de Ilo, Oficina Galves, Puente Nuevo, José Gálvez y La Pampa?', 'Sin ella el GPS no detecta las llegadas a esos puntos.'],
            ]],
            'offline' => ['Agencias sin internet', 'Propuesta: un equipo de Murano dentro de cada agencia que funciona aunque no haya internet; lo que dependa de otras agencias se resuelve por teléfono con una central, y todo se sincroniza al volver la línea.', [
                ['¿Qué agencias pierden internet, cada cuánto y por cuántas horas?'],
                ['¿Por qué las tablets y celulares no salen a internet: por política o por falta de cobertura? ¿Aceptarían permitir solo la dirección de Murano?'],
                ['¿Hay una oficina central con personal que pueda atender llamadas de las agencias? ¿En qué horario?'],
                ['¿Aceptan que, sin internet, la venta de asientos de buses de otra ciudad se haga por teléfono con la central?'],
                ['¿Hay en cada agencia una PC encendida todo el día, o ponemos nosotros el equipo (mini PC con UPS)?'],
                ['¿Les sirve, mientras tanto, una línea 4G de respaldo en el router de cada agencia?'],
                ['¿Los terminales están en la red de una agencia o son locales aparte con su propio internet?'],
            ]],
            'hosting' => ['Murano en su propio hosting', 'Sería una instalación de Murano solo con los módulos de transporte, sincronizada con el servidor de KcolTech.', [
                ['¿Por qué prefieren su propio hosting: ser dueños de los datos, el costo, la velocidad o funcionar sin internet?'],
                ['¿«Su hosting» es un servidor en internet o un equipo dentro de su oficina?'],
                ['¿Quién lo administraría: ustedes, un proveedor o KcolTech? ¿Quién hace las copias de seguridad?'],
                ['¿Qué tiene el hosting (PHP 8.3, MySQL o MariaDB, SSL, cron, acceso SSH)?'],
            ]],
            'flota' => ['Flota, mantenimiento y monitoreo', 'Murano lleva los buses, su mantenimiento, los repuestos y el monitoreo de viajes desde el celular del piloto y la azafata.', [
                ['La flota es de 19 vehículos y tenemos 17: ¿nombre, placa y número de asientos de los 2 que faltan?'],
                ['¿Los buses ya tienen GPS? ¿De qué proveedor? ¿Nos da acceso a sus datos?'],
                ['¿A partir de cuántos minutos consideran que un bus llegó tarde? Usamos 10 minutos.', '', true],
                ['¿Quieren una foto en cada llegada o solo en el destino final? Hoy se pide en cada llegada.', '', true],
                ['¿Guardamos el recorrido GPS 30 días?', '', true],
                ['¿Pilotos y azafatas tienen celular de la empresa con datos móviles?'],
                ['¿El mantenimiento se hace en taller propio o externo? ¿Cada cuántos kilómetros cada servicio?'],
                ['¿Cómo se paga el combustible (vales, tarjeta, efectivo) y quién lo registra?'],
                ['Para calcular la depreciación: ¿precio de compra, año y vida útil de cada bus?'],
            ]],
            'personal' => ['Personal, planilla y accesos', 'Cada empleado tiene su usuario. Hay roles para caja de agencia, supervisor por agencia, gerente y administrador.', [
                ['¿Cómo se paga a pilotos y azafatas: sueldo mensual, por viaje o ambos? ¿Hay viáticos?'],
                ['¿Quién será el supervisor de cada agencia?'],
                ['¿Quién de la empresa administrará usuarios y permisos?'],
                ['¿Necesitan turnos o control de asistencia del personal de agencia?'],
            ]],
            'dueno' => ['Rentabilidad y tablero del dueño', 'El tablero calcula la ganancia de cada viaje: pasajes y encomiendas, menos tripulación, planilla, combustible, mantenimiento, depreciación, peajes, agencia y refrigerios VIP.', [
                ['¿El costo diario de cada agencia es S/ 50?', '', true],
                ['¿El refrigerio de cada pasajero VIP cuesta S/ 10? ¿Los pasajeros normales no reciben refrigerio?', '', true],
                ['¿Qué cifras quiere ver el dueño cada día, y dónde: en el celular, por correo o en la computadora?'],
                ['¿Qué consideran un viaje que no conviene (por ejemplo, ocupación menor a cierto porcentaje)?'],
            ]],
            'reclamos' => ['Libro de reclamaciones y formularios', 'Los reclamos y los contactos de la web llegan a Murano.', [
                ['¿Quién responde los reclamos y en cuántos días?'],
                ['¿A qué correos debe llegar el aviso de cada reclamo nuevo?'],
                ['¿Quieren que el cliente reciba una copia de su reclamo y de la respuesta por correo?'],
            ]],
        ];
    }
};
